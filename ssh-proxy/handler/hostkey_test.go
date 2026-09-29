package handler

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"sshproxy/pool"
	"sshproxy/ssh"
)

const keyA = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEPlaceholderKeyAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

type keyedSession struct {
	mockSession
	hostKey string
}

func (k *keyedSession) HostKey() string { return k.hostKey }

func okExecutor() *mockExecutor {
	return &mockExecutor{result: &ssh.CommandResult{Success: true, Output: []ssh.CommandOutput{}}}
}

func post(h *Handler, body string) (*httptest.ResponseRecorder, executeResponse) {
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()
	h.Execute(w, req)
	var resp executeResponse
	_ = json.NewDecoder(w.Body).Decode(&resp)
	return w, resp
}

func TestExecute_DifferentCredentialsOrPortNeverReuseSession(t *testing.T) {
	p := pool.New(10 * time.Minute, 0)
	calls := 0
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		calls++
		return &keyedSession{}, nil
	}
	h := testHandler(p, connector, okExecutor())

	bodies := []string{
		`{"hostname":"sw","username":"admin","password":"one","commands":[]}`,
		`{"hostname":"sw","username":"admin","password":"one","commands":[]}`,
		`{"hostname":"sw","username":"admin","password":"two","commands":[]}`,
		`{"hostname":"sw","username":"admin","commands":[]}`,
		`{"hostname":"sw","username":"admin","password":"one","port":2222,"commands":[]}`,
		`{"hostname":"sw","username":"root","password":"one","commands":[]}`,
		`{"hostname":"sw","username":"admin","private_key":"k","commands":[]}`,
		`{"hostname":"sw","username":"admin","private_key":"k","passphrase":"p","commands":[]}`,
	}
	want := []int{1, 1, 2, 3, 4, 5, 6, 7}
	for i, b := range bodies {
		if w, _ := post(h, b); w.Code != http.StatusOK {
			t.Fatalf("request %d: status %d", i, w.Code)
		}
		if calls != want[i] {
			t.Fatalf("request %d: connector calls = %d, want %d", i, calls, want[i])
		}
	}
}

func TestExecute_ReturnsHostKeyForNewAndReusedConnections(t *testing.T) {
	p := pool.New(10 * time.Minute, 0)
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return &keyedSession{hostKey: keyA}, nil
	}
	h := testHandler(p, connector, okExecutor())
	body := fmt.Sprintf(`{"hostname":"sw","username":"admin","password":"p","host_key":%q,"commands":[]}`, keyA)

	for i := 0; i < 2; i++ {
		_, resp := post(h, body)
		if resp.HostKey != keyA {
			t.Errorf("request %d: host_key = %q, want %q", i, resp.HostKey, keyA)
		}
	}
}

func TestExecute_PassesPinAndCredentialsToConnector(t *testing.T) {
	var got ssh.ConnectParams
	connector := func(_ context.Context, cp ssh.ConnectParams) (ssh.Session, error) {
		got = cp
		return &keyedSession{hostKey: keyA}, nil
	}
	h := testHandler(pool.New(time.Minute, 0), connector, okExecutor())
	post(h, `{"hostname":"sw","username":"u","private_key":"PEM","passphrase":"pp","host_key":"ssh-ed25519 X","commands":[]}`)
	if got.PrivateKey != "PEM" || got.Passphrase != "pp" || got.HostKey != "ssh-ed25519 X" || got.Username != "u" || got.Port != 22 {
		t.Errorf("unexpected params: %+v", got)
	}
}

func TestExecute_HostKeyMismatch(t *testing.T) {
	p := pool.New(10 * time.Minute, 0)
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return nil, fmt.Errorf("%w: expected SHA256:aaa but server presented SHA256:bbb", ssh.ErrHostKeyMismatch)
	}
	h := testHandler(p, connector, okExecutor())
	w, resp := post(h, `{"hostname":"sw","username":"admin","password":"p","host_key":"ssh-ed25519 X","commands":[]}`)

	if w.Code != http.StatusInternalServerError {
		t.Errorf("status = %d, want 500", w.Code)
	}
	if resp.ErrorCode != "host_key_mismatch" || resp.Success || resp.HostKey != "" {
		t.Errorf("unexpected response: %+v", resp)
	}
	if !strings.Contains(resp.Error, "SHA256:aaa") || !strings.Contains(resp.Error, "SHA256:bbb") {
		t.Errorf("error should name both fingerprints: %q", resp.Error)
	}
	if _, conns := p.Status(); len(conns) != 0 {
		t.Errorf("mismatch left %d pooled entries", len(conns))
	}
}

