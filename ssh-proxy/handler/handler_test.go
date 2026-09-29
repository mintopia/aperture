package handler

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"sshproxy/pool"
	"sshproxy/ssh"
	"strings"
	"testing"
	"time"
)

var defaultTestChannels = []string{"commands", "polling"}

type mockSession struct {
	chunks  []string
	idx     int
	written []string
	closed  bool
}

func newMockSession(chunks ...string) *mockSession {
	return &mockSession{chunks: chunks}
}

func (m *mockSession) Write(data string) error {
	m.written = append(m.written, data)
	return nil
}

func (m *mockSession) Read(_ time.Duration) string {
	if m.idx >= len(m.chunks) {
		return ""
	}
	chunk := m.chunks[m.idx]
	m.idx++
	return chunk
}

func (m *mockSession) Close() error {
	m.closed = true
	return nil
}

type mockExecutor struct {
	result *ssh.CommandResult
}

func (m *mockExecutor) Execute(_ ssh.Session, _ []ssh.Command) *ssh.CommandResult {
	return m.result
}

func mockConnectorSuccess(session ssh.Session) Connector {
	return func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return session, nil
	}
}

func mockConnectorFailure(errMsg string) Connector {
	return func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return nil, fmt.Errorf("%s", errMsg)
	}
}

func testLoggerWithBuffer(buf *bytes.Buffer) *slog.Logger {
	return slog.New(slog.NewJSONHandler(buf, &slog.HandlerOptions{Level: slog.LevelDebug}))
}

func testLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(&bytes.Buffer{}, &slog.HandlerOptions{Level: slog.LevelError}))
}

func testHandler(p *pool.Pool, connector Connector, executor CommandExecutor) *Handler {
	return New(p, connector, executor, 10*time.Second, testLogger(), defaultTestChannels)
}

func testHandlerWithLogger(p *pool.Pool, connector Connector, executor CommandExecutor, logger *slog.Logger) *Handler {
	return New(p, connector, executor, 10*time.Second, logger, defaultTestChannels)
}

func testHandlerWithChannels(p *pool.Pool, connector Connector, executor CommandExecutor, channels []string) *Handler {
	return New(p, connector, executor, 10*time.Second, testLogger(), channels)
}

func TestHealth(t *testing.T) {
	h := testHandler(pool.New(10*time.Minute, 0), nil, nil)

	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	w := httptest.NewRecorder()

	h.Health(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	var body healthResponse
	json.NewDecoder(w.Body).Decode(&body)
	if body.Status != "ok" {
		t.Errorf("expected status 'ok', got %q", body.Status)
	}
	if body.Version == "" {
		t.Error("expected non-empty version")
	}
	if body.UptimeSeconds < 0 {
		t.Errorf("expected non-negative uptime, got %d", body.UptimeSeconds)
	}
}

func TestStatus_Empty(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	h := testHandler(p, nil, nil)

	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	w := httptest.NewRecorder()

	h.Status(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	var body statusResponse
	json.NewDecoder(w.Body).Decode(&body)
	if body.UptimeSeconds < 0 {
		t.Errorf("expected non-negative uptime, got %d", body.UptimeSeconds)
	}
	if len(body.Connections) != 0 {
		t.Errorf("expected 0 connections, got %d", len(body.Connections))
	}
}

func TestStatus_WithConnections(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	seedPool(t, p, testKey("switch1.local", pool.DefaultChannel), newMockSession(), "")

	h := testHandler(p, nil, nil)

	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	w := httptest.NewRecorder()

	h.Status(w, req)

	var body statusResponse
	json.NewDecoder(w.Body).Decode(&body)
	if len(body.Connections) != 1 {
		t.Fatalf("expected 1 connection, got %d", len(body.Connections))
	}
	if body.Connections[0].Hostname != "switch1.local" {
		t.Errorf("expected hostname 'switch1.local', got %q", body.Connections[0].Hostname)
	}
	if body.Connections[0].Channel != pool.DefaultChannel {
		t.Errorf("expected channel %q, got %q", pool.DefaultChannel, body.Connections[0].Channel)
	}
}

func TestExecute_Success(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output: []ssh.CommandOutput{
				{Command: "show version", Output: "Cisco IOS..."},
			},
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"secret","commands":[{"command":"show version","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if !resp.Success {
		t.Errorf("expected success true, got false: %s", resp.Error)
	}
	if len(resp.Output) != 1 {
		t.Fatalf("expected 1 output, got %d", len(resp.Output))
	}
}

func TestExecute_InvalidJSON(t *testing.T) {
	h := testHandler(pool.New(10*time.Minute, 0), nil, nil)

	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader("not json"))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusBadRequest {
		t.Errorf("expected 400, got %d", w.Code)
	}

	var body map[string]string
	json.NewDecoder(w.Body).Decode(&body)
	if body["error"] != "Invalid JSON body" {
		t.Errorf("expected 'Invalid JSON body', got %q", body["error"])
	}
}

func TestExecute_MissingFields(t *testing.T) {
	tests := []struct {
		name string
		body string
	}{
		{"missing hostname", `{"username":"admin","commands":[{"command":"show ver"}]}`},
		{"missing username", `{"hostname":"switch1","commands":[{"command":"show ver"}]}`},
		{"missing commands", `{"hostname":"switch1","username":"admin"}`},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			h := testHandler(pool.New(10*time.Minute, 0), nil, nil)

			req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(tt.body))
			w := httptest.NewRecorder()

			h.Execute(w, req)

			if w.Code != http.StatusBadRequest {
				t.Errorf("expected 400, got %d", w.Code)
			}

			var body map[string]string
			json.NewDecoder(w.Body).Decode(&body)
			if body["error"] != "Missing required fields: hostname, username, commands" {
				t.Errorf("unexpected error: %q", body["error"])
			}
		})
	}
}

