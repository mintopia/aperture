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

	logHandler := slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: cfg.SlogLevel(),
	})
	logger := slog.New(logHandler)
	slog.SetDefault(logger)

	connPool := pool.New(cfg.IdleTimeout, cfg.KeepaliveInterval)

	connector := func(ctx context.Context, params ssh.ConnectParams) (ssh.Session, error) {
		return ssh.Connect(ctx, params)
	}

	executor := &ssh.Executor{
		ReadTimeout:    cfg.ReadTimeout,
		CommandTimeout: cfg.CommandTimeout,
		Logger:         logger,
	}

	h := handler.New(connPool, connector, executor, cfg.ConnectTimeout, logger, cfg.Channels)

	srv := server.New(cfg.ListenAddr, cfg.APIKey, h, logger)

	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer cancel()

	go sweepLoop(ctx, connPool, cfg.SweepInterval, logger)

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
			"channels", cfg.Channels,
		)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Error("server error", "error", err)
			cancel()
		}
	}()

	<-ctx.Done()
	logger.Info("shutting down SSH proxy...")

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer shutdownCancel()

	if err := srv.Shutdown(shutdownCtx); err != nil {
		logger.Error("server shutdown error", "error", err)
	}

	connPool.DisconnectAll()
	logger.Info("SSH proxy stopped")
}

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
