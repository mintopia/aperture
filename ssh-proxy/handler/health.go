package handler

import (
	"net/http"
	"time"
)

type healthResponse struct {
	Status        string `json:"status"`
	Version       string `json:"version"`
	UptimeSeconds int    `json:"uptime_seconds"`
}

func (h *Handler) Health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, healthResponse{
		Status:        "ok",
		Version:       Version,
		UptimeSeconds: int(time.Since(h.startTime).Seconds()),
	})
}