func TestExecute_HostLocked(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	heldLease, _ := p.Acquire(testKey("switch1", pool.DefaultChannel))
	defer heldLease.Release()

	h := testHandler(p, nil, nil)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusConflict {
		t.Errorf("expected 409, got %d", w.Code)
	}

	var resp map[string]string
	json.NewDecoder(w.Body).Decode(&resp)
	if resp["error"] != "Host is currently locked by another request" {
		t.Errorf("unexpected error: %q", resp["error"])
	}
}

func TestExecute_ConnectionFailure(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	connector := mockConnectorFailure("connection refused")
	h := testHandler(p, connector, nil)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusInternalServerError {
		t.Errorf("expected 500, got %d", w.Code)
	}

	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if resp.Success {
		t.Error("expected success false")
	}
	if !strings.Contains(resp.Error, "connection refused") {
		t.Errorf("expected error to contain 'connection refused', got %q", resp.Error)
	}
}

func TestExecute_ReusesPooledConnection(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#", "output\nSwitch#")

	seedPool(t, p, testKey("switch1", pool.DefaultChannel), session, "")

	connectorCalled := false
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		connectorCalled = true
		return nil, fmt.Errorf("should not be called")
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if connectorCalled {
		t.Error("connector should not have been called for pooled connection")
	}
	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}
}

func TestExecute_DefaultPort(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")

	var capturedPort int
	connector := func(_ context.Context, cp ssh.ConnectParams) (ssh.Session, error) {
		capturedPort = cp.Port
		return session, nil
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if capturedPort != 22 {
		t.Errorf("expected default port 22, got %d", capturedPort)
	}
}

func TestExecute_CustomPort(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")

	var capturedPort int
	connector := func(_ context.Context, cp ssh.ConnectParams) (ssh.Session, error) {
		capturedPort = cp.Port
		return session, nil
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","port":2222,"commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if capturedPort != 2222 {
		t.Errorf("expected port 2222, got %d", capturedPort)
	}
}

func TestNotFound(t *testing.T) {
	h := testHandler(pool.New(10*time.Minute, 0), nil, nil)

	req := httptest.NewRequest(http.MethodGet, "/nonexistent", nil)
	w := httptest.NewRecorder()

	h.NotFound(w, req)

	if w.Code != http.StatusNotFound {
		t.Errorf("expected 404, got %d", w.Code)
	}

	var body map[string]string
	json.NewDecoder(w.Body).Decode(&body)
	if body["error"] != "Not found" {
		t.Errorf("expected 'Not found', got %q", body["error"])
	}
}

func TestExecute_CommandFailure(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: false,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "partial"}},
			Error:   "Timeout waiting for expected pattern: #",
			Err:     fmt.Errorf("%w: #", ssh.ErrExpectTimeout),
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200 (command failure is still 200), got %d", w.Code)
	}

	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if resp.Success {
		t.Error("expected success false")
	}
	if resp.Error != "Timeout waiting for expected pattern: #" {
		t.Errorf("unexpected error: %q", resp.Error)
	}
}

