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

func writeConnectFailure(w http.ResponseWriter, err error) {
	status, code := connectFailure(err)
	writeJSON(w, status, executeResponse{
		Success:   false,
		Output:    make([]ssh.CommandOutput, 0),
		Error:     fmt.Sprintf("SSH connection failed: %s", err),
		ErrorCode: code,
	})
}

type executeResponse struct {
	Success   bool                `json:"success"`
	Output    []ssh.CommandOutput `json:"output"`
	Error     string              `json:"error,omitempty"`
	HostKey   string              `json:"host_key,omitempty"`
	ErrorCode string              `json:"error_code,omitempty"`
}

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

	if req.Hostname == "" || req.Username == "" || req.Commands == nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{
			"error": "Missing required fields: hostname, username, commands",
		})
		return
	}

	if req.Port == 0 {
		req.Port = 22
	}

	if req.Channel == "" {
		req.Channel = pool.DefaultChannel
	}

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
		h.pool.Remove(key)
		entry, isNew, err = h.pool.Acquire(key)
	}
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

	var session ssh.Session
	var observedKey string
	if isNew {
		conn, connErr := h.connect(r.Context(), log, params, "SSH connection failed")
		if connErr != nil {
			h.pool.Remove(key)
			writeConnectFailure(w, connErr)
			return
		}
		h.pool.SetConnection(key, conn)
		observedKey = hostKeyOf(conn)
		h.pool.SetHostKey(key, observedKey)
		session = conn
	} else {
		var ok bool
		session, ok = entry.Conn.(ssh.Session)
		if !ok || session == nil {
			log.Error("pooled connection is not a valid session")
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
		log.Debug("reusing pooled connection")
	}

	defer h.pool.Release(key)

	log.Info("executing commands",
		"command_count", len(req.Commands),
		"commands_summary", commandsSummary(req.Commands),
	)

	execStart := time.Now()
	result := h.executor.Execute(session, req.Commands)
	execDuration := time.Since(execStart)

	if !result.Success && !isNew && isConnectionError(result.Error) {
		log.Warn("stale connection detected, retrying with new connection",
			"original_error", result.Error,
		)

		h.pool.Remove(key)

		retryConn, retryErr := h.connect(r.Context(), log, params, "retry reconnection failed")
		if retryErr != nil {
			writeConnectFailure(w, retryErr)
			return
		}

		if _, _, acquireErr := h.pool.Acquire(key); acquireErr != nil {
			retryConn.Close()
			log.Error("retry pool acquire failed", "error", acquireErr)
			writeJSON(w, http.StatusInternalServerError, executeResponse{
				Success: false,
				Output:  make([]ssh.CommandOutput, 0),
				Error:   fmt.Sprintf("Pool error: %s", acquireErr),
			})
			return
		}
		h.pool.SetConnection(key, retryConn)
		observedKey = hostKeyOf(retryConn)
		h.pool.SetHostKey(key, observedKey)

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
			HostKey: observedKey,
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
		HostKey: observedKey,
	})
}

func (h *Handler) connect(ctx context.Context, log *slog.Logger, params ssh.ConnectParams, failMsg string) (ssh.Session, error) {
	ctx, cancel := context.WithTimeout(ctx, h.connectTimeout)
	defer cancel()

	log.Info("connecting to host", "port", params.Port)
	conn, err := h.connector(ctx, params)
	if err != nil {
		log.Error(failMsg, "port", params.Port, "error", err)
		return nil, err
	}
	log.Info("connected to host", "port", params.Port)
	return conn, nil
}

func (h *Handler) NotFound(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusNotFound, map[string]string{"error": "Not found"})
}

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
