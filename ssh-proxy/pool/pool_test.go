package pool

import (
	"encoding/json"
	"errors"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"sshproxy/ssh"
)

type mockCloser struct {
	closed bool
}

func (m *mockCloser) Close() error {
	m.closed = true
	return nil
}

func (m *mockCloser) Write(string) error { return nil }

func (m *mockCloser) Read(time.Duration) string { return "" }

func seed(t *testing.T, p *Pool, key Key, conn ssh.Session) {
	t.Helper()
	l, err := p.Acquire(key)
	if err != nil {
		t.Fatalf("seed acquire: %v", err)
	}
	l.SetConnection(conn)
	l.Release()
}

type mockKeepaliveConn struct {
	closed         bool
	keepaliveCount atomic.Int32
	failAfter      int32
	shouldFail     bool
}

func (m *mockKeepaliveConn) Close() error {
	m.closed = true
	return nil
}

func (m *mockKeepaliveConn) Write(string) error { return nil }

func (m *mockKeepaliveConn) Read(time.Duration) string { return "" }

func (m *mockKeepaliveConn) SendKeepalive() error {
	if m.shouldFail {
		return errors.New("keepalive failed: connection dead")
	}
	count := m.keepaliveCount.Add(1)
	if m.failAfter > 0 && count >= m.failAfter {
		return errors.New("keepalive failed: connection dead")
	}
	return nil
}

func TestPool_AcquireNew(t *testing.T) {
	p := New(10*time.Minute, 0)

	lease, err := p.Acquire(testKey("switch1.local", DefaultChannel))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !lease.IsNew() {
		t.Error("expected isNew to be true")
	}
	entry := lease.entry
	if !entry.Locked {
		t.Error("expected entry to be locked")
	}
	if entry.Hostname != "switch1.local" {
		t.Errorf("expected hostname 'switch1.local', got %q", entry.Hostname)
	}
	if entry.Channel != DefaultChannel {
		t.Errorf("expected channel %q, got %q", DefaultChannel, entry.Channel)
	}
}

func TestPool_AcquireExisting(t *testing.T) {
	p := New(10*time.Minute, 0)

	conn := &mockCloser{}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	lease, err := p.Acquire(testKey("switch1.local", DefaultChannel))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if lease.IsNew() {
		t.Error("expected isNew to be false")
	}
	if lease.Conn() != conn {
		t.Error("expected same connection to be reused")
	}
	if !lease.entry.Locked {
		t.Error("expected entry to be locked after acquire")
	}
}

func TestPool_AcquireLocked(t *testing.T) {
	p := New(10*time.Minute, 0)

	_, _ = p.Acquire(testKey("switch1.local", DefaultChannel))

	_, err := p.Acquire(testKey("switch1.local", DefaultChannel))
	if err != ErrHostLocked {
		t.Fatalf("expected ErrHostLocked, got %v", err)
	}
}

func TestPool_Release(t *testing.T) {
	p := New(10*time.Minute, 0)

	lease, _ := p.Acquire(testKey("switch1.local", DefaultChannel))
	lease.Release()

	_, err := p.Acquire(testKey("switch1.local", DefaultChannel))
	if err != nil {
		t.Fatalf("unexpected error after release: %v", err)
	}
}

func TestPool_LeaseReleaseIsIdempotentAndHeldLocks(t *testing.T) {
	p := New(10*time.Minute, 0)
	key := testKey("switch1.local", DefaultChannel)

	lease, err := p.Acquire(key)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := p.Acquire(key); !errors.Is(err, ErrHostLocked) {
		t.Fatalf("expected ErrHostLocked while held, got %v", err)
	}

	lease.Release()
	second, err := p.Acquire(key)
	if err != nil {
		t.Fatalf("expected acquire after release: %v", err)
	}

	lease.Release()
	if _, err := p.Acquire(key); !errors.Is(err, ErrHostLocked) {
		t.Fatalf("stale double release must not unlock a newer lease, got %v", err)
	}
	second.Release()
}