func TestExecute_ReleasesLockOnSuccess(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	lease, err := p.Acquire(testKey("switch1", pool.DefaultChannel))
	if err != nil {
		t.Fatalf("expected lock to be released, got error: %v", err)
	}
	lease.Release()
}

func TestGenerateRequestID(t *testing.T) {
	id := GenerateRequestID()
	if len(id) != 8 {
		t.Errorf("expected 8 char request ID, got %d chars: %q", len(id), id)
	}

	id2 := GenerateRequestID()
	if id == id2 {
		t.Errorf("expected unique request IDs, got two identical: %q", id)
	}
}

func TestRequestIDContextRoundTrip(t *testing.T) {
	ctx := context.Background()

	if got := RequestIDFromContext(ctx); got != "" {
		t.Errorf("expected empty string from fresh context, got %q", got)
	}

	ctx = ContextWithRequestID(ctx, "abcd1234")
	if got := RequestIDFromContext(ctx); got != "abcd1234" {
		t.Errorf("expected 'abcd1234', got %q", got)
	}
}

func TestExecute_LogsRequestID(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()

	for _, line := range strings.Split(strings.TrimSpace(logs), "\n") {
		if line == "" {
			continue
		}
		var entry map[string]any
		if err := json.Unmarshal([]byte(line), &entry); err != nil {
			t.Fatalf("failed to parse log line as JSON: %s", line)
		}
		if _, ok := entry["request_id"]; !ok {
			t.Errorf("log line missing request_id: %s", line)
		}
		rid, _ := entry["request_id"].(string)
		if len(rid) != 8 {
			t.Errorf("expected 8-char request_id, got %q in line: %s", rid, line)
		}
	}
}

func TestExecute_LogsErrorOnFailure(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: false,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "partial"}},
			Error:   "Timeout waiting for expected pattern: #",
			Err:     fmt.Errorf("%w: #", ssh.ErrExpectTimeout),
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()
	found := false
	for _, line := range strings.Split(strings.TrimSpace(logs), "\n") {
		var entry map[string]any
		if err := json.Unmarshal([]byte(line), &entry); err != nil {
			continue
		}
		if entry["msg"] == "commands executed" {
			found = true
			if entry["success"] != false {
				t.Errorf("expected success=false in 'commands executed' log")
			}
			errVal, ok := entry["error"]
			if !ok {
				t.Error("expected 'error' field in 'commands executed' log when success=false")
			}
			if errStr, _ := errVal.(string); errStr != "Timeout waiting for expected pattern: #" {
				t.Errorf("unexpected error in log: %q", errStr)
			}
		}
	}
	if !found {
		t.Error("did not find 'commands executed' log line")
	}
}

func TestExecute_LogsNoErrorOnSuccess(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()
	for _, line := range strings.Split(strings.TrimSpace(logs), "\n") {
		var entry map[string]any
		if err := json.Unmarshal([]byte(line), &entry); err != nil {
			continue
		}
		if entry["msg"] == "commands executed" {
			if _, ok := entry["error"]; ok {
				t.Error("expected no 'error' field in 'commands executed' log when success=true")
			}
		}
	}
}

