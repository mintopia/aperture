package handler

import (
	"net/http"
	"sshproxy/pool"
)

type statusResponse struct {
	UptimeSeconds int                   `json:"uptime_seconds"`
	Connections   []pool.ConnectionInfo `json:"connections"`
}

func (h *Handler) Status(w http.ResponseWriter, _ *http.Request) {
	uptime, connections := h.pool.Status()
	writeJSON(w, http.StatusOK, statusResponse{
		UptimeSeconds: uptime,
		Connections:   connections,
	})
}