func TestPool_LeaseReleaseAfterRemoveDoesNotUnlockReplacement(t *testing.T) {
	p := New(10*time.Minute, 0)
	key := testKey("switch1.local", DefaultChannel)

	old, _ := p.Acquire(key)
	old.Remove()
	replacement, err := p.Acquire(key)
	if err != nil {
		t.Fatal(err)
	}
	old.Release()
	if _, err := p.Acquire(key); !errors.Is(err, ErrHostLocked) {
		t.Fatalf("expected replacement to stay locked, got %v", err)
	}
	replacement.Release()
}

func TestPool_LeaseSetConnectionClosesReplacedConn(t *testing.T) {
	p := New(10*time.Minute, 0)
	lease, _ := p.Acquire(testKey("switch1.local", DefaultChannel))
	first, second := &mockCloser{}, &mockCloser{}
	lease.SetConnection(first)
	lease.SetConnection(second)
	if !first.closed || second.closed {
		t.Errorf("expected only replaced conn closed: first=%v second=%v", first.closed, second.closed)
	}
	if lease.Conn() != second {
		t.Error("expected new conn installed")
	}
}

func TestPool_Remove(t *testing.T) {
	p := New(10*time.Minute, 0)

	conn := &mockCloser{}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	p.Remove(testKey("switch1.local", DefaultChannel))

	if !conn.closed {
		t.Error("expected connection to be closed on remove")
	}

	lease, err := p.Acquire(testKey("switch1.local", DefaultChannel))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !lease.IsNew() {
		t.Error("expected new entry after remove")
	}
}

func TestPool_RemoveNonExistent(t *testing.T) {
	p := New(10*time.Minute, 0)
	p.Remove(testKey("nonexistent", DefaultChannel))
}

func TestPool_SweepIdle(t *testing.T) {
	p := New(50*time.Millisecond, 0)

	conn := &mockCloser{}
	seed(t, p, testKey("idle-switch", DefaultChannel), conn)

	activeConn := &mockCloser{}
	seed(t, p, testKey("active-switch", DefaultChannel), activeConn)

	time.Sleep(100 * time.Millisecond)

	p.mu.Lock()
	key := testKey("active-switch", DefaultChannel)
	if entry, ok := p.entries[key]; ok {
		entry.LastUsed = time.Now()
	}
	p.mu.Unlock()

	removed := p.SweepIdle()
	if removed != 1 {
		t.Errorf("expected 1 removed, got %d", removed)
	}
	if !conn.closed {
		t.Error("expected idle connection to be closed")
	}
	if activeConn.closed {
		t.Error("expected active connection to NOT be closed")
	}
}

func TestPool_SweepSkipsLocked(t *testing.T) {
	p := New(1*time.Millisecond, 0)

	lease, _ := p.Acquire(testKey("locked-switch", DefaultChannel))
	conn := &mockCloser{}
	lease.SetConnection(conn)

	time.Sleep(10 * time.Millisecond)

	removed := p.SweepIdle()
	if removed != 0 {
		t.Errorf("expected 0 removed (locked), got %d", removed)
	}
	if conn.closed {
		t.Error("locked connection should not be closed by sweep")
	}
}

func TestPool_Status(t *testing.T) {
	p := New(10*time.Minute, 0)

	seed(t, p, testKey("switch1.local", DefaultChannel), &mockCloser{})

	uptime, infos := p.Status()

	if uptime < 0 {
		t.Errorf("expected non-negative uptime, got %d", uptime)
	}
	if len(infos) != 1 {
		t.Fatalf("expected 1 connection info, got %d", len(infos))
	}
	if infos[0].Hostname != "switch1.local" {
		t.Errorf("expected hostname 'switch1.local', got %q", infos[0].Hostname)
	}
	if infos[0].Channel != DefaultChannel {
		t.Errorf("expected channel %q, got %q", DefaultChannel, infos[0].Channel)
	}
	if infos[0].Locked {
		t.Error("expected connection to be unlocked")
	}
}