func TestExecute_LogsDurationMs(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()
	found := false
	for _, line := range strings.Split(strings.TrimSpace(logs), "\n") {
		var entry map[string]any
		if err := json.Unmarshal([]byte(line), &entry); err != nil {
			continue
		}
		if entry["msg"] == "commands executed" {
			found = true
			if _, ok := entry["duration_ms"]; !ok {
				t.Error("expected 'duration_ms' field in 'commands executed' log")
			}
			dur, ok := entry["duration_ms"].(float64)
			if !ok {
				t.Error("expected 'duration_ms' to be a number")
			}
			if dur < 0 {
				t.Errorf("expected non-negative duration_ms, got %f", dur)
			}
		}
	}
	if !found {
		t.Error("did not find 'commands executed' log line")
	}
}

func TestExecute_UsesContextRequestID(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	ctx := ContextWithRequestID(req.Context(), "deadbeef")
	req = req.WithContext(ctx)
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()
	for _, line := range strings.Split(strings.TrimSpace(logs), "\n") {
		if line == "" {
			continue
		}
		var entry map[string]any
		if err := json.Unmarshal([]byte(line), &entry); err != nil {
			continue
		}
		rid, _ := entry["request_id"].(string)
		if rid != "deadbeef" {
			t.Errorf("expected request_id 'deadbeef', got %q in line: %s", rid, line)
		}
	}
}

func TestExecute_DefaultChannel(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	_, infos := p.Status()
	if len(infos) != 1 {
		t.Fatalf("expected 1 pool entry, got %d", len(infos))
	}
	if infos[0].Channel != pool.DefaultChannel {
		t.Errorf("expected channel %q, got %q", pool.DefaultChannel, infos[0].Channel)
	}
}

func TestExecute_ExplicitChannel(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"polling","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	_, infos := p.Status()
	if len(infos) != 1 {
		t.Fatalf("expected 1 pool entry, got %d", len(infos))
	}
	if infos[0].Channel != "polling" {
		t.Errorf("expected channel 'polling', got %q", infos[0].Channel)
	}
}

func TestExecute_InvalidChannel(t *testing.T) {
	h := testHandler(pool.New(10*time.Minute, 0), nil, nil)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"invalid-channel","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusBadRequest {
		t.Errorf("expected 400, got %d", w.Code)
	}

	var resp map[string]string
	json.NewDecoder(w.Body).Decode(&resp)
	if !strings.Contains(resp["error"], "Invalid channel") {
		t.Errorf("expected 'Invalid channel' error, got %q", resp["error"])
	}
}

func TestExecute_DifferentChannelsSameHostNotBlocked(t *testing.T) {
	p := pool.New(10*time.Minute, 0)

	session1 := newMockSession("Switch#")
	session2 := newMockSession("Switch#")

	connectorCallCount := 0
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		connectorCallCount++
		if connectorCallCount == 1 {
			return session1, nil
		}
		return session2, nil
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body1 := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"commands","commands":[]}`
	req1 := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body1))
	w1 := httptest.NewRecorder()
	h.Execute(w1, req1)

	if w1.Code != http.StatusOK {
		t.Errorf("commands channel: expected 200, got %d", w1.Code)
	}

	body2 := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"polling","commands":[]}`
	req2 := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body2))
	w2 := httptest.NewRecorder()
	h.Execute(w2, req2)

	if w2.Code != http.StatusOK {
		t.Errorf("polling channel: expected 200, got %d", w2.Code)
	}

	_, infos := p.Status()
	if len(infos) != 2 {
		t.Fatalf("expected 2 pool entries, got %d", len(infos))
	}

	if connectorCallCount != 2 {
		t.Errorf("expected connector to be called 2 times, got %d", connectorCallCount)
	}
}

func TestExecute_ChannelReusesPooledConnection(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#", "output\nSwitch#")

	seedPool(t, p, testKey("switch1", "polling"), session, "")

	connectorCalled := false
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		connectorCalled = true
		return nil, fmt.Errorf("should not be called")
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"polling","commands":[{"command":"show ver"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if connectorCalled {
		t.Error("connector should not have been called for pooled channel connection")
	}
	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}
}

func TestExecute_ChannelLogsIncludeChannel(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10*time.Minute, 0)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  make([]ssh.CommandOutput, 0),
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"polling","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()
	for _, line := range strings.Split(strings.TrimSpace(logs), "\n") {
		if line == "" {
			continue
		}
		var entry map[string]any
		if err := json.Unmarshal([]byte(line), &entry); err != nil {
			continue
		}
		ch, ok := entry["channel"]
		if !ok {
			t.Errorf("log line missing channel: %s", line)
		}
		if ch != "polling" {
			t.Errorf("expected channel 'polling', got %q in line: %s", ch, line)
		}
	}
}

