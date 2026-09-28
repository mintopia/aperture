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
	Hostname   string        `json:"hostname"`
	Username   string        `json:"username"`
	Password   string        `json:"password"`
	PrivateKey string        `json:"private_key"`
	Passphrase string        `json:"passphrase"`
	HostKey    string        `json:"host_key"`
	Commands   []ssh.Command `json:"commands"`
	Port       int           `json:"port"`
	Channel    string        `json:"channel"`
}

const (
	errCodeHostKeyMismatch   = "host_key_mismatch"
	errCodeInvalidPrivateKey = "invalid_private_key"
)

type hostKeyer interface{ HostKey() string }

func hostKeyOf(s ssh.Session) string {
	if hk, ok := s.(hostKeyer); ok {
		return hk.HostKey()
	}
	return ""
}

func connectFailure(err error) (int, string) {
	switch {
	case errors.Is(err, ssh.ErrHostKeyMismatch):
		return http.StatusInternalServerError, errCodeHostKeyMismatch
	case errors.Is(err, ssh.ErrInvalidPrivateKey):
		return http.StatusBadRequest, errCodeInvalidPrivateKey
	}
	return http.StatusInternalServerError, ""
}

func (h *Handler) writeConnectFailure(w http.ResponseWriter, err error) {
	status, code := connectFailure(err)
	writeJSON(w, status, executeResponse{
		Success:   false,
		Output:    make([]ssh.CommandOutput, 0),
		Error:     fmt.Sprintf("SSH connection failed: %s", err),
		ErrorCode: code,
	})
}

