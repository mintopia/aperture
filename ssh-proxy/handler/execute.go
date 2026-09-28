package handler

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
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
	Channel  string        `json:"channel"`
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

	// Default channel to "commands".
	if req.Channel == "" {
		req.Channel = pool.DefaultChannel
	}

	// Validate channel name against configured channels.
	if !h.IsValidChannel(req.Channel) {
		writeJSON(w, http.StatusBadRequest, map[string]string{
			"error": fmt.Sprintf("Invalid channel: %s", req.Channel),
		})
		return
	}

	log := h.logger.With(
		"hostname", req.Hostname,
		"channel", req.Channel,
		"request_id", requestID,
	)

	// Acquire a pool slot for this hostname+channel.
	entry, isNew, err := h.pool.Acquire(req.Hostname, req.Channel)
	if err != nil {
		if errors.Is(err, pool.ErrHostLocked) {
			log.Warn("host is locked")
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
		conn, connErr := h.connect(r.Context(), log, req, "SSH connection failed")
		if connErr != nil {
			// Clean up the placeholder entry.
			h.pool.Remove(req.Hostname, req.Channel)
			writeConnectError(w, connErr)
			return
		}
		h.pool.SetConnection(req.Hostname, req.Channel, conn)
		session = conn
	} else {
		// Reuse existing connection — the entry.Conn is an io.Closer,
		// but we know it's also an ssh.Session.
		var ok bool
		session, ok = entry.Conn.(ssh.Session)
		if !ok || session == nil {
			log.Error("pooled connection is not a valid session")
			h.pool.Remove(req.Hostname, req.Channel)
			writeJSON(w, http.StatusInternalServerError, executeResponse{
				Success: false,
				Output:  make([]ssh.CommandOutput, 0),
				Error:   "SSH connection failed: pooled connection invalid",
			})
			return
		}
		log.Debug("reusing pooled connection")
	}

	// Execute commands — always release the lock afterwards.
	defer h.pool.Release(req.Hostname, req.Channel)

	log.Info("executing commands",
		"command_count", len(req.Commands),
		"commands_summary", commandsSummary(req.Commands),
	)

	execStart := time.Now()
	result := h.executor.Execute(session, req.Commands)
	execDuration := time.Since(execStart)

	// If the command failed with a connection error on a reused (pooled)
	// connection, attempt stale recovery: evict, reconnect, retry once.
	if !result.Success && !isNew && isConnectionError(result.Error) {
		log.Warn("stale connection detected, retrying with new connection",
			"original_error", result.Error,
		)

		h.pool.Remove(req.Hostname, req.Channel)

		retryConn, retryErr := h.connect(r.Context(), log, req, "retry reconnection failed")
		if retryErr != nil {
			writeConnectError(w, retryErr)
			return
		}

		// Re-acquire a pool slot, store the new connection, and retry.
		if _, _, acquireErr := h.pool.Acquire(req.Hostname, req.Channel); acquireErr != nil {
			retryConn.Close()
			log.Error("retry pool acquire failed", "error", acquireErr)
			writeJSON(w, http.StatusInternalServerError, executeResponse{
				Success: false,
				Output:  make([]ssh.CommandOutput, 0),
				Error:   fmt.Sprintf("Pool error: %s", acquireErr),
			})
			return
		}
		h.pool.SetConnection(req.Hostname, req.Channel, retryConn)

		log.Info("retrying commands after reconnection")

		execStart = time.Now()
		result = h.executor.Execute(retryConn, req.Commands)
		execDuration = time.Since(execStart)

		if result.Success {
			log.Info("retry succeeded after stale connection recovery",
				"duration_ms", execDuration.Milliseconds(),
			)
		} else {
			log.Error("retry failed after stale connection recovery",
				"error", result.Error,
				"duration_ms", execDuration.Milliseconds(),
			)
		}

		writeJSON(w, http.StatusOK, executeResponse{
			Success: result.Success,
			Output:  result.Output,
			Error:   result.Error,
		})
		return
	}

	logAttrs := []any{
		"success", result.Success,
		"duration_ms", execDuration.Milliseconds(),
	}
	if result.Error != "" {
		logAttrs = append(logAttrs, "error", result.Error)
	}
	log.Info("commands executed", logAttrs...)

	writeJSON(w, http.StatusOK, executeResponse{
		Success: result.Success,
		Output:  result.Output,
		Error:   result.Error,
	})
}

// connect dials the switch within the connect timeout, logging the attempt
// and, on failure, the error under failMsg.
func (h *Handler) connect(ctx context.Context, log *slog.Logger, req executeRequest, failMsg string) (ssh.Session, error) {
	ctx, cancel := context.WithTimeout(ctx, h.connectTimeout)
	defer cancel()

	log.Info("connecting to host", "port", req.Port)
	conn, err := h.connector(ctx, req.Hostname, req.Port, req.Username, req.Password)
	if err != nil {
		log.Error(failMsg, "port", req.Port, "error", err)
		return nil, err
	}
	log.Info("connected to host", "port", req.Port)
	return conn, nil
}

func writeConnectError(w http.ResponseWriter, err error) {
	writeJSON(w, http.StatusInternalServerError, executeResponse{
		Success: false,
		Output:  make([]ssh.CommandOutput, 0),
		Error:   fmt.Sprintf("SSH connection failed: %s", err),
	})
}

// NotFound handles unmatched routes.
func (h *Handler) NotFound(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusNotFound, map[string]string{"error": "Not found"})
}

// isConnectionError returns true if the error string indicates a broken
// or stale SSH connection (as opposed to a command timeout or expect
// pattern mismatch). These errors warrant a retry with a fresh connection.
func isConnectionError(errMsg string) bool {
	connectionPatterns := []string{
		"connection closed",
		"connection reset",
		"broken pipe",
		"EOF",
		"use of closed network connection",
		"i/o timeout",
	}
	for _, pattern := range connectionPatterns {
		if strings.Contains(errMsg, pattern) {
			return true
		}
	}
	return false
}

func commandsSummary(commands []ssh.Command) []string {
	summary := make([]string, 0, len(commands))
	for _, cmd := range commands {
		summary = append(summary, ssh.SanitizeForLog(cmd.Command))
	}
	return summary
}