func TestIsValidChannel(t *testing.T) {
	h := testHandlerWithChannels(pool.New(10*time.Minute, 0), nil, nil, []string{"commands", "polling"})

	if !h.IsValidChannel("commands") {
		t.Error("expected 'commands' to be valid")
	}
	if !h.IsValidChannel("polling") {
		t.Error("expected 'polling' to be valid")
	}
	if h.IsValidChannel("invalid") {
		t.Error("expected 'invalid' to be invalid")
	}
	if h.IsValidChannel("") {
		t.Error("expected empty string to be invalid")
	}
}

type failOnceExecutor struct {
	callCount     int
	failResult    *ssh.CommandResult
	successResult *ssh.CommandResult
}

func (e *failOnceExecutor) Execute(_ ssh.Session, _ []ssh.Command) *ssh.CommandResult {
	e.callCount++
	if e.callCount == 1 {
		return e.failResult
	}
	return e.successResult
}

func TestExecute_RetryOnStaleConnection(t *testing.T) {
	p := pool.New(10*time.Minute, 0)

	staleSession := newMockSession("Switch#")
	seedPool(t, p, testKey("switch1", pool.DefaultChannel), staleSession, "")

	freshSession := newMockSession("Switch#", "output\nSwitch#")

	connectorCallCount := 0
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		connectorCallCount++
		return freshSession, nil
	}

	executor := &failOnceExecutor{
		failResult: &ssh.CommandResult{
			Success: false,
			Output:  make([]ssh.CommandOutput, 0),
			Error:   "Failed to send command: connection lost: connection closed",
			Err:     fmt.Errorf("%w: connection closed", ssh.ErrConnectionLost),
		},
		successResult: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "Cisco IOS..."}},
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if !resp.Success {
		t.Errorf("expected success after retry, got error: %s", resp.Error)
	}

	if connectorCallCount != 1 {
		t.Errorf("expected connector called 1 time (retry), got %d", connectorCallCount)
	}

	if executor.callCount != 2 {
		t.Errorf("expected executor called 2 times, got %d", executor.callCount)
	}
}

func TestExecute_RetryNotTriggeredOnNewConnection(t *testing.T) {
	p := pool.New(10*time.Minute, 0)

	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)

	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: false,
			Output:  make([]ssh.CommandOutput, 0),
			Error:   "Failed to send command: connection lost: connection closed",
			Err:     fmt.Errorf("%w: connection closed", ssh.ErrConnectionLost),
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if resp.Success {
		t.Error("expected failure — retry should NOT happen on new connections")
	}
	if resp.Error != "Failed to send command: connection lost: connection closed" {
		t.Errorf("unexpected error: %q", resp.Error)
	}
}

func TestExecute_RetryReconnectFailure(t *testing.T) {
	p := pool.New(10*time.Minute, 0)

	staleSession := newMockSession("Switch#")
	seedPool(t, p, testKey("switch1", pool.DefaultChannel), staleSession, "")

	connector := mockConnectorFailure("connection refused")

	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: false,
			Output:  make([]ssh.CommandOutput, 0),
			Error:   "Failed to send command: connection lost: connection closed",
			Err:     fmt.Errorf("%w: connection closed", ssh.ErrConnectionLost),
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusInternalServerError {
		t.Errorf("expected 500, got %d", w.Code)
	}

	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if resp.Success {
		t.Error("expected failure when retry reconnect fails")
	}
	if !strings.Contains(resp.Error, "connection refused") {
		t.Errorf("expected 'connection refused' in error, got %q", resp.Error)
	}
}

