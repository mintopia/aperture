// SSH Proxy is an HTTP service that executes commands on network switches
// (Cisco IOS) over SSH, with connection pooling and per-host locking.
//
// It replaces the PHP-based SSH proxy in the Aperture project with a
// Go implementation for better concurrency and resource efficiency.
package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"sshproxy/config"
	"sshproxy/handler"
	"sshproxy/pool"
	"sshproxy/server"
	"sshproxy/ssh"
)

func main() {
	cfg := config.Load()

	// Set up structured JSON logging.
	logHandler := slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: cfg.SlogLevel(),
	})
	logger := slog.New(logHandler)
	slog.SetDefault(logger)

	// Create connection pool with keepalive support.
	connPool := pool.NewWithKeepalive(cfg.IdleTimeout, cfg.KeepaliveInterval)

	// Create SSH connector function.
	connector := func(ctx context.Context, hostname string, port int, username, password string) (ssh.Session, error) {
		return ssh.Connect(ctx, hostname, port, username, password)
	}

	// Create command executor.
	executor := &ssh.Executor{
		ReadTimeout:    cfg.ReadTimeout,
		CommandTimeout: cfg.CommandTimeout,
		Logger:         logger,
	}

	// Create HTTP handlers.
	h := handler.New(connPool, connector, executor, cfg.ConnectTimeout, logger)

	// Create HTTP server.
	srv := server.New(cfg.ListenAddr, cfg.APIKey, h, logger)

	// Set up graceful shutdown on SIGTERM/SIGINT.
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer cancel()

	// Start idle connection sweep goroutine.
	go sweepLoop(ctx, connPool, cfg.SweepInterval, logger)

	// Start HTTP server.
	go func() {
		logger.Info("SSH proxy starting",
			"addr", cfg.ListenAddr,
			"log_level", cfg.LogLevel,
			"idle_timeout", cfg.IdleTimeout.String(),
			"sweep_interval", cfg.SweepInterval.String(),
			"command_timeout", cfg.CommandTimeout.String(),
			"read_timeout", cfg.ReadTimeout.String(),
			"connect_timeout", cfg.ConnectTimeout.String(),
			"keepalive_interval", cfg.KeepaliveInterval.String(),
		)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Error("server error", "error", err)
			cancel()
		}
	}()

	// Block until shutdown signal.
	<-ctx.Done()
	logger.Info("shutting down SSH proxy...")

	// Graceful shutdown with timeout.
	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer shutdownCancel()

	if err := srv.Shutdown(shutdownCtx); err != nil {
		logger.Error("server shutdown error", "error", err)
	}

	connPool.DisconnectAll()
	logger.Info("SSH proxy stopped")
}

// sweepLoop periodically removes idle connections from the pool.
func sweepLoop(ctx context.Context, p *pool.Pool, interval time.Duration, logger *slog.Logger) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			removed := p.SweepIdle()
			if removed > 0 {
				logger.Info("swept idle connections", "count", removed)
			}
		}
	}
}
