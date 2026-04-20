// Package config loads SSH proxy configuration from environment variables.
package config

import (
	"log/slog"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config holds all runtime configuration for the SSH proxy service.
type Config struct {
	APIKey         string
	ListenAddr     string
	IdleTimeout    time.Duration
	SweepInterval  time.Duration
	CommandTimeout time.Duration
	ReadTimeout    time.Duration
	ConnectTimeout time.Duration
	LogLevel       string
}

// Load reads configuration from environment variables, applying defaults
// for any values not set.
func Load() Config {
	return Config{
		APIKey:         getAPIKeyEnv(),
		ListenAddr:     getEnv("SSH_PROXY_LISTEN_ADDR", "0.0.0.0:8022"),
		IdleTimeout:    getDurationEnv("SSH_PROXY_IDLE_TIMEOUT", 600),
		SweepInterval:  getDurationEnv("SSH_PROXY_SWEEP_INTERVAL", 60),
		CommandTimeout: getDurationEnv("SSH_PROXY_COMMAND_TIMEOUT", 30),
		ReadTimeout:    getDurationEnv("SSH_PROXY_READ_TIMEOUT", 5),
		ConnectTimeout: getDurationEnv("SSH_PROXY_CONNECT_TIMEOUT", 10),
		LogLevel:       strings.ToLower(getEnv("SSH_PROXY_LOG_LEVEL", "info")),
	}
}

func getAPIKeyEnv() string {
	if val, ok := os.LookupEnv("SSH_PROXY_API_KEY"); ok {
		return val
	}
	return getEnv("APERTURE_SSH_PROXY_API_KEY", "")
}

// SlogLevel converts the string log level to a slog.Level.
func (c Config) SlogLevel() slog.Level {
	switch c.LogLevel {
	case "debug":
		return slog.LevelDebug
	case "warn":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}

func getEnv(key, defaultVal string) string {
	if val := os.Getenv(key); val != "" {
		return val
	}
	return defaultVal
}

func getDurationEnv(key string, defaultSeconds int) time.Duration {
	val := os.Getenv(key)
	if val == "" {
		return time.Duration(defaultSeconds) * time.Second
	}
	seconds, err := strconv.Atoi(val)
	if err != nil {
		return time.Duration(defaultSeconds) * time.Second
	}
	return time.Duration(seconds) * time.Second
}
