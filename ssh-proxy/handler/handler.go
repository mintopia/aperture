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

type contextKey int

const (
	requestIDKey contextKey = iota
)

// Version is set at build time via -ldflags.
var Version = "dev"

type Connector func(ctx context.Context, params ssh.ConnectParams) (ssh.Session, error)

type CommandExecutor interface {
	Execute(session ssh.Session, commands []ssh.Command) *ssh.CommandResult
}

type Handler struct {
	pool           *pool.Pool
	connector      Connector
	executor       CommandExecutor
	connectTimeout time.Duration
	logger         *slog.Logger
	startTime      time.Time
	channels       map[string]bool
}

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

func (h *Handler) IsValidChannel(channel string) bool {
	return h.channels[channel]
}

func GenerateRequestID() string {
	b := make([]byte, 4)
	if _, err := rand.Read(b); err != nil {
		return "00000000"
	}
	return hex.EncodeToString(b)
}

func RequestIDFromContext(ctx context.Context) string {
	if id, ok := ctx.Value(requestIDKey).(string); ok {
		return id
	}
	return ""
}

func ContextWithRequestID(ctx context.Context, id string) context.Context {
	return context.WithValue(ctx, requestIDKey, id)
}

func writeJSON(w http.ResponseWriter, status int, data any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(data)
}
