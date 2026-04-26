// Package handler implements HTTP request handlers for the SSH proxy API.
package handler

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"log/slog"
	"net/http"
	"sshproxy/pool"
	"sshproxy/ssh"
	"time"
)

// contextKey is an unexported type for context keys in this package.
type contextKey int

const (
	// requestIDKey is the context key for the request ID.
	requestIDKey contextKey = iota
)

// Version is set at build time via -ldflags.
var Version = "dev"

// Connector creates SSH sessions for a given host.
type Connector func(ctx context.Context, hostname string, port int, username, password string) (ssh.Session, error)

// CommandExecutor runs commands on an SSH session.
type CommandExecutor interface {
	Execute(session ssh.Session, commands []ssh.Command) *ssh.CommandResult
}

// Handler holds dependencies for all HTTP handlers.
type Handler struct {
	pool           *pool.Pool
	connector      Connector
	executor       CommandExecutor
	connectTimeout time.Duration
	logger         *slog.Logger
	startTime      time.Time
	channels       map[string]bool
}

// New creates a Handler with the given dependencies.
func New(
	p *pool.Pool,
	connector Connector,
	executor CommandExecutor,
	connectTimeout time.Duration,
	logger *slog.Logger,
	channels []string,
) *Handler {
	channelSet := make(map[string]bool, len(channels))
	for _, ch := range channels {
		channelSet[ch] = true
	}
	return &Handler{
		pool:           p,
		connector:      connector,
		executor:       executor,
		connectTimeout: connectTimeout,
		logger:         logger,
		startTime:      time.Now(),
		channels:       channelSet,
	}
}

// IsValidChannel reports whether the given channel name is allowed.
func (h *Handler) IsValidChannel(channel string) bool {
	return h.channels[channel]
}

// GenerateRequestID creates a short random request ID (8 hex characters).
func GenerateRequestID() string {
	b := make([]byte, 4)
	if _, err := rand.Read(b); err != nil {
		// Fallback: should never happen in practice.
		return "00000000"
	}
	return hex.EncodeToString(b)
}

// RequestIDFromContext retrieves the request ID from a context, or empty string.
func RequestIDFromContext(ctx context.Context) string {
	if id, ok := ctx.Value(requestIDKey).(string); ok {
		return id
	}
	return ""
}

// ContextWithRequestID returns a new context with the given request ID.
func ContextWithRequestID(ctx context.Context, id string) context.Context {
	return context.WithValue(ctx, requestIDKey, id)
}

// writeJSON writes a JSON response with the given status code.
func writeJSON(w http.ResponseWriter, status int, data any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(data) //nolint:errcheck
}
