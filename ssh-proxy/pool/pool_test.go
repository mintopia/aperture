package pool

import (
	"errors"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// mockCloser tracks whether Close was called.
type mockCloser struct {
	closed bool
}

func (m *mockCloser) Close() error {
	m.closed = true
	return nil
}

// mockKeepaliveConn implements both io.Closer and KeepaliveChecker for testing.
type mockKeepaliveConn struct {
	closed         bool
	keepaliveCount atomic.Int32
	failAfter      int32 // if > 0, fail after this many keepalives
	shouldFail     bool  // if true, always fail
}

func (m *mockKeepaliveConn) Close() error {
	m.closed = true
	return nil
}

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
	p := New(10 * time.Minute)

	entry, isNew, err := p.Acquire("switch1.local", DefaultChannel)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !isNew {
		t.Error("expected isNew to be true")
	}
	if entry == nil {
		t.Fatal("expected non-nil entry")
	}
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
	p := New(10 * time.Minute)

	// First acquire — creates entry
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	conn := &mockCloser{}
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	// Second acquire — reuses entry
	entry, isNew, err := p.Acquire("switch1.local", DefaultChannel)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if isNew {
		t.Error("expected isNew to be false")
	}
	if entry.Conn != conn {
		t.Error("expected same connection to be reused")
	}
	if !entry.Locked {
		t.Error("expected entry to be locked after acquire")
	}
}

func TestPool_AcquireLocked(t *testing.T) {
	p := New(10 * time.Minute)

	// First acquire — locks the entry
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)

	// Second acquire — should fail with ErrHostLocked
	_, _, err := p.Acquire("switch1.local", DefaultChannel)
	if err != ErrHostLocked {
		t.Fatalf("expected ErrHostLocked, got %v", err)
	}
}

func TestPool_Release(t *testing.T) {
	p := New(10 * time.Minute)

	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.Release("switch1.local", DefaultChannel)

	// Should be able to acquire again
	_, _, err := p.Acquire("switch1.local", DefaultChannel)
	if err != nil {
		t.Fatalf("unexpected error after release: %v", err)
	}
}

func TestPool_ReleaseNonExistent(t *testing.T) {
	p := New(10 * time.Minute)
	// Should not panic
	p.Release("nonexistent", DefaultChannel)
}

func TestPool_Remove(t *testing.T) {
	p := New(10 * time.Minute)

	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	conn := &mockCloser{}
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	p.Remove("switch1.local", DefaultChannel)

	if !conn.closed {
		t.Error("expected connection to be closed on remove")
	}

	// Should create a new entry now
	_, isNew, err := p.Acquire("switch1.local", DefaultChannel)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !isNew {
		t.Error("expected new entry after remove")
	}
}

func TestPool_RemoveNonExistent(t *testing.T) {
	p := New(10 * time.Minute)
	// Should not panic
	p.Remove("nonexistent", DefaultChannel)
}

func TestPool_SweepIdle(t *testing.T) {
	// Use a very short idle timeout for testing.
	p := New(50 * time.Millisecond)

	_, _, _ = p.Acquire("idle-switch", DefaultChannel)
	conn := &mockCloser{}
	p.SetConnection("idle-switch", DefaultChannel, conn)
	p.Release("idle-switch", DefaultChannel)

	_, _, _ = p.Acquire("active-switch", DefaultChannel)
	activeConn := &mockCloser{}
	p.SetConnection("active-switch", DefaultChannel, activeConn)
	p.Release("active-switch", DefaultChannel)

	// Wait for idle timeout to expire
	time.Sleep(100 * time.Millisecond)

	// Touch the active switch to keep it alive
	p.mu.Lock()
	key := PoolKey("active-switch", DefaultChannel)
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
	p := New(1 * time.Millisecond)

	_, _, _ = p.Acquire("locked-switch", DefaultChannel)
	conn := &mockCloser{}
	p.SetConnection("locked-switch", DefaultChannel, conn)
	// Don't release — entry stays locked

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
	p := New(10 * time.Minute)

	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, &mockCloser{})
	p.Release("switch1.local", DefaultChannel)

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
	p := New(10 * time.Minute)

	conn1 := &mockCloser{}
	conn2 := &mockCloser{}

	_, _, _ = p.Acquire("switch1", DefaultChannel)
	p.SetConnection("switch1", DefaultChannel, conn1)
	p.Release("switch1", DefaultChannel)

	_, _, _ = p.Acquire("switch2", DefaultChannel)
	p.SetConnection("switch2", DefaultChannel, conn2)
	p.Release("switch2", DefaultChannel)

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
	p := New(10 * time.Minute)
	const goroutines = 50

	var wg sync.WaitGroup
	wg.Add(goroutines)

	lockedCount := 0
	var lockedMu sync.Mutex

	for i := 0; i < goroutines; i++ {
		go func() {
			defer wg.Done()

			_, _, err := p.Acquire("shared-switch", DefaultChannel)
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

			// Simulate work
			time.Sleep(1 * time.Millisecond)
			p.Release("shared-switch", DefaultChannel)
		}()
	}

	wg.Wait()

	// At least some goroutines should have been locked out
	if lockedCount == 0 {
		t.Log("Note: no lock contention detected (may be OK with fast execution)")
	}
}

