package handler

import (
	"net/http"
	"time"
)

// healthResponse is the JSON shape for the health check endpoint.
type healthResponse struct {
	Status        string `json:"status"`
	Version       string `json:"version"`
	UptimeSeconds int    `json:"uptime_seconds"`
}

// Health handles GET /health — returns a simple status check.
// No authentication required.
func (h *Handler) Health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, healthResponse{
		Status:        "ok",
		Version:       Version,
		UptimeSeconds: int(time.Since(h.startTime).Seconds()),
	})
}
