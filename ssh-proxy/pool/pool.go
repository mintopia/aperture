// Package pool provides a thread-safe SSH connection pool keyed by
// hostname and channel. Connections are acquired with exclusive locking
// per hostname+channel pair and automatically swept when idle or dead.
package pool

import (
	"errors"
	"fmt"
	"io"
	"sync"
	"time"
)

// DefaultChannel is the channel used when no channel is specified.
const DefaultChannel = "commands"

// ErrHostLocked is returned when a hostname+channel is already locked by another request.
var ErrHostLocked = errors.New("host is currently locked by another request")

// KeepaliveChecker is implemented by connections that support SSH keepalive.
// The pool sends periodic keepalive requests to detect dead connections.
type KeepaliveChecker interface {
	SendKeepalive() error
}

// Entry represents a pooled connection for a single hostname+channel pair.
type Entry struct {
	Hostname      string
	Channel       string
	Conn          io.Closer
	CreatedAt     time.Time
	LastUsed      time.Time
	Locked        bool
	Dead          bool
	keepaliveStop chan struct{}
}

// ConnectionInfo describes a pooled connection's current state for status reporting.
type ConnectionInfo struct {
	Hostname           string `json:"hostname"`
	Channel            string `json:"channel"`
	ConnectedSeconds   int    `json:"connected_seconds"`
	LastUsedSecondsAgo int    `json:"last_used_seconds_ago"`
	Locked             bool   `json:"locked"`
}

// Pool manages SSH connections keyed by hostname+channel with mutual exclusion.
// All methods are safe for concurrent use.
type Pool struct {
	mu                sync.Mutex
	entries           map[string]*Entry
	idleTimeout       time.Duration
	keepaliveInterval time.Duration
	startedAt         time.Time
}

// PoolKey returns the composite key for a hostname+channel pair.
func PoolKey(hostname, channel string) string {
	return fmt.Sprintf("%s:%s", hostname, channel)
}

// New creates a connection pool that evicts idle connections after idleTimeout.
// No keepalive is configured; use NewWithKeepalive for keepalive support.
func New(idleTimeout time.Duration) *Pool {
	return &Pool{
		entries:     make(map[string]*Entry),
		idleTimeout: idleTimeout,
		startedAt:   time.Now(),
	}
}

// NewWithKeepalive creates a connection pool with periodic SSH keepalive.
// Connections that implement KeepaliveChecker will receive keepalive requests
// at the specified interval. If a keepalive fails, the connection is marked
// dead and will be evicted on the next Acquire or SweepIdle call.
func NewWithKeepalive(idleTimeout, keepaliveInterval time.Duration) *Pool {
	return &Pool{
		entries:           make(map[string]*Entry),
		idleTimeout:       idleTimeout,
		keepaliveInterval: keepaliveInterval,
		startedAt:         time.Now(),
	}
}

// Acquire obtains or creates a pool entry for the given hostname and channel.
// If the hostname+channel is already locked, returns ErrHostLocked.
// Dead entries are evicted and treated as new (requiring a fresh connection).
// Returns the entry, whether it is new (needs a connection), and any error.
// The returned entry is always locked — the caller must call Release when done.
func (p *Pool) Acquire(hostname, channel string) (*Entry, bool, error) {
	p.mu.Lock()
	defer p.mu.Unlock()

	key := PoolKey(hostname, channel)
	entry, exists := p.entries[key]
	if exists {
		if entry.Dead {
			p.stopKeepaliveLocked(entry)
			if entry.Conn != nil {
				entry.Conn.Close()
			}
			delete(p.entries, key)
		} else {
			if entry.Locked {
				return nil, false, ErrHostLocked
			}
			entry.Locked = true
			entry.LastUsed = time.Now()
			return entry, false, nil
		}
	}

	entry = &Entry{
		Hostname:  hostname,
		Channel:   channel,
		Locked:    true,
		CreatedAt: time.Now(),
		LastUsed:  time.Now(),
	}
	p.entries[key] = entry
	return entry, true, nil
}

// Release unlocks the entry for the given hostname and channel.
func (p *Pool) Release(hostname, channel string) {
	p.mu.Lock()
	defer p.mu.Unlock()

	key := PoolKey(hostname, channel)
	if entry, ok := p.entries[key]; ok {
		entry.Locked = false
	}
}