func TestExecute_InvalidPrivateKey(t *testing.T) {
	p := pool.New(10 * time.Minute, 0)
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return nil, fmt.Errorf("%w: bad", ssh.ErrInvalidPrivateKey)
	}
	h := testHandler(p, connector, okExecutor())
	w, resp := post(h, `{"hostname":"sw","username":"admin","private_key":"junk","commands":[]}`)

	if w.Code != http.StatusBadRequest || resp.ErrorCode != "invalid_private_key" {
		t.Errorf("status=%d code=%q", w.Code, resp.ErrorCode)
	}
	if _, conns := p.Status(); len(conns) != 0 {
		t.Errorf("left %d pooled entries", len(conns))
	}
}

func TestExecute_ChangedPinReconnectsPooledSession(t *testing.T) {
	p := pool.New(10 * time.Minute, 0)
	old := &keyedSession{hostKey: keyA}
	k := pool.NewKey("sw", 22, "admin", pool.DefaultChannel, "p", "", "")
	_, _, _ = p.Acquire(k)
	p.SetConnection(k, old)
	p.SetHostKey(k, keyA)
	p.Release(k)

	calls := 0
	connector := func(_ context.Context, cp ssh.ConnectParams) (ssh.Session, error) {
		calls++
		return nil, fmt.Errorf("%w: new pin checked", ssh.ErrHostKeyMismatch)
	}
	h := testHandler(p, connector, okExecutor())
	unparseableDifferingPin := "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOtherKeyBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB"
	_, resp := post(h, fmt.Sprintf(`{"hostname":"sw","username":"admin","password":"p","host_key":%q,"commands":[]}`, unparseableDifferingPin))
	if calls != 1 || resp.ErrorCode != "host_key_mismatch" {
		t.Errorf("calls=%d code=%q", calls, resp.ErrorCode)
	}
	if !old.closed {
		t.Error("stale pooled session should have been closed")
	}
}

func TestExecute_NeverLogsSecrets(t *testing.T) {
	var buf bytes.Buffer
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return nil, fmt.Errorf("boom")
	}
	h := testHandlerWithLogger(pool.New(time.Minute, 0), connector, okExecutor(), testLoggerWithBuffer(&buf))
	post(h, `{"hostname":"sw","username":"u","password":"PW-SECRET","private_key":"KEY-SECRET","passphrase":"PP-SECRET","commands":[]}`)
	for _, s := range []string{"PW-SECRET", "KEY-SECRET", "PP-SECRET"} {
		if strings.Contains(buf.String(), s) {
			t.Errorf("log leaked %s", s)
		}
	}
}

func TestExecute_EmptyPinEvictsPooledSessionWithObservedKey(t *testing.T) {
	p := pool.New(10 * time.Minute, 0)
	old := &keyedSession{hostKey: keyA}
	k := pool.NewKey("sw", 22, "admin", pool.DefaultChannel, "p", "", "")
	_, _, _ = p.Acquire(k)
	p.SetConnection(k, old)
	p.SetHostKey(k, keyA)
	p.Release(k)

	const keyB = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOtherKeyBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB"
	calls := 0
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		calls++
		return &keyedSession{hostKey: keyB}, nil
	}
	h := testHandler(p, connector, okExecutor())
	_, resp := post(h, `{"hostname":"sw","username":"admin","password":"p","commands":[]}`)

	if calls != 1 || resp.HostKey != keyB || !old.closed {
		t.Errorf("calls=%d host_key=%q oldClosed=%v", calls, resp.HostKey, old.closed)
	}
}
