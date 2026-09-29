package pool

import (
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"io"
	"sync"
	"time"
)

const DefaultChannel = "commands"

var ErrHostLocked = errors.New("host is currently locked by another request")

type KeepaliveChecker interface {
	SendKeepalive() error
}

type Key struct {
	Hostname string
	Port     int
	Username string
	Channel  string
	credHash [sha256.Size]byte
}

func NewKey(hostname string, port int, username, channel string, creds ...string) Key {
	h := sha256.New()
	var n [8]byte
	for _, c := range creds {
		binary.BigEndian.PutUint64(n[:], uint64(len(c)))
		h.Write(n[:])
		h.Write([]byte(c))
	}
	k := Key{Hostname: hostname, Port: port, Username: username, Channel: channel}
	copy(k.credHash[:], h.Sum(nil))
	return k
}

type Entry struct {
	Hostname      string
	Port          int
	Username      string
	Channel       string
	HostKey       string
	Conn          io.Closer
	CreatedAt     time.Time
	LastUsed      time.Time
	Locked        bool
	Dead          bool
	keepaliveStop chan struct{}
}

type ConnectionInfo struct {
	Hostname           string `json:"hostname"`
	Port               int    `json:"port"`
	Username           string `json:"username"`
	Channel            string `json:"channel"`
	ConnectedSeconds   int    `json:"connected_seconds"`
	LastUsedSecondsAgo int    `json:"last_used_seconds_ago"`
	Locked             bool   `json:"locked"`
}

type Pool struct {
	mu                sync.Mutex
	entries           map[Key]*Entry
	idleTimeout       time.Duration
	keepaliveInterval time.Duration
	startedAt         time.Time
}

func New(idleTimeout, keepaliveInterval time.Duration) *Pool {
	return &Pool{
		entries:           make(map[Key]*Entry),
		idleTimeout:       idleTimeout,
		keepaliveInterval: keepaliveInterval,
		startedAt:         time.Now(),
	}
}

func (p *Pool) Acquire(key Key) (*Entry, bool, error) {
	p.mu.Lock()
	defer p.mu.Unlock()

	entry, exists := p.entries[key]
	if exists {
		if entry.Dead {
			p.removeLocked(key, entry)
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
		Hostname:  key.Hostname,
		Port:      key.Port,
		Username:  key.Username,
		Channel:   key.Channel,
		Locked:    true,
		CreatedAt: time.Now(),
		LastUsed:  time.Now(),
	}
	p.entries[key] = entry
	return entry, true, nil
}

func (p *Pool) Release(key Key) {
	p.mu.Lock()
	defer p.mu.Unlock()

	if entry, ok := p.entries[key]; ok {
		entry.Locked = false
	}
}

func (p *Pool) SetConnection(key Key, conn io.Closer) {
	p.mu.Lock()
	defer p.mu.Unlock()

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

func (p *Pool) SetHostKey(key Key, hostKey string) {
	p.mu.Lock()
	defer p.mu.Unlock()

	if entry, ok := p.entries[key]; ok {
		entry.HostKey = hostKey
	}
}

func (p *Pool) Remove(key Key) {
	p.mu.Lock()
	defer p.mu.Unlock()

	if entry, ok := p.entries[key]; ok {
		p.removeLocked(key, entry)
	}
}

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

func (p *Pool) Status() (int, []ConnectionInfo) {
	p.mu.Lock()
	defer p.mu.Unlock()

	uptime := int(time.Since(p.startedAt).Seconds())
	infos := make([]ConnectionInfo, 0, len(p.entries))
	now := time.Now()
	for _, entry := range p.entries {
		infos = append(infos, ConnectionInfo{
			Hostname:           entry.Hostname,
			Port:               entry.Port,
			Username:           entry.Username,
			Channel:            entry.Channel,
			ConnectedSeconds:   int(now.Sub(entry.CreatedAt).Seconds()),
			LastUsedSecondsAgo: int(now.Sub(entry.LastUsed).Seconds()),
			Locked:             entry.Locked,
		})
	}
	return uptime, infos
}

func (p *Pool) DisconnectAll() {
	p.mu.Lock()
	defer p.mu.Unlock()

	for key, entry := range p.entries {
		p.removeLocked(key, entry)
	}
}

func (p *Pool) removeLocked(key Key, entry *Entry) {
	p.stopKeepaliveLocked(entry)
	if entry.Conn != nil {
		entry.Conn.Close()
	}
	delete(p.entries, key)
}

func (p *Pool) stopKeepaliveLocked(entry *Entry) {
	if entry.keepaliveStop != nil {
		close(entry.keepaliveStop)
		entry.keepaliveStop = nil
	}
}

func (p *Pool) keepaliveLoop(key Key, checker KeepaliveChecker, stopCh chan struct{}) {
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
