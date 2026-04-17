// Package pool provides a thread-safe SSH connection pool keyed by hostname.
// Connections are acquired with exclusive locking per hostname and
// automatically swept when idle.
package pool

import (
	"errors"
	"io"
	"sync"
	"time"
)

// ErrHostLocked is returned when a hostname is already locked by another request.
var ErrHostLocked = errors.New("host is currently locked by another request")

// Entry represents a pooled connection for a single hostname.
type Entry struct {
	Hostname  string
	Conn      io.Closer
	CreatedAt time.Time
	LastUsed  time.Time
	Locked    bool
}

// ConnectionInfo describes a pooled connection's current state for status reporting.
type ConnectionInfo struct {
	Hostname           string `json:"hostname"`
	ConnectedSeconds   int    `json:"connected_seconds"`
	LastUsedSecondsAgo int    `json:"last_used_seconds_ago"`
	Locked             bool   `json:"locked"`
}

// Pool manages SSH connections keyed by hostname with mutual exclusion.
// All methods are safe for concurrent use.
type Pool struct {
	mu          sync.Mutex
	entries     map[string]*Entry
	idleTimeout time.Duration
	startedAt   time.Time
}

// New creates a connection pool that evicts idle connections after idleTimeout.
func New(idleTimeout time.Duration) *Pool {
	return &Pool{
		entries:     make(map[string]*Entry),
		idleTimeout: idleTimeout,
		startedAt:   time.Now(),
	}
}

// Acquire obtains or creates a pool entry for the given hostname.
// If the hostname is already locked, returns ErrHostLocked.
// Returns the entry, whether it is new (needs a connection), and any error.
// The returned entry is always locked — the caller must call Release when done.
func (p *Pool) Acquire(hostname string) (*Entry, bool, error) {
	p.mu.Lock()
	defer p.mu.Unlock()

	entry, exists := p.entries[hostname]
	if exists {
		if entry.Locked {
			return nil, false, ErrHostLocked
		}
		entry.Locked = true
		entry.LastUsed = time.Now()
		return entry, false, nil
	}

	// Create a placeholder entry — the caller will establish the SSH connection.
	entry = &Entry{
		Hostname:  hostname,
		Locked:    true,
		CreatedAt: time.Now(),
		LastUsed:  time.Now(),
	}
	p.entries[hostname] = entry
	return entry, true, nil
}

// Release unlocks the entry for the given hostname.
func (p *Pool) Release(hostname string) {
	p.mu.Lock()
	defer p.mu.Unlock()

	if entry, ok := p.entries[hostname]; ok {
		entry.Locked = false
	}
}

// SetConnection stores the SSH connection on an existing entry.
func (p *Pool) SetConnection(hostname string, conn io.Closer) {
	p.mu.Lock()
	defer p.mu.Unlock()

	if entry, ok := p.entries[hostname]; ok {
		entry.Conn = conn
	}
}

// Remove closes and removes the entry for the given hostname.
func (p *Pool) Remove(hostname string) {
	p.mu.Lock()
	defer p.mu.Unlock()

	if entry, ok := p.entries[hostname]; ok {
		if entry.Conn != nil {
			entry.Conn.Close()
		}
		delete(p.entries, hostname)
	}
}

// SweepIdle removes and closes all unlocked connections that have been
// idle longer than the pool's idle timeout. Returns the number removed.
func (p *Pool) SweepIdle() int {
	p.mu.Lock()
	defer p.mu.Unlock()

	removed := 0
	now := time.Now()
	for hostname, entry := range p.entries {
		if entry.Locked {
			continue
		}
		if now.Sub(entry.LastUsed) >= p.idleTimeout {
			if entry.Conn != nil {
				entry.Conn.Close()
			}
			delete(p.entries, hostname)
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
			ConnectedSeconds:   int(now.Sub(entry.CreatedAt).Seconds()),
			LastUsedSecondsAgo: int(now.Sub(entry.LastUsed).Seconds()),
			Locked:             entry.Locked,
		})
	}
	return uptime, infos
}

// DisconnectAll closes and removes all pooled connections.
func (p *Pool) DisconnectAll() {
	p.mu.Lock()
	defer p.mu.Unlock()

	for hostname, entry := range p.entries {
		if entry.Conn != nil {
			entry.Conn.Close()
		}
		delete(p.entries, hostname)
	}
}
