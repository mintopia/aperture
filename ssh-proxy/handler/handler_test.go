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

// --- Mocks ---

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
	return func(_ context.Context, _ string, _ int, _, _ string) (ssh.Session, error) {
		return session, nil
	}
}

func mockConnectorFailure(errMsg string) Connector {
	return func(_ context.Context, _ string, _ int, _, _ string) (ssh.Session, error) {
		return nil, fmt.Errorf("%s", errMsg)
	}
}

// testLoggerWithBuffer creates a logger that writes JSON to the given buffer.
func testLoggerWithBuffer(buf *bytes.Buffer) *slog.Logger {
	return slog.New(slog.NewJSONHandler(buf, &slog.HandlerOptions{Level: slog.LevelDebug}))
}

func testLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(&bytes.Buffer{}, &slog.HandlerOptions{Level: slog.LevelError}))
}

func testHandler(p *pool.Pool, connector Connector, executor CommandExecutor) *Handler {
	return New(p, connector, executor, 10*time.Second, testLogger())
}

func testHandlerWithLogger(p *pool.Pool, connector Connector, executor CommandExecutor, logger *slog.Logger) *Handler {
	return New(p, connector, executor, 10*time.Second, logger)
}

// --- Health tests ---

func TestHealth(t *testing.T) {
	h := testHandler(pool.New(10*time.Minute), nil, nil)

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

// --- Status tests ---

func TestStatus_Empty(t *testing.T) {
	p := pool.New(10 * time.Minute)
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
	p := pool.New(10 * time.Minute)
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", newMockSession())
	p.Release("switch1.local")

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
}

// --- Execute tests ---

func TestExecute_Success(t *testing.T) {
	p := pool.New(10 * time.Minute)
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

	body := `{"hostname":"switch1","username":"admin","password":"secret","commands":[{"command":"show version","expect":"#"}]}`
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
	h := testHandler(pool.New(10*time.Minute), nil, nil)

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
			h := testHandler(pool.New(10*time.Minute), nil, nil)

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
	p := pool.New(10 * time.Minute)
	// Lock the host
	_, _, _ = p.Acquire("switch1")
	// Don't release — it stays locked

	h := testHandler(p, nil, nil)

	body := `{"hostname":"switch1","username":"admin","commands":[{"command":"show ver"}]}`
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
	p := pool.New(10 * time.Minute)
	connector := mockConnectorFailure("connection refused")
	h := testHandler(p, connector, nil)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver"}]}`
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
	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#", "output\nSwitch#")

	// Pre-populate the pool
	_, _, _ = p.Acquire("switch1")
	p.SetConnection("switch1", session)
	p.Release("switch1")

	connectorCalled := false
	connector := func(_ context.Context, _ string, _ int, _, _ string) (ssh.Session, error) {
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

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver"}]}`
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
	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#")

	var capturedPort int
	connector := func(_ context.Context, _ string, port int, _, _ string) (ssh.Session, error) {
		capturedPort = port
		return session, nil
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if capturedPort != 22 {
		t.Errorf("expected default port 22, got %d", capturedPort)
	}
}

func TestExecute_CustomPort(t *testing.T) {
	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#")

	var capturedPort int
	connector := func(_ context.Context, _ string, port int, _, _ string) (ssh.Session, error) {
		capturedPort = port
		return session, nil
	}

	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","password":"pass","port":2222,"commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	if capturedPort != 2222 {
		t.Errorf("expected port 2222, got %d", capturedPort)
	}
}

// --- NotFound tests ---

func TestNotFound(t *testing.T) {
	h := testHandler(pool.New(10*time.Minute), nil, nil)

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
	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: false,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "partial"}},
			Error:   "Timeout waiting for expected pattern: #",
		},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver","expect":"#"}]}`
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
	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{Success: true, Output: make([]ssh.CommandOutput, 0)},
	}

	h := testHandler(p, connector, executor)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	// Try to acquire again — should succeed (lock was released)
	_, _, err := p.Acquire("switch1")
	if err != nil {
		t.Errorf("expected lock to be released, got error: %v", err)
	}
	p.Release("switch1")
}

// --- Request ID tests ---

func TestGenerateRequestID(t *testing.T) {
	id := GenerateRequestID()
	if len(id) != 8 {
		t.Errorf("expected 8 char request ID, got %d chars: %q", len(id), id)
	}

	// Verify uniqueness (probabilistic, but collision on 4 random bytes is extremely unlikely).
	id2 := GenerateRequestID()
	if id == id2 {
		t.Errorf("expected unique request IDs, got two identical: %q", id)
	}
}

func TestRequestIDContextRoundTrip(t *testing.T) {
	ctx := context.Background()

	// No ID set — should return empty string.
	if got := RequestIDFromContext(ctx); got != "" {
		t.Errorf("expected empty string from fresh context, got %q", got)
	}

	// Set and retrieve.
	ctx = ContextWithRequestID(ctx, "abcd1234")
	if got := RequestIDFromContext(ctx); got != "abcd1234" {
		t.Errorf("expected 'abcd1234', got %q", got)
	}
}

func TestExecute_LogsRequestID(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver","expect":"#"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	logs := logBuf.String()

	// Every INFO/DEBUG log line from the execute handler should contain request_id.
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
		// Verify request_id is an 8-char hex string.
		rid, _ := entry["request_id"].(string)
		if len(rid) != 8 {
			t.Errorf("expected 8-char request_id, got %q in line: %s", rid, line)
		}
	}
}

func TestExecute_LogsErrorOnFailure(t *testing.T) {
	var logBuf bytes.Buffer
	logger := testLoggerWithBuffer(&logBuf)

	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: false,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "partial"}},
			Error:   "Timeout waiting for expected pattern: #",
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver","expect":"#"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	// Find the "commands executed" log line and verify it contains the error.
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

	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver","expect":"#"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	// Find the "commands executed" log line and verify it does NOT contain error.
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

	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver","expect":"#"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	w := httptest.NewRecorder()

	h.Execute(w, req)

	// Find the "commands executed" log line and verify it contains duration_ms.
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
			// duration_ms should be a number >= 0.
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

	p := pool.New(10 * time.Minute)
	session := newMockSession("Switch#", "output\nSwitch#")
	connector := mockConnectorSuccess(session)
	executor := &mockExecutor{
		result: &ssh.CommandResult{
			Success: true,
			Output:  []ssh.CommandOutput{{Command: "show ver", Output: "..."}},
		},
	}

	h := testHandlerWithLogger(p, connector, executor, logger)

	body := `{"hostname":"switch1","username":"admin","password":"pass","commands":[{"command":"show ver","expect":"#"}]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	// Inject a known request ID via context.
	ctx := ContextWithRequestID(req.Context(), "deadbeef")
	req = req.WithContext(ctx)
	w := httptest.NewRecorder()

	h.Execute(w, req)

	// All log lines should use the injected request ID.
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
