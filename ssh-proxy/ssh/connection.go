package ssh

import (
	"bytes"
	"context"
	"errors"
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
	hostKey string
}

// Verify Connection implements Session at compile time.
var _ Session = (*Connection)(nil)

type ConnectParams struct {
	Hostname   string
	Port       int
	Username   string
	Password   string
	PrivateKey string // PEM; takes precedence over Password
	Passphrase string
	// HostKey is the pinned key (authorized_keys format); empty means trust on first use.
	HostKey string
}

var ErrHostKeyMismatch = errors.New("host key mismatch")

var ErrInvalidPrivateKey = errors.New("invalid private key")

func Fingerprint(authorizedKey string) (string, error) {
	pub, _, _, _, err := gossh.ParseAuthorizedKey([]byte(authorizedKey))
	if err != nil {
		return "", err
	}
	return gossh.FingerprintSHA256(pub), nil
}

func HostKeysEqual(a, b string) bool {
	ka, _, _, _, errA := gossh.ParseAuthorizedKey([]byte(a))
	kb, _, _, _, errB := gossh.ParseAuthorizedKey([]byte(b))
	return errA == nil && errB == nil && bytes.Equal(ka.Marshal(), kb.Marshal())
}

func authMethod(p ConnectParams) (gossh.AuthMethod, error) {
	if p.PrivateKey == "" {
		return gossh.Password(p.Password), nil
	}
	var raw any
	var err error
	if p.Passphrase != "" {
		raw, err = gossh.ParseRawPrivateKeyWithPassphrase([]byte(p.PrivateKey), []byte(p.Passphrase))
	} else {
		raw, err = gossh.ParseRawPrivateKey([]byte(p.PrivateKey))
	}
	if err != nil {
		var missing *gossh.PassphraseMissingError
		if errors.As(err, &missing) {
			return nil, fmt.Errorf("%w: key is encrypted and no passphrase was given", ErrInvalidPrivateKey)
		}
		return nil, fmt.Errorf("%w: %v", ErrInvalidPrivateKey, err)
	}
	signer, err := gossh.NewSignerFromKey(raw)
	if err != nil {
		return nil, fmt.Errorf("%w: %v", ErrInvalidPrivateKey, err)
	}
	return gossh.PublicKeys(signer), nil
}

// Connect establishes an SSH connection with PTY to the given host.
// The provided context controls the overall connect+handshake+auth timeout.
func Connect(ctx context.Context, p ConnectParams) (*Connection, error) {
	auth, err := authMethod(p)
	if err != nil {
		return nil, err
	}

	var observed string
	var mismatch error
	sshConfig := &gossh.ClientConfig{
		User: p.Username,
		Auth: []gossh.AuthMethod{auth},
		HostKeyCallback: func(_ string, _ net.Addr, key gossh.PublicKey) error {
			observed = string(bytes.TrimSpace(gossh.MarshalAuthorizedKey(key)))
			if p.HostKey == "" {
				return nil
			}
			pinned, _, _, _, perr := gossh.ParseAuthorizedKey([]byte(p.HostKey))
			if perr != nil {
				mismatch = fmt.Errorf("%w: pinned key is unparseable: %v", ErrHostKeyMismatch, perr)
				return mismatch
			}
			if !bytes.Equal(pinned.Marshal(), key.Marshal()) {
				mismatch = fmt.Errorf("%w: expected %s but server presented %s",
					ErrHostKeyMismatch, gossh.FingerprintSHA256(pinned), gossh.FingerprintSHA256(key))
				return mismatch
			}
			return nil
		},
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

	addr := fmt.Sprintf("%s:%d", p.Hostname, p.Port)

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
		if mismatch != nil {
			return nil, mismatch
		}
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
		hostKey: observed,
	}

	go conn.readLoop(stdout)

	return conn, nil
}

// HostKey returns the server host key observed at connect time (authorized_keys format).
func (c *Connection) HostKey() string { return c.hostKey }

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

// SendKeepalive sends an SSH keepalive request to verify the connection is alive.
// This uses the "keepalive@openssh.com" global request which is widely supported.
// Returns nil if the remote end responds, or an error if the connection is dead.
func (c *Connection) SendKeepalive() error {
	if c.closed {
		return fmt.Errorf("connection closed")
	}
	_, _, err := c.client.SendRequest("keepalive@openssh.com", true, nil)
	return err
}

// CheckHealth performs a lightweight health probe by sending an SSH keepalive
// request. This satisfies pool.HealthChecker, allowing the pool to verify
// a connection is still alive before reusing it.
func (c *Connection) CheckHealth() error {
	return c.SendKeepalive()
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
