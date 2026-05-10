// Package server provides the HTTP server with routing, authentication
// middleware, and request logging for the SSH proxy API.
package server

import (
	"crypto/subtle"
	"encoding/json"
	"log/slog"
	"net/http"
	"sshproxy/handler"
	"strings"
	"time"
)

// New creates an http.Server wired with routes, auth, and logging middleware.
func New(addr string, apiKey string, h *handler.Handler, logger *slog.Logger) *http.Server {
	mux := http.NewServeMux()

	// Health check — no authentication required.
	mux.HandleFunc("GET /health", h.Health)

	// Authenticated routes.
	mux.HandleFunc("POST /execute", authMiddleware(apiKey, logger, h.Execute))
	mux.HandleFunc("GET /status", authMiddleware(apiKey, logger, h.Status))

	// Catch-all for unmatched routes.
	mux.HandleFunc("/", authMiddleware(apiKey, logger, h.NotFound))

	return &http.Server{
		Addr:              addr,
		Handler:           requestLogger(logger, mux),
		ReadHeaderTimeout: 10 * time.Second,
	}
}

// authMiddleware validates the Bearer token on incoming requests.
func authMiddleware(apiKey string, logger *slog.Logger, next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		authHeader := r.Header.Get("Authorization")
		token := strings.TrimPrefix(authHeader, "Bearer ")

		if token == "" || token == authHeader || subtle.ConstantTimeCompare([]byte(token), []byte(apiKey)) != 1 {
			requestID := handler.RequestIDFromContext(r.Context())
			logger.Warn("unauthorized request",
				"method", r.Method,
				"path", r.URL.Path,
				"remote_addr", r.RemoteAddr,
				"request_id", requestID,
			)
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusUnauthorized)
			json.NewEncoder(w).Encode(map[string]string{"error": "Unauthorized"}) //nolint:errcheck
			return
		}

		next(w, r)
	}
}

// responseWriter wraps http.ResponseWriter to capture the status code.
type responseWriter struct {
	http.ResponseWriter
	statusCode int
}

func (rw *responseWriter) WriteHeader(code int) {
	rw.statusCode = code
	rw.ResponseWriter.WriteHeader(code)
}

// requestLogger logs every HTTP request with method, path, status, duration,
// and a unique request ID for correlation.
func requestLogger(logger *slog.Logger, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		requestID := handler.GenerateRequestID()

		// Store request ID in context for downstream handlers.
		ctx := handler.ContextWithRequestID(r.Context(), requestID)
		r = r.WithContext(ctx)

		rw := &responseWriter{ResponseWriter: w, statusCode: http.StatusOK}

		next.ServeHTTP(rw, r)

		duration := time.Since(start)
		logger.Info("request",
			"method", r.Method,
			"path", r.URL.Path,
			"status", rw.statusCode,
			"duration_ms", duration.Milliseconds(),
			"remote_addr", r.RemoteAddr,
			"request_id", requestID,
		)
	})
}