// SetConnection stores the SSH connection on an existing entry.
// If the pool has a keepalive interval configured and the connection
// implements KeepaliveChecker, a keepalive goroutine is started.
func (p *Pool) SetConnection(hostname, channel string, conn io.Closer) {
	p.mu.Lock()
	defer p.mu.Unlock()

	key := PoolKey(hostname, channel)
	entry, ok := p.entries[key]
	if !ok {
		return
	}

	entry.Conn = conn

	if p.keepaliveInterval > 0 {
		if checker, ok := conn.(KeepaliveChecker); ok {
			stopCh := make(chan struct{})
			entry.keepaliveStop = stopCh
			go p.keepaliveLoop(key, checker, stopCh)
		}
	}
}

// Remove closes and removes the entry for the given hostname and channel,
// stopping its keepalive goroutine if one is running.
func (p *Pool) Remove(hostname, channel string) {
	p.mu.Lock()
	defer p.mu.Unlock()

	key := PoolKey(hostname, channel)
	if entry, ok := p.entries[key]; ok {
		p.stopKeepaliveLocked(entry)
		if entry.Conn != nil {
			entry.Conn.Close()
		}
		delete(p.entries, key)
	}
}

// SweepIdle removes and closes all unlocked connections that have been
// idle longer than the pool's idle timeout, as well as any connections
// marked dead by a failed keepalive. Returns the number removed.
func (p *Pool) SweepIdle() int {
	p.mu.Lock()
	defer p.mu.Unlock()

	removed := 0
	now := time.Now()
	for key, entry := range p.entries {
		if entry.Locked {
			continue
		}
		shouldRemove := entry.Dead || now.Sub(entry.LastUsed) >= p.idleTimeout
		if shouldRemove {
			p.stopKeepaliveLocked(entry)
			if entry.Conn != nil {
				entry.Conn.Close()
			}
			delete(p.entries, key)
			removed++
		}
	}
	return removed
}

// Status returns the server uptime in seconds and info about all pooled connections.
func (p *Pool) Status() (int, []ConnectionInfo) {
	p.mu.Lock()
	defer p.mu.Unlock()

	uptime := int(time.Since(p.startedAt).Seconds())
	infos := make([]ConnectionInfo, 0, len(p.entries))
	now := time.Now()
	for _, entry := range p.entries {
		infos = append(infos, ConnectionInfo{
			Hostname:           entry.Hostname,
			Channel:            entry.Channel,
			ConnectedSeconds:   int(now.Sub(entry.CreatedAt).Seconds()),
			LastUsedSecondsAgo: int(now.Sub(entry.LastUsed).Seconds()),
			Locked:             entry.Locked,
		})
	}
	return uptime, infos
}

// DisconnectAll closes and removes all pooled connections,
// stopping all keepalive goroutines.
func (p *Pool) DisconnectAll() {
	p.mu.Lock()
	defer p.mu.Unlock()

	for key, entry := range p.entries {
		p.stopKeepaliveLocked(entry)
		if entry.Conn != nil {
			entry.Conn.Close()
		}
		delete(p.entries, key)
	}
}

// stopKeepaliveLocked signals the keepalive goroutine for an entry to stop.
// Must be called with p.mu held.
func (p *Pool) stopKeepaliveLocked(entry *Entry) {
	if entry.keepaliveStop != nil {
		close(entry.keepaliveStop)
		entry.keepaliveStop = nil
	}
}

// keepaliveLoop sends periodic keepalive requests to detect dead connections.
// It marks the entry as Dead if a keepalive fails.
func (p *Pool) keepaliveLoop(key string, checker KeepaliveChecker, stopCh chan struct{}) {
	ticker := time.NewTicker(p.keepaliveInterval)
	defer ticker.Stop()

	for {
		select {
		case <-stopCh:
			return
		case <-ticker.C:
			if err := checker.SendKeepalive(); err != nil {
				p.mu.Lock()
				if entry, ok := p.entries[key]; ok {
					entry.Dead = true
				}
				p.mu.Unlock()
				return
			}
		}
	}
}
