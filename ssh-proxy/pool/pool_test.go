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

	entry, isNew, err := p.Acquire("switch1.local")
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
}

func TestPool_AcquireExisting(t *testing.T) {
	p := New(10 * time.Minute)

	// First acquire — creates entry
	_, _, _ = p.Acquire("switch1.local")
	conn := &mockCloser{}
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	// Second acquire — reuses entry
	entry, isNew, err := p.Acquire("switch1.local")
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
	_, _, _ = p.Acquire("switch1.local")

	// Second acquire — should fail with ErrHostLocked
	_, _, err := p.Acquire("switch1.local")
	if err != ErrHostLocked {
		t.Fatalf("expected ErrHostLocked, got %v", err)
	}
}

func TestPool_Release(t *testing.T) {
	p := New(10 * time.Minute)

	_, _, _ = p.Acquire("switch1.local")
	p.Release("switch1.local")

	// Should be able to acquire again
	_, _, err := p.Acquire("switch1.local")
	if err != nil {
		t.Fatalf("unexpected error after release: %v", err)
	}
}

func TestPool_ReleaseNonExistent(t *testing.T) {
	p := New(10 * time.Minute)
	// Should not panic
	p.Release("nonexistent")
}

func TestPool_Remove(t *testing.T) {
	p := New(10 * time.Minute)

	_, _, _ = p.Acquire("switch1.local")
	conn := &mockCloser{}
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	p.Remove("switch1.local")

	if !conn.closed {
		t.Error("expected connection to be closed on remove")
	}

	// Should create a new entry now
	_, isNew, err := p.Acquire("switch1.local")
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
	p.Remove("nonexistent")
}

func TestPool_SweepIdle(t *testing.T) {
	// Use a very short idle timeout for testing.
	p := New(50 * time.Millisecond)

	_, _, _ = p.Acquire("idle-switch")
	conn := &mockCloser{}
	p.SetConnection("idle-switch", conn)
	p.Release("idle-switch")

	_, _, _ = p.Acquire("active-switch")
	activeConn := &mockCloser{}
	p.SetConnection("active-switch", activeConn)
	p.Release("active-switch")

	// Wait for idle timeout to expire
	time.Sleep(100 * time.Millisecond)

	// Touch the active switch to keep it alive
	p.mu.Lock()
	if entry, ok := p.entries["active-switch"]; ok {
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

	_, _, _ = p.Acquire("locked-switch")
	conn := &mockCloser{}
	p.SetConnection("locked-switch", conn)
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

	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", &mockCloser{})
	p.Release("switch1.local")

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
	if infos[0].Locked {
		t.Error("expected connection to be unlocked")
	}
}

func TestPool_DisconnectAll(t *testing.T) {
	p := New(10 * time.Minute)

	conn1 := &mockCloser{}
	conn2 := &mockCloser{}

	_, _, _ = p.Acquire("switch1")
	p.SetConnection("switch1", conn1)
	p.Release("switch1")

	_, _, _ = p.Acquire("switch2")
	p.SetConnection("switch2", conn2)
	p.Release("switch2")

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

			_, _, err := p.Acquire("shared-switch")
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
			p.Release("shared-switch")
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
			entry, isNew, err := p.Acquire(h)
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
			p.SetConnection(h, &mockCloser{})
			p.Release(h)
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
	// Use a short keepalive interval so we can verify it fires.
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	// Wait enough time for at least 2 keepalives to fire.
	time.Sleep(150 * time.Millisecond)

	count := conn.keepaliveCount.Load()
	if count < 2 {
		t.Errorf("expected at least 2 keepalive requests, got %d", count)
	}

	// Clean up
	p.Remove("switch1.local")
}

func TestPool_KeepaliveMarksDeadOnFailure(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{failAfter: 2}
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	// Wait for the keepalive to fail (after 2 successful ones).
	time.Sleep(200 * time.Millisecond)

	p.mu.Lock()
	entry, exists := p.entries["switch1.local"]
	dead := exists && entry.Dead
	p.mu.Unlock()

	if !dead {
		t.Error("expected entry to be marked dead after keepalive failure")
	}
}

func TestPool_AcquireEvictsDeadEntry(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{shouldFail: true}
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	// Wait for keepalive to mark connection dead.
	time.Sleep(100 * time.Millisecond)

	// Acquire should evict the dead entry and return isNew=true.
	entry, isNew, err := p.Acquire("switch1.local")
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

	// Clean up
	p.Remove("switch1.local")
}

func TestPool_SweepEvictsDeadEntries(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{shouldFail: true}
	_, _, _ = p.Acquire("dead-switch")
	p.SetConnection("dead-switch", conn)
	p.Release("dead-switch")

	// Wait for keepalive to mark dead.
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
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	// Let a few keepalives fire.
	time.Sleep(100 * time.Millisecond)
	countBefore := conn.keepaliveCount.Load()

	// Remove should stop the keepalive goroutine.
	p.Remove("switch1.local")

	// Wait and verify no more keepalives fire.
	time.Sleep(100 * time.Millisecond)
	countAfter := conn.keepaliveCount.Load()

	// Allow at most 1 more (race between stop and tick).
	if countAfter > countBefore+1 {
		t.Errorf("expected keepalive to stop after Remove, but count went from %d to %d", countBefore, countAfter)
	}
}

func TestPool_KeepaliveStopsOnDisconnectAll(t *testing.T) {
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockKeepaliveConn{}
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	// Let a few keepalives fire.
	time.Sleep(100 * time.Millisecond)
	countBefore := conn.keepaliveCount.Load()

	p.DisconnectAll()

	// Wait and verify no more keepalives fire.
	time.Sleep(100 * time.Millisecond)
	countAfter := conn.keepaliveCount.Load()

	if countAfter > countBefore+1 {
		t.Errorf("expected keepalive to stop after DisconnectAll, but count went from %d to %d", countBefore, countAfter)
	}
}

func TestPool_NoKeepaliveWithZeroInterval(t *testing.T) {
	// When keepalive interval is 0, no keepalive goroutine should start.
	p := New(10 * time.Minute)

	conn := &mockKeepaliveConn{}
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	time.Sleep(100 * time.Millisecond)

	count := conn.keepaliveCount.Load()
	if count != 0 {
		t.Errorf("expected 0 keepalive requests with zero interval, got %d", count)
	}

	p.Remove("switch1.local")
}

func TestPool_KeepaliveDoesNotRunOnNonKeepaliveConn(t *testing.T) {
	// When the connection doesn't implement KeepaliveChecker, no keepalive should run.
	p := NewWithKeepalive(10*time.Minute, 50*time.Millisecond)

	conn := &mockCloser{} // doesn't implement KeepaliveChecker
	_, _, _ = p.Acquire("switch1.local")
	p.SetConnection("switch1.local", conn)
	p.Release("switch1.local")

	time.Sleep(100 * time.Millisecond)

	// Entry should not be marked dead — no keepalive was running.
	p.mu.Lock()
	entry, exists := p.entries["switch1.local"]
	dead := exists && entry.Dead
	p.mu.Unlock()

	if dead {
		t.Error("expected entry NOT to be dead when conn doesn't implement KeepaliveChecker")
	}

	p.Remove("switch1.local")
}
