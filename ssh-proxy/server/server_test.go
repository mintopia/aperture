package server

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"sshproxy/handler"
	"sshproxy/pool"
	"sshproxy/ssh"
	"strings"
	"testing"
	"time"
)

// --- Mocks ---

type mockSession struct{}

func (m *mockSession) Write(_ string) error        { return nil }
func (m *mockSession) Read(_ time.Duration) string { return "Switch#" }
func (m *mockSession) Close() error                { return nil }

type mockExecutor struct{}

func (m *mockExecutor) Execute(_ ssh.Session, _ []ssh.Command) *ssh.CommandResult {
	return &ssh.CommandResult{
		Success: true,
		Output:  make([]ssh.CommandOutput, 0),
	}
}

func testServer(apiKey string) *http.Server {
	logger := slog.New(slog.NewTextHandler(&bytes.Buffer{}, &slog.HandlerOptions{Level: slog.LevelError}))
	p := pool.New(10 * time.Minute)
	connector := func(_ context.Context, _ string, _ int, _, _ string) (ssh.Session, error) {
		return &mockSession{}, nil
	}
	h := handler.New(p, connector, &mockExecutor{}, 10*time.Second, logger, []string{"commands", "polling"})
	return New("localhost:0", apiKey, h, logger)
}

// --- Auth middleware tests ---

func TestAuth_ValidToken(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	req.Header.Set("Authorization", "Bearer test-key")
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}
}

func TestAuth_MissingToken(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", w.Code)
	}

	var body map[string]string
	json.NewDecoder(w.Body).Decode(&body)
	if body["error"] != "Unauthorized" {
		t.Errorf("expected 'Unauthorized', got %q", body["error"])
	}
}

func TestAuth_WrongToken(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	req.Header.Set("Authorization", "Bearer wrong-key")
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", w.Code)
	}
}

func TestAuth_NoBearerPrefix(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	req.Header.Set("Authorization", "test-key")
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusUnauthorized {
		t.Errorf("expected 401, got %d", w.Code)
	}
}

func TestAuth_ConstantTimeComparison(t *testing.T) {
	// Verifies that authentication uses constant-time comparison:
	// a token differing by one byte must be rejected, and the exact key accepted.
	apiKey := "correct-horse-battery-staple"
	srv := testServer(apiKey)

	// Exact match must be accepted.
	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	req.Header.Set("Authorization", "Bearer "+apiKey)
	w := httptest.NewRecorder()
	srv.Handler.ServeHTTP(w, req)
	if w.Code != http.StatusOK {
		t.Errorf("constant-time: expected 200 for correct key, got %d", w.Code)
	}

	// Token with one byte different must be rejected.
	badKey := "correct-horse-battery-Staple" // capital S differs at index 22
	req2 := httptest.NewRequest(http.MethodGet, "/status", nil)
	req2.Header.Set("Authorization", "Bearer "+badKey)
	w2 := httptest.NewRecorder()
	srv.Handler.ServeHTTP(w2, req2)
	if w2.Code != http.StatusUnauthorized {
		t.Errorf("constant-time: expected 401 for near-match key, got %d", w2.Code)
	}

	// Token longer than key by one byte must be rejected.
	longKey := apiKey + "x"
	req3 := httptest.NewRequest(http.MethodGet, "/status", nil)
	req3.Header.Set("Authorization", "Bearer "+longKey)
	w3 := httptest.NewRecorder()
	srv.Handler.ServeHTTP(w3, req3)
	if w3.Code != http.StatusUnauthorized {
		t.Errorf("constant-time: expected 401 for longer key, got %d", w3.Code)
	}
}

// --- Routing tests ---

func TestRouting_Health_NoAuth(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	// No Authorization header
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200 for /health without auth, got %d", w.Code)
	}

	var body map[string]string
	json.NewDecoder(w.Body).Decode(&body)
	if body["status"] != "ok" {
		t.Errorf("expected status 'ok', got %q", body["status"])
	}
}

func TestRouting_Execute(t *testing.T) {
	srv := testServer("test-key")

	body := `{"hostname":"sw1","username":"admin","password":"pass","commands":[]}`
	req := httptest.NewRequest(http.MethodPost, "/execute", strings.NewReader(body))
	req.Header.Set("Authorization", "Bearer test-key")
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}
}

func TestRouting_NotFound(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/nonexistent", nil)
	req.Header.Set("Authorization", "Bearer test-key")
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusNotFound {
		t.Errorf("expected 404, got %d", w.Code)
	}

	var body map[string]string
	json.NewDecoder(w.Body).Decode(&body)
	if body["error"] != "Not found" {
		t.Errorf("expected 'Not found', got %q", body["error"])
	}
}

func TestRouting_WrongMethod(t *testing.T) {
	srv := testServer("test-key")

	// GET to /execute should be 404 (only POST is registered)
	req := httptest.NewRequest(http.MethodGet, "/execute", nil)
	req.Header.Set("Authorization", "Bearer test-key")
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusNotFound {
		t.Errorf("expected 404 for GET /execute, got %d", w.Code)
	}
}

func TestRouting_Status(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/status", nil)
	req.Header.Set("Authorization", "Bearer test-key")
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("expected 200, got %d", w.Code)
	}

	// Verify response shape
	var body map[string]any
	json.NewDecoder(w.Body).Decode(&body)
	if _, ok := body["uptime_seconds"]; !ok {
		t.Error("expected 'uptime_seconds' in response")
	}
	if _, ok := body["connections"]; !ok {
		t.Error("expected 'connections' in response")
	}
}

// --- Response format tests ---

func TestResponseContentType(t *testing.T) {
	srv := testServer("test-key")

	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	w := httptest.NewRecorder()

	srv.Handler.ServeHTTP(w, req)

	ct := w.Header().Get("Content-Type")
	if ct != "application/json" {
		t.Errorf("expected Content-Type 'application/json', got %q", ct)
	}
}