// executeResponse matches the PHP API's success response shape.
type executeResponse struct {
	Success bool                `json:"success"`
	Output  []ssh.CommandOutput `json:"output"`
	Error   string              `json:"error,omitempty"`
	// HostKey is the observed server key (authorized_keys format) for the PHP app to pin.
	HostKey   string `json:"host_key,omitempty"`
	ErrorCode string `json:"error_code,omitempty"`
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

	key := pool.NewKey(req.Hostname, req.Port, req.Username, req.Channel, req.Password, req.PrivateKey, req.Passphrase)
	params := ssh.ConnectParams{
		Hostname:   req.Hostname,
		Port:       req.Port,
		Username:   req.Username,
		Password:   req.Password,
		PrivateKey: req.PrivateKey,
		Passphrase: req.Passphrase,
		HostKey:    req.HostKey,
	}

	entry, isNew, err := h.pool.Acquire(key)
	if err == nil && !isNew && entry.HostKey != "" && (req.HostKey == "" || !ssh.HostKeysEqual(req.HostKey, entry.HostKey)) {
		// Pin cleared or changed (e.g. admin reset); reconnect so the reported key is fresh.
		h.pool.Evict(key)
		entry, isNew, err = h.pool.Acquire(key)
	}
	if err != nil {
		if errors.Is(err, pool.ErrHostLocked) {
			h.logger.Warn("host is locked",
				"hostname", req.Hostname,
				"channel", req.Channel,
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
	var observedKey string
	if isNew {
		ctx, cancel := context.WithTimeout(r.Context(), h.connectTimeout)
		defer cancel()

		h.logger.Info("connecting to host",
			"hostname", req.Hostname,
			"port", req.Port,
			"channel", req.Channel,
			"request_id", requestID,
		)

		conn, connErr := h.connector(ctx, params)
		if connErr != nil {
			h.logger.Error("SSH connection failed",
				"hostname", req.Hostname,
				"port", req.Port,
				"channel", req.Channel,
				"error", connErr,
				"request_id", requestID,
			)
			// Clean up the placeholder entry.
			h.pool.Remove(key)
			h.writeConnectFailure(w, connErr)
			return
		}

		h.pool.SetConnection(key, conn)
		observedKey = hostKeyOf(conn)
		h.pool.SetHostKey(key, observedKey)
		session = conn
		h.logger.Info("connected to host",
			"hostname", req.Hostname,
			"port", req.Port,
			"channel", req.Channel,
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
				"channel", req.Channel,
				"request_id", requestID,
			)
			h.pool.Remove(key)
			writeJSON(w, http.StatusInternalServerError, executeResponse{
				Success: false,
				Output:  make([]ssh.CommandOutput, 0),
				Error:   "SSH connection failed: pooled connection invalid",
			})
			return
		}
		observedKey = entry.HostKey
		if observedKey == "" {
			observedKey = hostKeyOf(session)
		}
		h.logger.Debug("reusing pooled connection",
			"hostname", req.Hostname,
			"channel", req.Channel,
			"request_id", requestID,
		)
	}

	// Execute commands — always release the lock afterwards.
	defer h.pool.Release(key)

	h.logger.Info("executing commands",
		"hostname", req.Hostname,
		"channel", req.Channel,
		"command_count", len(req.Commands),
		"commands_summary", commandsSummary(req.Commands),
		"request_id", requestID,
	)

	execStart := time.Now()
	result := h.executor.Execute(session, req.Commands)
	execDuration := time.Since(execStart)

	// If the command failed with a connection error on a reused (pooled)
	// connection, attempt stale recovery: evict, reconnect, retry once.
	if !result.Success && !isNew && isConnectionError(result.Error) {
		h.logger.Warn("stale connection detected, retrying with new connection",
			"hostname", req.Hostname,
			"channel", req.Channel,
			"original_error", result.Error,
			"request_id", requestID,
		)

		// Evict the stale connection.
		h.pool.Evict(key)

		// Establish a new connection.
		retryCtx, retryCancel := context.WithTimeout(r.Context(), h.connectTimeout)
		defer retryCancel()

		retryConn, retryErr := h.connector(retryCtx, params)
		if retryErr != nil {
			h.logger.Error("retry reconnection failed",
				"hostname", req.Hostname,
				"channel", req.Channel,
				"error", retryErr,
				"request_id", requestID,
			)
			h.writeConnectFailure(w, retryErr)
			return
		}

		// Re-acquire a pool slot, store the new connection, and retry.
		retryEntry, _, acquireErr := h.pool.Acquire(key)
		if acquireErr != nil {
			retryConn.Close()
			h.logger.Error("retry pool acquire failed",
				"hostname", req.Hostname,
				"channel", req.Channel,
				"error", acquireErr,
				"request_id", requestID,
			)
			writeJSON(w, http.StatusInternalServerError, executeResponse{
				Success: false,
				Output:  make([]ssh.CommandOutput, 0),
				Error:   fmt.Sprintf("Pool error: %s", acquireErr),
			})
			return
		}
		_ = retryEntry // entry is created; set the connection on it
		h.pool.SetConnection(key, retryConn)
		observedKey = hostKeyOf(retryConn)
		h.pool.SetHostKey(key, observedKey)

		h.logger.Info("retrying commands after reconnection",
			"hostname", req.Hostname,
			"channel", req.Channel,
			"request_id", requestID,
		)

		execStart = time.Now()
		result = h.executor.Execute(retryConn, req.Commands)
		execDuration = time.Since(execStart)

		if result.Success {
			h.logger.Info("retry succeeded after stale connection recovery",
				"hostname", req.Hostname,
				"channel", req.Channel,
				"duration_ms", execDuration.Milliseconds(),
				"request_id", requestID,
			)
		} else {
			h.logger.Error("retry failed after stale connection recovery",
				"hostname", req.Hostname,
				"channel", req.Channel,
				"error", result.Error,
				"duration_ms", execDuration.Milliseconds(),
				"request_id", requestID,
			)
		}

		writeJSON(w, http.StatusOK, executeResponse{
			Success: result.Success,
			Output:  result.Output,
			Error:   result.Error,
			HostKey: observedKey,
		})
		return
	}

	// Build log attributes for the "commands executed" line.
	logAttrs := []any{
		"hostname", req.Hostname,
		"channel", req.Channel,
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
		HostKey: observedKey,
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