func TestPool_DisconnectAll(t *testing.T) {
	p := New(10*time.Minute, 0)

	conn1 := &mockCloser{}
	conn2 := &mockCloser{}

	seed(t, p, testKey("switch1", DefaultChannel), conn1)

	seed(t, p, testKey("switch2", DefaultChannel), conn2)

	p.DisconnectAll()

	if !conn1.closed {
		t.Error("expected conn1 to be closed")
	}
	if !conn2.closed {
		t.Error("expected conn2 to be closed")
	}

	_, infos := p.Status()
	if len(infos) != 0 {
		t.Errorf("expected 0 connections after disconnect all, got %d", len(infos))
	}
}

func TestPool_ConcurrentAccess(t *testing.T) {
	p := New(10*time.Minute, 0)
	const goroutines = 50

	var wg sync.WaitGroup
	wg.Add(goroutines)

	lockedCount := 0
	var lockedMu sync.Mutex

	for i := 0; i < goroutines; i++ {
		go func() {
			defer wg.Done()

			lease, err := p.Acquire(testKey("shared-switch", DefaultChannel))
			if err == ErrHostLocked {
				lockedMu.Lock()
				lockedCount++
				lockedMu.Unlock()
				return
			}
			if err != nil {
				t.Errorf("unexpected error: %v", err)
				return
			}

			time.Sleep(1 * time.Millisecond)
			lease.Release()
		}()
	}

	wg.Wait()

	if lockedCount == 0 {
		t.Log("Note: no lock contention detected (may be OK with fast execution)")
	}
}

func TestPool_MultipleHostnames(t *testing.T) {
	p := New(10*time.Minute, 0)

	hosts := []string{"switch1", "switch2", "switch3", "switch4", "switch5"}
	var wg sync.WaitGroup
	wg.Add(len(hosts))

	for _, host := range hosts {
		go func(h string) {
			defer wg.Done()
			lease, err := p.Acquire(testKey(h, DefaultChannel))
			if err != nil {
				t.Errorf("failed to acquire %s: %v", h, err)
				return
			}
			if !lease.IsNew() {
				t.Errorf("expected new entry for %s", h)
			}
			if lease.entry.Hostname != h {
				t.Errorf("expected hostname %s, got %s", h, lease.entry.Hostname)
			}
			lease.SetConnection(&mockCloser{})
			lease.Release()
		}(host)
	}

	wg.Wait()

	_, infos := p.Status()
	if len(infos) != len(hosts) {
		t.Errorf("expected %d connections, got %d", len(hosts), len(infos))
	}
}

