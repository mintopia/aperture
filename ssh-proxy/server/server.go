package server

import (
	"crypto/subtle"
	"log/slog"
	"net/http"
	"sshproxy/handler"
	"strings"
	"time"
)

func New(addr string, apiKey string, h *handler.Handler, logger *slog.Logger) *http.Server {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /health", h.Health)

	mux.HandleFunc("POST /execute", authMiddleware(apiKey, logger, h.Execute))
	mux.HandleFunc("GET /status", authMiddleware(apiKey, logger, h.Status))

	mux.HandleFunc("/", authMiddleware(apiKey, logger, h.NotFound))

	return &http.Server{
		Addr:              addr,
		Handler:           requestLogger(logger, mux),
		ReadHeaderTimeout: 10 * time.Second,
	}
}

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
			handler.WriteJSON(logger, w, http.StatusUnauthorized, map[string]string{"error": "Unauthorized"})
			return
		}

		next(w, r)
	}
}

type responseWriter struct {
	http.ResponseWriter
	statusCode int
}

func (rw *responseWriter) WriteHeader(code int) {
	rw.statusCode = code
	rw.ResponseWriter.WriteHeader(code)
}

func requestLogger(logger *slog.Logger, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		requestID := handler.GenerateRequestID()

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