func TestPool_MultipleHostnames(t *testing.T) {
	p := New(10 * time.Minute)

	// Acquire different hostnames concurrently — should not interfere
	hosts := []string{"switch1", "switch2", "switch3", "switch4", "switch5"}
	var wg sync.WaitGroup
	wg.Add(len(hosts))

	for _, host := range hosts {
		go func(h string) {
			defer wg.Done()
			entry, isNew, err := p.Acquire(h, DefaultChannel)
			if err != nil {
				t.Errorf("failed to acquire %s: %v", h, err)
				return
			}
			if !isNew {
				t.Errorf("expected new entry for %s", h)
			}
			if entry.Hostname != h {
				t.Errorf("expected hostname %s, got %s", h, entry.Hostname)
			}
			p.SetConnection(h, DefaultChannel, &mockCloser{})
			p.Release(h, DefaultChannel)
		}(host)
	}

	wg.Wait()

	_, infos := p.Status()
	if len(infos) != len(hosts) {
		t.Errorf("expected %d connections, got %d", len(hosts), len(infos))
	}
}

// --- Keepalive tests ---

func TestPool_KeepaliveStartsOnSetConnection(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	time.Sleep(150 * time.Millisecond)

	count := conn.keepaliveCount.Load()
	if count < 2 {
		t.Errorf("expected at least 2 keepalive requests, got %d", count)
	}

	p.Remove("switch1.local", DefaultChannel)
}

func TestPool_KeepaliveMarksDeadOnFailure(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{failAfter: 2}
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	time.Sleep(200 * time.Millisecond)

	key := PoolKey("switch1.local", DefaultChannel)
	p.mu.Lock()
	entry, exists := p.entries[key]
	dead := exists && entry.Dead
	p.mu.Unlock()

	if !dead {
		t.Error("expected entry to be marked dead after keepalive failure")
	}
}

func TestPool_AcquireEvictsDeadEntry(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{shouldFail: true}
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	time.Sleep(100 * time.Millisecond)

	entry, isNew, err := p.Acquire("switch1.local", DefaultChannel)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !isNew {
		t.Error("expected isNew=true when acquiring dead entry")
	}
	if entry == nil {
		t.Fatal("expected non-nil entry")
	}
	if !conn.closed {
		t.Error("expected dead connection to be closed on eviction")
	}

	p.Remove("switch1.local", DefaultChannel)
}

func TestPool_SweepEvictsDeadEntries(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{shouldFail: true}
	_, _, _ = p.Acquire("dead-switch", DefaultChannel)
	p.SetConnection("dead-switch", DefaultChannel, conn)
	p.Release("dead-switch", DefaultChannel)

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
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	time.Sleep(100 * time.Millisecond)
	countBefore := conn.keepaliveCount.Load()

	p.Remove("switch1.local", DefaultChannel)

	time.Sleep(100 * time.Millisecond)
	countAfter := conn.keepaliveCount.Load()

	if countAfter > countBefore+1 {
		t.Errorf("expected keepalive to stop after Remove, but count went from %d to %d", countBefore, countAfter)
	}
}

func TestPool_KeepaliveStopsOnDisconnectAll(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

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
	p := New(10 * time.Minute)

	conn := &mockKeepaliveConn{}
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	time.Sleep(100 * time.Millisecond)

	count := conn.keepaliveCount.Load()
	if count != 0 {
		t.Errorf("expected 0 keepalive requests with zero interval, got %d", count)
	}

	p.Remove("switch1.local", DefaultChannel)
}