func TestPool_KeepaliveStartsOnSetConnection(t *testing.T) {
	p := New(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	time.Sleep(150 * time.Millisecond)

	count := conn.keepaliveCount.Load()
	if count < 2 {
		t.Errorf("expected at least 2 keepalive requests, got %d", count)
	}

	p.Remove(testKey("switch1.local", DefaultChannel))
}

func TestPool_KeepaliveMarksDeadOnFailure(t *testing.T) {
	p := New(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{failAfter: 2}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	time.Sleep(200 * time.Millisecond)

	key := testKey("switch1.local", DefaultChannel)
	p.mu.Lock()
	entry, exists := p.entries[key]
	dead := exists && entry.Dead
	p.mu.Unlock()

	if !dead {
		t.Error("expected entry to be marked dead after keepalive failure")
	}
}

func TestPool_AcquireEvictsDeadEntry(t *testing.T) {
	p := New(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{shouldFail: true}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	time.Sleep(100 * time.Millisecond)

	lease, err := p.Acquire(testKey("switch1.local", DefaultChannel))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !lease.IsNew() {
		t.Error("expected isNew=true when acquiring dead entry")
	}
	if !conn.closed {
		t.Error("expected dead connection to be closed on eviction")
	}

	p.Remove(testKey("switch1.local", DefaultChannel))
}

func TestPool_SweepEvictsDeadEntries(t *testing.T) {
	p := New(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{shouldFail: true}
	seed(t, p, testKey("dead-switch", DefaultChannel), conn)

	time.Sleep(100 * time.Millisecond)

	removed := p.SweepIdle()
	if removed != 1 {
		t.Errorf("expected 1 removed (dead), got %d", removed)
	}
	if !conn.closed {
		t.Error("expected dead connection to be closed by sweep")
	}
}

func TestPool_KeepaliveStopsOnRemove(t *testing.T) {
	p := New(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	time.Sleep(100 * time.Millisecond)
	countBefore := conn.keepaliveCount.Load()

	p.Remove(testKey("switch1.local", DefaultChannel))

	time.Sleep(100 * time.Millisecond)
	countAfter := conn.keepaliveCount.Load()

	if countAfter > countBefore+1 {
		t.Errorf("expected keepalive to stop after Remove, but count went from %d to %d", countBefore, countAfter)
	}
}

func TestPool_KeepaliveStopsOnDisconnectAll(t *testing.T) {
	p := New(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	time.Sleep(100 * time.Millisecond)
	countBefore := conn.keepaliveCount.Load()

	p.DisconnectAll()

	time.Sleep(100 * time.Millisecond)
	countAfter := conn.keepaliveCount.Load()

	if countAfter > countBefore+1 {
		t.Errorf("expected keepalive to stop after DisconnectAll, but count went from %d to %d", countBefore, countAfter)
	}
}

func TestPool_NoKeepaliveWithZeroInterval(t *testing.T) {
	p := New(10*time.Minute, 0)

	conn := &mockKeepaliveConn{}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	time.Sleep(100 * time.Millisecond)

	count := conn.keepaliveCount.Load()
	if count != 0 {
		t.Errorf("expected 0 keepalive requests with zero interval, got %d", count)
	}

	p.Remove(testKey("switch1.local", DefaultChannel))
}

func TestPool_KeepaliveDoesNotRunOnNonKeepaliveConn(t *testing.T) {
	p := New(10*time.Minute, 50*time.Millisecond)

	conn := &mockCloser{}
	seed(t, p, testKey("switch1.local", DefaultChannel), conn)

	time.Sleep(100 * time.Millisecond)

	key := testKey("switch1.local", DefaultChannel)
	p.mu.Lock()
	entry, exists := p.entries[key]
	dead := exists && entry.Dead
	p.mu.Unlock()

	if dead {
		t.Error("expected entry NOT to be dead when conn doesn't implement KeepaliveChecker")
	}

	p.Remove(testKey("switch1.local", DefaultChannel))
}

func TestNewKey_DiffersByEveryField(t *testing.T) {
	base := NewKey("sw", 22, "admin", "commands", "pw", "", "")
	same := NewKey("sw", 22, "admin", "commands", "pw", "", "")
	if base != same {
		t.Fatal("identical inputs must produce equal keys")
	}
	variants := map[string]Key{
		"hostname":    NewKey("sw2", 22, "admin", "commands", "pw", "", ""),
		"port":        NewKey("sw", 2222, "admin", "commands", "pw", "", ""),
		"username":    NewKey("sw", 22, "root", "commands", "pw", "", ""),
		"channel":     NewKey("sw", 22, "admin", "polling", "pw", "", ""),
		"password":    NewKey("sw", 22, "admin", "commands", "other", "", ""),
		"empty pw":    NewKey("sw", 22, "admin", "commands", "", "", ""),
		"private key": NewKey("sw", 22, "admin", "commands", "", "pw", ""),
		"passphrase":  NewKey("sw", 22, "admin", "commands", "pw", "", "x"),
	}
	for name, k := range variants {
		if k == base {
			t.Errorf("key must differ when %s differs", name)
		}
	}
}

func TestPool_NoSharingAcrossCredentialsOrPort(t *testing.T) {
	p := New(10*time.Minute, 0)
	base := NewKey("sw", 22, "admin", "commands", "pw", "", "")
	baseLease, err := p.Acquire(base)
	if err != nil {
		t.Fatal(err)
	}
	baseLease.SetConnection(&mockCloser{})
	baseLease.SetHostKey("ssh-ed25519 AAAA")
	baseLease.Release()

	others := []Key{
		NewKey("sw", 22, "admin", "commands", "wrong", "", ""),
		NewKey("sw", 22, "admin", "commands", "", "", ""),
		NewKey("sw", 22, "admin", "commands", "", "KEY", ""),
		NewKey("sw", 2222, "admin", "commands", "pw", "", ""),
		NewKey("sw", 22, "root", "commands", "pw", "", ""),
	}
	for i, k := range others {
		l, err := p.Acquire(k)
		if err != nil || !l.IsNew() {
			t.Fatalf("variant %d: expected fresh entry, err=%v", i, err)
		}
	}

	l, err := p.Acquire(base)
	if err != nil || l.IsNew() {
		t.Fatalf("identical key must reuse: err=%v", err)
	}
	if l.HostKey() != "ssh-ed25519 AAAA" {
		t.Errorf("host key not remembered, got %q", l.HostKey())
	}
}

func TestPool_StatusIncludesPortAndUsername(t *testing.T) {
	p := New(10*time.Minute, 0)
	k := NewKey("sw", 2222, "admin", "commands", "secret")
	_, _ = p.Acquire(k)
	_, infos := p.Status()
	if len(infos) != 1 || infos[0].Port != 2222 || infos[0].Username != "admin" {
		t.Fatalf("unexpected status: %+v", infos)
	}
	b, _ := json.Marshal(infos)
	if strings.Contains(string(b), "secret") || strings.Contains(strings.ToLower(string(b)), "hash") {
		t.Errorf("status leaks credentials: %s", b)
	}
}

func TestPool_DifferentChannelsSameHost(t *testing.T) {
	p := New(10*time.Minute, 0)

	lease1, err := p.Acquire(testKey("switch1", "commands"))
	if err != nil {
		t.Fatalf("unexpected error acquiring commands channel: %v", err)
	}
	entry1 := lease1.entry
	if !lease1.IsNew() {
		t.Error("expected isNew to be true for commands channel")
	}
	if entry1.Channel != "commands" {
		t.Errorf("expected channel 'commands', got %q", entry1.Channel)
	}

	lease2, err := p.Acquire(testKey("switch1", "polling"))
	if err != nil {
		t.Fatalf("unexpected error acquiring polling channel: %v", err)
	}
	entry2 := lease2.entry
	if !lease2.IsNew() {
		t.Error("expected isNew to be true for polling channel")
	}
	if entry2.Channel != "polling" {
		t.Errorf("expected channel 'polling', got %q", entry2.Channel)
	}

	if !entry1.Locked {
		t.Error("expected commands entry to be locked")
	}
	if !entry2.Locked {
		t.Error("expected polling entry to be locked")
	}

	lease1.Release()

	_, err = p.Acquire(testKey("switch1", "commands"))
	if err != nil {
		t.Fatalf("expected commands channel to be available after release: %v", err)
	}

	_, err = p.Acquire(testKey("switch1", "polling"))
	if err != ErrHostLocked {
		t.Fatalf("expected polling channel to still be locked, got %v", err)
	}
}

func TestPool_DifferentChannelsIndependentConnections(t *testing.T) {
	p := New(10*time.Minute, 0)

	conn1 := &mockCloser{}
	conn2 := &mockCloser{}

	seed(t, p, testKey("switch1", "commands"), conn1)

	seed(t, p, testKey("switch1", "polling"), conn2)

	p.Remove(testKey("switch1", "commands"))

	if !conn1.closed {
		t.Error("expected commands connection to be closed")
	}
	if conn2.closed {
		t.Error("expected polling connection to NOT be closed")
	}

	lease, err := p.Acquire(testKey("switch1", "polling"))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if lease.IsNew() {
		t.Error("expected polling entry to still exist")
	}
	if lease.Conn() != conn2 {
		t.Error("expected polling connection to be preserved")
	}
}

func TestPool_StatusReportsChannels(t *testing.T) {
	p := New(10*time.Minute, 0)

	seed(t, p, testKey("switch1", "commands"), &mockCloser{})

	seed(t, p, testKey("switch1", "polling"), &mockCloser{})

	_, infos := p.Status()
	if len(infos) != 2 {
		t.Fatalf("expected 2 connection infos, got %d", len(infos))
	}

	channels := map[string]bool{}
	for _, info := range infos {
		if info.Hostname != "switch1" {
			t.Errorf("expected hostname 'switch1', got %q", info.Hostname)
		}
		channels[info.Channel] = true
	}

	if !channels["commands"] {
		t.Error("expected 'commands' channel in status")
	}
	if !channels["polling"] {
		t.Error("expected 'polling' channel in status")
	}
}

func TestPool_SweepIdleWithChannels(t *testing.T) {
	p := New(50*time.Millisecond, 0)

	cmdConn := &mockCloser{}
	seed(t, p, testKey("switch1", "commands"), cmdConn)

	pollConn := &mockCloser{}
	seed(t, p, testKey("switch1", "polling"), pollConn)

	time.Sleep(100 * time.Millisecond)

	p.mu.Lock()
	key := testKey("switch1", "polling")
	if entry, ok := p.entries[key]; ok {
		entry.LastUsed = time.Now()
	}
	p.mu.Unlock()

	removed := p.SweepIdle()
	if removed != 1 {
		t.Errorf("expected 1 removed, got %d", removed)
	}
	if !cmdConn.closed {
		t.Error("expected idle commands connection to be closed")
	}
	if pollConn.closed {
		t.Error("expected active polling connection to NOT be closed")
	}
}

func TestPool_ConcurrentDifferentChannels(t *testing.T) {
	p := New(10*time.Minute, 0)

	channels := []string{"commands", "polling"}
	var wg sync.WaitGroup
	wg.Add(len(channels))

	errors := make([]error, len(channels))

	for i, ch := range channels {
		go func(idx int, channel string) {
			defer wg.Done()
			lease, err := p.Acquire(testKey("switch1", channel))
			errors[idx] = err
			if err == nil {
				time.Sleep(5 * time.Millisecond)
				lease.Release()
			}
		}(i, ch)
	}

	wg.Wait()

	for i, err := range errors {
		if err != nil {
			t.Errorf("channel %q got unexpected error: %v", channels[i], err)
		}
	}
}

func TestPool_EntryHasChannel(t *testing.T) {
	p := New(10*time.Minute, 0)

	lease, _ := p.Acquire(testKey("switch1", "polling"))
	if lease.entry.Channel != "polling" {
		t.Errorf("expected channel 'polling', got %q", lease.entry.Channel)
	}
}

func TestPool_RemoveWhileLocked(t *testing.T) {
	p := New(10*time.Minute, 0)

	conn := &mockCloser{}
	lease, _ := p.Acquire(testKey("switch1", DefaultChannel))
	lease.SetConnection(conn)

	p.Remove(testKey("switch1", DefaultChannel))

	if !conn.closed {
		t.Error("expected connection to be closed on evict")
	}

	fresh, err := p.Acquire(testKey("switch1", DefaultChannel))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !fresh.IsNew() {
		t.Error("expected new entry after evict")
	}
}

func testKey(hostname, channel string) Key {
	return NewKey(hostname, 22, "admin", channel, "pass")
}
