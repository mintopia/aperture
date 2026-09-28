package ssh

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/pem"
	"errors"
	"net"
	"strings"
	"testing"
	"time"

	gossh "golang.org/x/crypto/ssh"
)

type testServer struct {
	port     int
	hostname string
}

func newSigner(t *testing.T) gossh.Signer {
	t.Helper()
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	s, err := gossh.NewSignerFromKey(priv)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func authorized(s gossh.Signer) string {
	return string(bytes.TrimSpace(gossh.MarshalAuthorizedKey(s.PublicKey())))
}

func startServer(t *testing.T, hostKey gossh.Signer, password string, clientPub gossh.PublicKey) *testServer {
	t.Helper()
	cfg := &gossh.ServerConfig{
		PasswordCallback: func(_ gossh.ConnMetadata, pw []byte) (*gossh.Permissions, error) {
			if password != "" && string(pw) == password {
				return nil, nil
			}
			return nil, errors.New("denied")
		},
		PublicKeyCallback: func(_ gossh.ConnMetadata, key gossh.PublicKey) (*gossh.Permissions, error) {
			if clientPub != nil && bytes.Equal(key.Marshal(), clientPub.Marshal()) {
				return nil, nil
			}
			return nil, errors.New("denied")
		},
	}
	cfg.AddHostKey(hostKey)

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	go func() {
		for {
			nc, err := ln.Accept()
			if err != nil {
				return
			}
			go serveConn(nc, cfg)
		}
	}()
	tcp := ln.Addr().(*net.TCPAddr)
	return &testServer{port: tcp.Port, hostname: "127.0.0.1"}
}

func serveConn(nc net.Conn, cfg *gossh.ServerConfig) {
	conn, chans, reqs, err := gossh.NewServerConn(nc, cfg)
	if err != nil {
		nc.Close()
		return
	}
	defer conn.Close()
	go func() {
		for r := range reqs {
			_ = r.Reply(false, nil)
		}
	}()
	for nch := range chans {
		ch, creqs, err := nch.Accept()
		if err != nil {
			return
		}
		go func() {
			for r := range creqs {
				_ = r.Reply(r.Type == "pty-req" || r.Type == "shell", nil)
			}
		}()
		_ = ch
	}
}

func (s *testServer) params(user string) ConnectParams {
	return ConnectParams{Hostname: s.hostname, Port: s.port, Username: user}
}

func connect(t *testing.T, p ConnectParams) (*Connection, error) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	return Connect(ctx, p)
}

func TestConnect_TOFUReturnsObservedHostKey(t *testing.T) {
	hk := newSigner(t)
	srv := startServer(t, hk, "pw", nil)
	p := srv.params("admin")
	p.Password = "pw"

	c, err := connect(t, p)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	defer c.Close()
	if c.HostKey() != authorized(hk) {
		t.Errorf("host key = %q, want %q", c.HostKey(), authorized(hk))
	}
}

func TestConnect_PinnedHostKeyMatches(t *testing.T) {
	hk := newSigner(t)
	srv := startServer(t, hk, "pw", nil)
	p := srv.params("admin")
	p.Password = "pw"
	p.HostKey = authorized(hk)

	c, err := connect(t, p)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	c.Close()
}

func TestConnect_PinnedHostKeyMismatch(t *testing.T) {
	presented := newSigner(t)
	pinned := newSigner(t)
	srv := startServer(t, presented, "pw", nil)
	p := srv.params("admin")
	p.Password = "pw"
	p.HostKey = authorized(pinned)

	_, err := connect(t, p)
	if !errors.Is(err, ErrHostKeyMismatch) {
		t.Fatalf("expected ErrHostKeyMismatch, got %v", err)
	}
	for _, s := range []gossh.Signer{pinned, presented} {
		if !strings.Contains(err.Error(), gossh.FingerprintSHA256(s.PublicKey())) {
			t.Errorf("error %q should name fingerprint %s", err, gossh.FingerprintSHA256(s.PublicKey()))
		}
	}
}

func TestConnect_PrivateKeyLogin(t *testing.T) {
	seed := newEd25519Seed(t)
	signer, _ := gossh.NewSignerFromKey(seed.key)
	srv := startServer(t, newSigner(t), "", signer.PublicKey())

	p := srv.params("admin")
	p.PrivateKey = seed.pem
	c, err := connect(t, p)
	if err != nil {
		t.Fatalf("connect with key: %v", err)
	}
	c.Close()

	p.PrivateKey = newEd25519Seed(t).pem
	if _, err := connect(t, p); err == nil {
		t.Fatal("unauthorised key must be rejected")
	}
}

func TestConnect_EncryptedPrivateKeyWithPassphrase(t *testing.T) {
	seed := newEd25519Seed(t)
	signer, _ := gossh.NewSignerFromKey(seed.key)
	srv := startServer(t, newSigner(t), "", signer.PublicKey())
	block, err := gossh.MarshalPrivateKeyWithPassphrase(seed.key, "", []byte("s3cret"))
	if err != nil {
		t.Fatal(err)
	}
	encrypted := string(pem.EncodeToMemory(block))

	p := srv.params("admin")
	p.PrivateKey = encrypted
	p.Passphrase = "s3cret"
	c, err := connect(t, p)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	c.Close()

	p.Passphrase = "wrong"
	if _, err := connect(t, p); !errors.Is(err, ErrInvalidPrivateKey) {
		t.Errorf("wrong passphrase: expected ErrInvalidPrivateKey, got %v", err)
	}
	p.Passphrase = ""
	if _, err := connect(t, p); !errors.Is(err, ErrInvalidPrivateKey) {
		t.Errorf("missing passphrase: expected ErrInvalidPrivateKey, got %v", err)
	}
}

func TestConnect_GarbagePrivateKey(t *testing.T) {
	srv := startServer(t, newSigner(t), "pw", nil)
	p := srv.params("admin")
	p.Password = "pw"
	p.PrivateKey = "not a key"
	_, err := connect(t, p)
	if !errors.Is(err, ErrInvalidPrivateKey) {
		t.Fatalf("expected ErrInvalidPrivateKey, got %v", err)
	}
}

func TestConnect_PasswordStillWorksAndWrongPasswordFails(t *testing.T) {
	srv := startServer(t, newSigner(t), "pw", nil)
	p := srv.params("admin")
	p.Password = "bad"
	if _, err := connect(t, p); err == nil {
		t.Fatal("wrong password must fail")
	}
}

func TestHostKeysEqualAndFingerprint(t *testing.T) {
	a, b := newSigner(t), newSigner(t)
	if !HostKeysEqual(authorized(a), authorized(a)+" comment") {
		t.Error("same key with comment should be equal")
	}
	if HostKeysEqual(authorized(a), authorized(b)) || HostKeysEqual("junk", "junk") {
		t.Error("different or unparseable keys must not be equal")
	}
	fp, err := Fingerprint(authorized(a))
	if err != nil || fp != gossh.FingerprintSHA256(a.PublicKey()) {
		t.Errorf("fingerprint = %q, %v", fp, err)
	}
	if _, err := Fingerprint("junk"); err == nil {
		t.Error("expected error for junk")
	}
}

type edSeed struct {
	key ed25519.PrivateKey
	pem string
}

func newEd25519Seed(t *testing.T) edSeed {
	t.Helper()
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	block, err := gossh.MarshalPrivateKey(priv, "")
	if err != nil {
		t.Fatal(err)
	}
	return edSeed{key: priv, pem: string(pem.EncodeToMemory(block))}
}