func TestExecute_RetryNotTriggeredOnNonConnectionError(t *testing.T) {
	p := pool.New(10*time.Minute, 0)

	session := newMockSession("Switch#")
	seedPool(t, p, testKey("switch1", pool.DefaultChannel), session, "")

	connectorCalled := false
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		connectorCalled = true
		return nil, fmt.Errorf("should not be called")
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: false,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "partial"}},
			Error:   "Timeout waiting for expected pattern: #",
			Err:     fmt.Errorf("%w: #", ssh.ErrExpectTimeout),
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if resp.Success {
		t.Error("expected failure (non-connection error)")
	}

	if connectorCalled {
		t.Error("connector should NOT be called — non-connection errors should not trigger retry")
	}
}

func TestExecute_RetryLogsStaleRecovery(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10*time.Minute, 0)

	staleSession := newMockSession("Switch#")
	seedPool(t, p, testKey("switch1", pool.DefaultChannel), staleSession, "")

	freshSession := newMockSession("Switch#", "output\nSwitch#")
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return freshSession, nil
	}

	executor := &failOnceExecutor{
		failResult: &ssh.CommandResult{
			Success: false,
			Output:  make([]ssh.CommandOutput, 0),
			Error:   "Failed to send command: connection lost: connection closed",
			Err:     fmt.Errorf("%w: connection closed", ssh.ErrConnectionLost),
		},
		successResult: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver","expect":{"type":"literal","value":"#"}}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()
	foundStaleWarning := false
	foundRetrySuccess := false
	for _, line := range strings.Split(strings.TrimSpace(logs), "\n") {
		var entry map[string]any
		if err := json.Unmarshal([]byte(line), &entry); err != nil {
			continue
		}
		if entry["msg"] == "stale connection detected, retrying with new connection" {
			foundStaleWarning = true
		}
		if entry["msg"] == "retry succeeded after stale connection recovery" {
			foundRetrySuccess = true
		}
	}
	if !foundStaleWarning {
		t.Error("expected 'stale connection detected' log message")
	}
	if !foundRetrySuccess {
		t.Error("expected 'retry succeeded after stale connection recovery' log message")
	}
}

func TestExecute_RetryReleasesLock(t *testing.T) {
	p := pool.New(10*time.Minute, 0)

	staleSession := newMockSession("Switch#")
	seedPool(t, p, testKey("switch1", pool.DefaultChannel), staleSession, "")

	freshSession := newMockSession("Switch#", "output\nSwitch#")
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		return freshSession, nil
	}

	executor := &failOnceExecutor{
		failResult: &ssh.CommandResult{
			Success: false,
			Output:  make([]ssh.CommandOutput, 0),
			Error:   "Failed to send command: connection lost: connection closed",
			Err:     fmt.Errorf("%w: connection closed", ssh.ErrConnectionLost),
		},
		successResult: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"show ver"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	lease, err := p.Acquire(testKey("switch1", pool.DefaultChannel))
	if err != nil {
		t.Fatalf("expected lock to be released after retry, got error: %v", err)
	}
	lease.Release()
}

func TestExecute_HostLockedOnOneChannelNotAnother(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	heldLease, _ := p.Acquire(testKey("switch1", "commands"))
	defer heldLease.Release()

	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body1 := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"commands","commands":[]}`
	req1 := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body1))
	w1 := httptest.NewRecorder()
	h.Execute(w1, req1)

	if w1.Code != http.StatusConflict {
		t.Errorf("commands channel: expected 409 (locked), got %d", w1.Code)
	}

	body2 := `{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","channel":"polling","commands":[]}`
	req2 := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body2))
	w2 := httptest.NewRecorder()
	h.Execute(w2, req2)

	if w2.Code != http.StatusOK {
		t.Errorf("polling channel: expected 200 (not locked), got %d", w2.Code)
	}
}

func testKey(hostname, channel string) pool.Key {
	return pool.NewKey(hostname, 22, "admin", channel, "password", "pass", "", "")
}

func seedPool(t *testing.T, p *pool.Pool, key pool.Key, conn ssh.Session, hostKey string) {
	t.Helper()
	l, err := p.Acquire(key)
	if err != nil {
		t.Fatalf("seed acquire: %v", err)
	}
	l.SetConnection(conn)
	if hostKey != "" {
		l.SetHostKey(hostKey)
	}
	l.Release()
}

