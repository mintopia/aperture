package handler

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"sshproxy/pool"
	"sshproxy/ssh"
	"strings"
	"time"
)

// executeRequest matches the PHP client's POST /execute JSON body.
type executeRequest struct {
	Hostname string        `json:"hostname"`
	Username string        `json:"username"`
	Password string        `json:"password"`
	Commands []ssh.Command `json:"commands"`
	Port     int           `json:"port"`
}

// executeResponse matches the PHP API's success response shape.
type executeResponse struct {
	Success bool                `json:"success"`
	Output  []ssh.CommandOutput `json:"output"`
	Error   string              `json:"error,omitempty"`
}

// Execute handles POST /execute — runs commands on a network switch via SSH.
func (h *Handler) Execute(w http.ResponseWriter, r *http.Request) {
	requestID := RequestIDFromContext(r.Context())
	if requestID == "" {
		requestID = GenerateRequestID()
	}

	var req executeRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid JSON body"})
		return
	}

	// Validate required fields (matches PHP: hostname, username, commands required).
	if req.Hostname == "" || req.Username == "" || req.Commands == nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{
			"error": "Missing required fields: hostname, username, commands",
		})
		return
	}

	// Default port to 22.
	if req.Port == 0 {
		req.Port = 22
	}

	// Acquire a pool slot for this hostname.
	entry, isNew, err := h.pool.Acquire(req.Hostname)
	if err != nil {
		if errors.Is(err, pool.ErrHostLocked) {
			h.logger.Warn("host is locked",
				"hostname", req.Hostname,
				"request_id", requestID,
			)
			writeJSON(w, http.StatusConflict, map[string]string{
				"error": "Host is currently locked by another request",
			})
			return
		}
		writeJSON(w, http.StatusInternalServerError, executeResponse{
			Success: false,
			Error:   fmt.Sprintf("Pool error: %s", err),
		})
		return
	}

	// If the connection is new, establish the SSH session.
	var session ssh.Session
	if isNew {
		ctx, cancel := context.WithTimeout(r.Context(), h.connectTimeout)
		defer cancel()

		h.logger.Info("connecting to host",
			"hostname", req.Hostname,
			"port", req.Port,
			"request_id", requestID,
		)

		conn, connErr := h.connector(ctx, req.Hostname, req.Port, req.Username, req.Password)
		if connErr != nil {
			h.logger.Error("SSH connection failed",
				"hostname", req.Hostname,
				"port", req.Port,
				"error", connErr,
				"request_id", requestID,
			)
			// Clean up the placeholder entry.
			h.pool.Remove(req.Hostname)
			writeJSON(w, http.StatusInternalServerError, executeResponse{
				Success: false,
				Output:  make([]ssh.CommandOutput, 0),
				Error:   fmt.Sprintf("SSH connection failed: %s", connErr),
			})
			return
		}

		h.pool.SetConnection(req.Hostname, conn)
		session = conn
		h.logger.Info("connected to host",
			"hostname", req.Hostname,
			"port", req.Port,
			"request_id", requestID,
		)
	} else {
		// Reuse existing connection — the entry.Conn is an io.Closer,
		// but we know it's also an ssh.Session.
		var ok bool
		session, ok = entry.Conn.(ssh.Session)
		if !ok || session == nil {
			h.logger.Error("pooled connection is not a valid session",
				"hostname", req.Hostname,
				"request_id", requestID,
			)
			h.pool.Remove(req.Hostname)
			writeJSON(w, http.StatusInternalServerError, executeResponse{
				Success: false,
				Output:  make([]ssh.CommandOutput, 0),
				Error:   "SSH connection failed: pooled connection invalid",
			})
			return
		}
		h.logger.Debug("reusing pooled connection",
			"hostname", req.Hostname,
			"request_id", requestID,
		)
	}

	// Execute commands — always release the lock afterwards.
	defer h.pool.Release(req.Hostname)

	h.logger.Info("executing commands",
		"hostname", req.Hostname,
		"command_count", len(req.Commands),
		"commands_summary", commandsSummary(req.Commands),
		"request_id", requestID,
	)

	execStart := time.Now()
	result := h.executor.Execute(session, req.Commands)
	execDuration := time.Since(execStart)

	// Build log attributes for the "commands executed" line.
	logAttrs := []any{
		"hostname", req.Hostname,
		"success", result.Success,
		"duration_ms", execDuration.Milliseconds(),
		"request_id", requestID,
	}
	if result.Error != "" {
		logAttrs = append(logAttrs, "error", result.Error)
	}
	h.logger.Info("commands executed", logAttrs...)

	writeJSON(w, http.StatusOK, executeResponse{
		Success: result.Success,
		Output:  result.Output,
		Error:   result.Error,
	})
}

// NotFound handles unmatched routes.
func (h *Handler) NotFound(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusNotFound, map[string]string{"error": "Not found"})
}

func commandsSummary(commands []ssh.Command) []string {
	summary := make([]string, 0, len(commands))
	for _, cmd := range commands {
		summary = append(summary, sanitizeCommandForLog(cmd.Command))
	}
	return summary
}

func sanitizeCommandForLog(cmd string) string {
	if len(cmd) < 50 &&
		!strings.Contains(cmd, " ") &&
		!strings.HasPrefix(cmd, "show") &&
		!strings.HasPrefix(cmd, "terminal") &&
		!strings.HasPrefix(cmd, "en") &&
		!strings.HasPrefix(cmd, "configure") &&
		!strings.HasPrefix(cmd, "interface") &&
		!strings.HasPrefix(cmd, "no ") &&
		!strings.HasPrefix(cmd, "shutdown") &&
		!strings.HasPrefix(cmd, "end") &&
		!strings.HasPrefix(cmd, "write") {
		return "****"
	}
	return cmd
}
