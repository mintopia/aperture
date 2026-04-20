package config

import (
	"log/slog"
	"os"
	"testing"
	"time"
)

func TestLoad_Defaults(t *testing.T) {
	// Clear any env vars that might interfere
	envVars := []string{
		"SSH_PROXY_API_KEY",
		"APERTURE_SSH_PROXY_API_KEY",
		"SSH_PROXY_LISTEN_ADDR",
		"SSH_PROXY_IDLE_TIMEOUT",
		"SSH_PROXY_SWEEP_INTERVAL",
		"SSH_PROXY_COMMAND_TIMEOUT",
		"SSH_PROXY_READ_TIMEOUT",
		"SSH_PROXY_CONNECT_TIMEOUT",
		"SSH_PROXY_LOG_LEVEL",
	}
	for _, v := range envVars {
		os.Unsetenv(v)
	}

	cfg := Load()

	if cfg.APIKey != "" {
		t.Errorf("expected empty API key, got %q", cfg.APIKey)
	}
	if cfg.ListenAddr != "0.0.0.0:8022" {
		t.Errorf("expected listen addr '0.0.0.0:8022', got %q", cfg.ListenAddr)
	}
	if cfg.IdleTimeout != 600*time.Second {
		t.Errorf("expected idle timeout 600s, got %v", cfg.IdleTimeout)
	}
	if cfg.SweepInterval != 60*time.Second {
		t.Errorf("expected sweep interval 60s, got %v", cfg.SweepInterval)
	}
	if cfg.CommandTimeout != 30*time.Second {
		t.Errorf("expected command timeout 30s, got %v", cfg.CommandTimeout)
	}
	if cfg.ReadTimeout != 5*time.Second {
		t.Errorf("expected read timeout 5s, got %v", cfg.ReadTimeout)
	}
	if cfg.ConnectTimeout != 10*time.Second {
		t.Errorf("expected connect timeout 10s, got %v", cfg.ConnectTimeout)
	}
	if cfg.LogLevel != "info" {
		t.Errorf("expected log level 'info', got %q", cfg.LogLevel)
	}
}

func TestLoad_EnvOverride(t *testing.T) {
	t.Setenv("SSH_PROXY_API_KEY", "test-key-123")
	t.Setenv("SSH_PROXY_LISTEN_ADDR", "127.0.0.1:9999")
	t.Setenv("SSH_PROXY_IDLE_TIMEOUT", "300")
	t.Setenv("SSH_PROXY_SWEEP_INTERVAL", "30")
	t.Setenv("SSH_PROXY_COMMAND_TIMEOUT", "60")
	t.Setenv("SSH_PROXY_READ_TIMEOUT", "10")
	t.Setenv("SSH_PROXY_CONNECT_TIMEOUT", "15")
	t.Setenv("SSH_PROXY_LOG_LEVEL", "DEBUG")

	cfg := Load()

	if cfg.APIKey != "test-key-123" {
		t.Errorf("expected API key 'test-key-123', got %q", cfg.APIKey)
	}
	if cfg.ListenAddr != "127.0.0.1:9999" {
		t.Errorf("expected listen addr '127.0.0.1:9999', got %q", cfg.ListenAddr)
	}
	if cfg.IdleTimeout != 300*time.Second {
		t.Errorf("expected idle timeout 300s, got %v", cfg.IdleTimeout)
	}
	if cfg.SweepInterval != 30*time.Second {
		t.Errorf("expected sweep interval 30s, got %v", cfg.SweepInterval)
	}
	if cfg.CommandTimeout != 60*time.Second {
		t.Errorf("expected command timeout 60s, got %v", cfg.CommandTimeout)
	}
	if cfg.ReadTimeout != 10*time.Second {
		t.Errorf("expected read timeout 10s, got %v", cfg.ReadTimeout)
	}
	if cfg.ConnectTimeout != 15*time.Second {
		t.Errorf("expected connect timeout 15s, got %v", cfg.ConnectTimeout)
	}
	if cfg.LogLevel != "debug" {
		t.Errorf("expected log level 'debug', got %q", cfg.LogLevel)
	}
}

func TestLoad_InvalidDuration(t *testing.T) {
	t.Setenv("SSH_PROXY_IDLE_TIMEOUT", "not-a-number")

	cfg := Load()

	// Should fall back to default
	if cfg.IdleTimeout != 600*time.Second {
		t.Errorf("expected default idle timeout 600s on invalid input, got %v", cfg.IdleTimeout)
	}
}

func TestLoad_APIKeyUsesApertureFallbackOnlyWhenPrimaryIsUnset(t *testing.T) {
	t.Setenv("APERTURE_SSH_PROXY_API_KEY", "shared-dev-key")
	_ = os.Unsetenv("SSH_PROXY_API_KEY")

	cfg := Load()

	if cfg.APIKey != "shared-dev-key" {
		t.Errorf("expected API key fallback from APERTURE_SSH_PROXY_API_KEY when SSH_PROXY_API_KEY is unset, got %q", cfg.APIKey)
	}
}

func TestLoad_APIKeyExplicitEmptyPrimaryDisablesFallback(t *testing.T) {
	t.Setenv("SSH_PROXY_API_KEY", "")
	t.Setenv("APERTURE_SSH_PROXY_API_KEY", "shared-dev-key")

	cfg := Load()

	if cfg.APIKey != "" {
		t.Errorf("expected explicit-empty SSH_PROXY_API_KEY to take precedence and keep API key empty, got %q", cfg.APIKey)
	}
}

func TestConfig_SlogLevel(t *testing.T) {
	tests := []struct {
		level    string
		expected slog.Level
	}{
		{"debug", slog.LevelDebug},
		{"info", slog.LevelInfo},
		{"warn", slog.LevelWarn},
		{"error", slog.LevelError},
		{"unknown", slog.LevelInfo},
		{"", slog.LevelInfo},
	}
	for _, tt := range tests {
		t.Run(tt.level, func(t *testing.T) {
			cfg := Config{LogLevel: tt.level}
			if got := cfg.SlogLevel(); got != tt.expected {
				t.Errorf("SlogLevel(%q) = %v, want %v", tt.level, got, tt.expected)
			}
		})
	}
}