func TestExecute_RetryDecisionIgnoresErrorText(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	seedPool(t, p, testKey("switch1", pool.DefaultChannel), newMockSession("Switch#"), "")
	connectorCalled := false
	connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
		connectorCalled = true
		return newMockSession(), nil
	}
	executor := &mockExecutor{result: &ssh.CommandResult{
		Success: false,
		Error:   "device said: connection closed, EOF, broken pipe",
	}}
	h := testHandler(p, connector, executor)

	w := httptest.NewRecorder()
	h.Execute(w, httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(
		`{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"x"}]}`)))

	if connectorCalled {
		t.Error("error text alone must not trigger a reconnect")
	}
}

func TestExecute_RetryReplacesStaleConnectionInPool(t *testing.T) {
	p := pool.New(10*time.Minute, 0)
	stale := newMockSession("Switch#")
	seedPool(t, p, testKey("switch1", pool.DefaultChannel), stale, "")
	fresh := newMockSession("Switch#")
	executor := &failOnceExecutor{
		failResult:    &ssh.CommandResult{Error: "lost", Err: ssh.ErrConnectionLost},
		successResult: &ssh.CommandResult{Success: true, Output: []ssh.CommandOutput{}},
	}
	h := testHandler(p, mockConnectorSuccess(fresh), executor)

	w := httptest.NewRecorder()
	h.Execute(w, httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(
		`{"hostname":"switch1","username":"admin","auth_method":"password","password":"pass","commands":[{"command":"x"}]}`)))

	if !stale.closed {
		t.Error("stale connection should be closed on reconnect")
	}
	lease, err := p.Acquire(testKey("switch1", pool.DefaultChannel))
	if err != nil {
		t.Fatalf("lease not released after retry: %v", err)
	}
	if lease.IsNew() || lease.Conn() != ssh.Session(fresh) {
		t.Error("pool should hold the fresh connection")
	}
	lease.Release()
}

func TestExecute_RejectsBadAuthMethod(t *testing.T) {
	for _, am := range []string{``, `"auth_method":"",`, `"auth_method":"kerberos",`, `"auth_method":"PASSWORD",`} {
		called := false
		connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
			called = true
			return newMockSession(), nil
		}
		h := testHandler(pool.New(time.Minute, 0), connector, okExecutor())
		w := httptest.NewRecorder()
		h.Execute(w, httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(
			`{"hostname":"sw","username":"u",`+am+`"password":"p","private_key":"k","commands":[]}`)))
		if w.Code != http.StatusBadRequest || called {
			t.Errorf("auth_method %q: status=%d connectorCalled=%v", am, w.Code, called)
		}
		var resp map[string]string
		if err := json.NewDecoder(w.Body).Decode(&resp); err != nil || !strings.Contains(resp["error"], "auth_method") {
			t.Errorf("auth_method %q: unexpected body %v (%v)", am, resp, err)
		}
	}
}

func TestExecute_PasswordAuthIsExplicitEvenWithKeyPresent(t *testing.T) {
	var got ssh.ConnectParams
	connector := func(_ context.Context, cp ssh.ConnectParams) (ssh.Session, error) {
		got = cp
		return newMockSession(), nil
	}
	h := testHandler(pool.New(time.Minute, 0), connector, okExecutor())
	w := httptest.NewRecorder()
	h.Execute(w, httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(
		`{"hostname":"sw","username":"u","auth_method":"password","password":"p","private_key":"k","commands":[]}`)))
	if got.Auth != ssh.AuthPassword || got.Password != "p" {
		t.Errorf("unexpected params: %+v", got)
	}
}