func TestPool_KeepaliveDoesNotRunOnNonKeepaliveConn(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockCloser{}
	_, _, _ = p.Acquire("switch1.local", DefaultChannel)
	p.SetConnection("switch1.local", DefaultChannel, conn)
	p.Release("switch1.local", DefaultChannel)

	time.Sleep(100 * time.Millisecond)

	key := PoolKey("switch1.local", DefaultChannel)
	p.mu.Lock()
	entry, exists := p.entries[key]
	dead := exists && entry.Dead
	p.mu.Unlock()

	if dead {
		t.Error("expected entry NOT to be dead when conn doesn't implement KeepaliveChecker")
	}

	p.Remove("switch1.local", DefaultChannel)
}

// --- Channel-specific tests ---

func TestPoolKey(t *testing.T) {
	key := PoolKey("switch1.local", "commands")
	if key != "switch1.local:commands" {
		t.Errorf("expected 'switch1.local:commands', got %q", key)
	}

	key2 := PoolKey("switch1.local", "polling")
	if key2 != "switch1.local:polling" {
		t.Errorf("expected 'switch1.local:polling', got %q", key2)
	}

	if key == key2 {
		t.Error("expected different keys for different channels")
	}
}

func TestPool_DifferentChannelsSameHost(t *testing.T) {
	p := New(10 * time.Minute)

	entry1, isNew1, err := p.Acquire("switch1", "commands")
	if err != nil {
		t.Fatalf("unexpected error acquiring commands channel: %v", err)
	}
	if !isNew1 {
		t.Error("expected isNew to be true for commands channel")
	}
	if entry1.Channel != "commands" {
		t.Errorf("expected channel 'commands', got %q", entry1.Channel)
	}

	entry2, isNew2, err := p.Acquire("switch1", "polling")
	if err != nil {
		t.Fatalf("unexpected error acquiring polling channel: %v", err)
	}
	if !isNew2 {
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

	p.Release("switch1", "commands")

	_, _, err = p.Acquire("switch1", "commands")
	if err != nil {
		t.Fatalf("expected commands channel to be available after release: %v", err)
	}

	_, _, err = p.Acquire("switch1", "polling")
	if err != ErrHostLocked {
		t.Fatalf("expected polling channel to still be locked, got %v", err)
	}
}

func TestPool_DifferentChannelsIndependentConnections(t *testing.T) {
	p := New(10 * time.Minute)

	conn1 := &mockCloser{}
	conn2 := &mockCloser{}

	_, _, _ = p.Acquire("switch1", "commands")
	p.SetConnection("switch1", "commands", conn1)
	p.Release("switch1", "commands")

	_, _, _ = p.Acquire("switch1", "polling")
	p.SetConnection("switch1", "polling", conn2)
	p.Release("switch1", "polling")

	p.Remove("switch1", "commands")

	if !conn1.closed {
		t.Error("expected commands connection to be closed")
	}
	if conn2.closed {
		t.Error("expected polling connection to NOT be closed")
	}

	entry, isNew, err := p.Acquire("switch1", "polling")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if isNew {
		t.Error("expected polling entry to still exist")
	}
	if entry.Conn != conn2 {
		t.Error("expected polling connection to be preserved")
	}
}

func TestPool_StatusReportsChannels(t *testing.T) {
	p := New(10 * time.Minute)

	_, _, _ = p.Acquire("switch1", "commands")
	p.SetConnection("switch1", "commands", &mockCloser{})
	p.Release("switch1", "commands")

	_, _, _ = p.Acquire("switch1", "polling")
	p.SetConnection("switch1", "polling", &mockCloser{})
	p.Release("switch1", "polling")

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
	p := New(50 * time.Millisecond)

	_, _, _ = p.Acquire("switch1", "commands")
	cmdConn := &mockCloser{}
	p.SetConnection("switch1", "commands", cmdConn)
	p.Release("switch1", "commands")

	_, _, _ = p.Acquire("switch1", "polling")
	pollConn := &mockCloser{}
	p.SetConnection("switch1", "polling", pollConn)
	p.Release("switch1", "polling")

	time.Sleep(100 * time.Millisecond)

	p.mu.Lock()
	key := PoolKey("switch1", "polling")
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
	p := New(10 * time.Minute)

	channels := []string{"commands", "polling"}
	var wg sync.WaitGroup
	wg.Add(len(channels))

	errors := make([]error, len(channels))

	for i, ch := range channels {
		go func(idx int, channel string) {
			defer wg.Done()
			_, _, err := p.Acquire("switch1", channel)
			errors[idx] = err
			if err == nil {
				time.Sleep(5 * time.Millisecond)
				p.Release("switch1", channel)
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
	p := New(10 * time.Minute)

	entry, _, _ := p.Acquire("switch1", "polling")
	if entry.Channel != "polling" {
		t.Errorf("expected channel 'polling', got %q", entry.Channel)
	}
}
