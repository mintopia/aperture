package ssh

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net"
	"time"

	gossh "golang.org/x/crypto/ssh"
)

// Connection wraps an SSH client with a PTY session for interactive use.
// It satisfies the Session interface.
type Connection struct {
	client  *gossh.Client
	session *gossh.Session
	stdin   io.WriteCloser
	dataCh  chan []byte
	closeCh chan struct{}
	closed  bool
}

// Verify Connection implements Session at compile time.
var _ Session = (*Connection)(nil)

// Connect establishes an SSH connection with PTY to the given host.
// The provided context controls the overall connect+handshake+auth timeout.
func Connect(ctx context.Context, hostname string, port int, username, password string) (*Connection, error) {
	sshConfig := &gossh.ClientConfig{
		User: username,
		Auth: []gossh.AuthMethod{
			gossh.Password(password),
		},
		HostKeyCallback: gossh.InsecureIgnoreHostKey(), //nolint:gosec // network switches don't have known host keys
	}

	// Include older algorithms for Cisco IOS compatibility (SSH-1.99-Cisco-1.25).
	sshConfig.KeyExchanges = []string{
		"curve25519-sha256",
		"curve25519-sha256@libssh.org",
		"ecdh-sha2-nistp256",
		"ecdh-sha2-nistp384",
		"ecdh-sha2-nistp521",
		"diffie-hellman-group14-sha256",
		"diffie-hellman-group14-sha1",
		"diffie-hellman-group-exchange-sha256",
		"diffie-hellman-group-exchange-sha1",
		"diffie-hellman-group1-sha1",
	}
	sshConfig.Ciphers = []string{
		"aes128-gcm@openssh.com",
		"aes256-gcm@openssh.com",
		"chacha20-poly1305@openssh.com",
		"aes128-ctr",
		"aes192-ctr",
		"aes256-ctr",
		"aes128-cbc",
		"aes192-cbc",
		"aes256-cbc",
		"3des-cbc",
	}

	addr := fmt.Sprintf("%s:%d", hostname, port)

	// Dial TCP with context for timeout/cancellation.
	var d net.Dialer
	netConn, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, fmt.Errorf("TCP connection failed: %w", err)
	}

	// Set deadline from context for the SSH handshake + auth phase.
	if deadline, ok := ctx.Deadline(); ok {
		if err := netConn.SetDeadline(deadline); err != nil {
			netConn.Close()
			return nil, fmt.Errorf("set deadline failed: %w", err)
		}
	}

	sshConn, chans, reqs, err := gossh.NewClientConn(netConn, addr, sshConfig)
	if err != nil {
		netConn.Close()
		return nil, fmt.Errorf("SSH handshake failed: %w", err)
	}

	// Clear deadline after successful handshake.
	_ = netConn.SetDeadline(time.Time{})

	client := gossh.NewClient(sshConn, chans, reqs)

	session, err := client.NewSession()
	if err != nil {
		client.Close()
		return nil, fmt.Errorf("session creation failed: %w", err)
	}

	// Request PTY — required for interactive Cisco IOS sessions.
	modes := gossh.TerminalModes{
		gossh.ECHO:          1,
		gossh.TTY_OP_ISPEED: 14400,
		gossh.TTY_OP_OSPEED: 14400,
	}
	if err := session.RequestPty("xterm", 24, 80, modes); err != nil {
		session.Close()
		client.Close()
		return nil, fmt.Errorf("PTY request failed: %w", err)
	}

	stdin, err := session.StdinPipe()
	if err != nil {
		session.Close()
		client.Close()
		return nil, fmt.Errorf("stdin pipe failed: %w", err)
	}

	stdout, err := session.StdoutPipe()
	if err != nil {
		session.Close()
		client.Close()
		return nil, fmt.Errorf("stdout pipe failed: %w", err)
	}

	if err := session.Shell(); err != nil {
		session.Close()
		client.Close()
		return nil, fmt.Errorf("shell start failed: %w", err)
	}

	conn := &Connection{
		client:  client,
		session: session,
		stdin:   stdin,
		dataCh:  make(chan []byte, 256),
		closeCh: make(chan struct{}),
	}

	go conn.readLoop(stdout)

	return conn, nil
}

// readLoop continuously reads from stdout and pushes chunks to dataCh.
func (c *Connection) readLoop(r io.Reader) {
	defer close(c.dataCh)
	buf := make([]byte, 4096)
	for {
		n, err := r.Read(buf)
		if n > 0 {
			data := make([]byte, n)
			copy(data, buf[:n])
			select {
			case c.dataCh <- data:
			case <-c.closeCh:
				return
			}
		}
		if err != nil {
			return
		}
	}
}

// Write sends data to the SSH session's stdin.
func (c *Connection) Write(data string) error {
	if c.closed {
		return fmt.Errorf("connection closed")
	}
	_, err := c.stdin.Write([]byte(data))
	return err
}

// Read waits up to timeout for the first chunk of data, then drains
// any immediately buffered data and returns the accumulated result.
func (c *Connection) Read(timeout time.Duration) string {
	var buf bytes.Buffer
	timer := time.NewTimer(timeout)
	defer timer.Stop()

	// Wait for first chunk or timeout.
	select {
	case data, ok := <-c.dataCh:
		if !ok {
			return ""
		}
		buf.Write(data)
	case <-timer.C:
		return ""
	}

	// Drain any immediately available buffered data.
	for {
		select {
		case data, ok := <-c.dataCh:
			if !ok {
				return buf.String()
			}
			buf.Write(data)
		default:
			return buf.String()
		}
	}
}

// Close terminates the SSH session and underlying TCP connection.
func (c *Connection) Close() error {
	if c.closed {
		return nil
	}
	c.closed = true
	close(c.closeCh)
	c.session.Close()
	return c.client.Close()
}