func TestExecute_RejectsInvalidMatchers(t *testing.T) {
	cases := map[string]string{
		"unknown type":  `{"command":"x","if":{"type":"glob","value":"a"}}`,
		"missing type":  `{"command":"x","expect":{"value":"a"}}`,
		"empty value":   `{"command":"x","expect":{"type":"literal","value":""}}`,
		"bad regex":     `{"command":"x","if":{"type":"regex","value":"[oops"}}`,
		"legacy string": `{"command":"x","expect":"/foo/"}`,
	}
	for name, cmd := range cases {
		t.Run(name, func(t *testing.T) {
			called := false
			connector := func(_ context.Context, _ ssh.ConnectParams) (ssh.Session, error) {
				called = true
				return newMockSession(), nil
			}
			h := testHandler(pool.New(time.Minute, 0), connector, okExecutor())
			w := httptest.NewRecorder()
			h.Execute(w, httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(
				`{"hostname":"sw","username":"u","auth_method":"password","password":"p","commands":[`+cmd+`]}`)))
			if w.Code != http.StatusBadRequest || called {
				t.Errorf("status=%d connectorCalled=%v body=%s", w.Code, called, w.Body)
			}
		})
	}
}

func TestExecute_ValidMatchersAccepted(t *testing.T) {
	session := newMockSession("Switch#", "show ver\nfoo\nSwitch#")
	exec := &ssh.Executor{ReadTimeout: time.Second, CommandTimeout: time.Second}
	h := testHandler(pool.New(time.Minute, 0), mockConnectorSuccess(session), exec)
	w := httptest.NewRecorder()
	h.Execute(w, httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(
		`{"hostname":"sw","username":"u","auth_method":"password","password":"p","commands":[
		{"command":"show ver","if":{"type":"literal","value":"#"},"expect":{"type":"regex","value":"^{prompt}#$"}},
		{"command":"skipped","if":{"type":"literal","value":"/#/"}}]}`)))
	var resp executeResponse
	json.NewDecoder(w.Body).Decode(&resp)
	if w.Code != http.StatusOK || !resp.Success || len(resp.Output) != 1 {
		t.Fatalf("status=%d resp=%+v", w.Code, resp)
	}
}

func TestExecute_SensitiveCommandsRedactedInLogs(t *testing.T) {
	var buf bytes.Buffer
	session := newMockSession("Switch>", "Password:", "Switch#", "Switch#")
	exec := &ssh.Executor{ReadTimeout: time.Second, CommandTimeout: time.Second, Logger: testLoggerWithBuffer(&buf)}
	h := testHandlerWithLogger(pool.New(time.Minute, 0), mockConnectorSuccess(session), exec, testLoggerWithBuffer(&buf))

	w := httptest.NewRecorder()
	h.Execute(w, httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(
		`{"hostname":"sw","username":"u","auth_method":"password","password":"p","commands":[
		{"command":"enable"},
		{"command":"hunter2","sensitive":true,"if":{"type":"literal","value":"Password:"}},
		{"command":"show clock"}]}`)))

	if w.Code != http.StatusOK {
		t.Fatalf("status %d: %s", w.Code, w.Body)
	}
	logs := buf.String()
	if strings.Contains(logs, "hunter2") {
		t.Errorf("sensitive command leaked into logs:\n%s", logs)
	}
	if !strings.Contains(logs, "****") {
		t.Error("expected redaction marker in logs")
	}
	for _, want := range []string{"enable", "show clock"} {
		if !strings.Contains(logs, want) {
			t.Errorf("non-sensitive command %q should be logged verbatim", want)
		}
	}
	if !strings.Contains(w.Body.String(), "hunter2") {
		t.Error("response output must still carry the real command")
	}
}

type unmarshalableValue struct{}

func (unmarshalableValue) MarshalJSON() ([]byte, error) { return nil, fmt.Errorf("nope") }

func TestWriteJSON_MarshalFailureYields500(t *testing.T) {
	var buf bytes.Buffer
	w := httptest.NewRecorder()
	WriteJSON(testLoggerWithBuffer(&buf), w, http.StatusOK, map[string]any{"a": unmarshalableValue{}})
	if w.Code != http.StatusInternalServerError {
		t.Errorf("status = %d, want 500", w.Code)
	}
	if strings.Contains(w.Body.String(), `"a"`) {
		t.Errorf("partial body written: %s", w.Body)
	}
	if !strings.Contains(buf.String(), "failed to encode") {
		t.Error("marshal failure should be logged")
	}
}
