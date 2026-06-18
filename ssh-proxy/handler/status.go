package handler

import (
	"net/http"
	"sshproxy/pool"
)

// statusResponse matches the PHP API's /status JSON shape.
type statusResponse struct {
	UptimeSeconds int                   `json:"uptime_seconds"`
	Connections   []pool.ConnectionInfo `json:"connections"`
}

// Status handles GET /status — returns pool uptime and connection info.
func (h *Handler) Status(w http.ResponseWriter, _ *http.Request) {
	uptime, connections := h.pool.Status()
	writeJSON(w, http.StatusOK, statusResponse{
		UptimeSeconds: uptime,
		Connections:   connections,
	})
}
