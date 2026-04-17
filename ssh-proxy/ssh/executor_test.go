package ssh

import (
	"testing"
	"time"
)

// mockSession is a test double for ssh.Session that replays predefined output
// chunks in sequence. Each Read call returns the next chunk from the list.
type mockSession struct {
	chunks   []string
	chunkIdx int
	written  []string
	closed   bool
}

func newMockSession(chunks ...string) *mockSession {
	return &mockSession{chunks: chunks}
}

func (m *mockSession) Write(data string) error {
	m.written = append(m.written, data)
	return nil
}

func (m *mockSession) Read(_ time.Duration) string {
	if m.chunkIdx >= len(m.chunks) {
		return ""
	}
	chunk := m.chunks[m.chunkIdx]
	m.chunkIdx++
	return chunk
}

func (m *mockSession) Close() error {
	m.closed = true
	return nil
}

// --- getLastLine tests ---

func TestGetLastLine(t *testing.T) {
	tests := []struct {
		name   string
		input  string
		expect string
	}{
		{"empty", "", ""},
		{"single line", "Switch#", "Switch#"},
		{"trailing newline", "Switch#\n", "Switch#"},
		{"multiple lines", "line1\nline2\nSwitch#", "Switch#"},
		{"empty lines in middle", "line1\n\nSwitch#\n", "Switch#"},
		{"whitespace only", "   \n  \n  ", ""},
		{"leading spaces trimmed by outer trim", "  Switch#\n", "Switch#"},
		{"carriage return", "output\r\nSwitch#\r\n", "Switch#"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := getLastLine(tt.input)
			if got != tt.expect {
				t.Errorf("getLastLine(%q) = %q, want %q", tt.input, got, tt.expect)
			}
		})
	}
}

// --- matchesCondition tests ---

func TestMatchesCondition(t *testing.T) {
	tests := []struct {
		name      string
		text      string
		condition string
		expect    bool
	}{
		{"substring match", "Password:", "Password:", true},
		{"substring partial", "Enter Password: now", "Password:", true},
		{"substring no match", "Username:", "Password:", false},
		{"regex match", "Switch>", `/>\s*$/`, true},
		{"regex match with trailing space", "Switch>  ", `/>\s*$/`, true},
		{"regex no match", "Switch#", `/>\s*$/`, false},
		{"regex password prompt", "Password:", `/Password:/`, true},
		{"regex config mode", "Switch(config)#", `/\(config\)#$/`, true},
		{"regex config-if mode", "Switch(config-if)#", `/\(config-if\)#$/`, true},
		{"short regex treated as substring", "/", "/", true}, // len("/") <= 2, so substring: "/" contains "/"
		{"two char regex treated as substring", "//", "//", true},
		{"invalid regex returns false", "test", `/[invalid/`, false},
		{"empty condition", "anything", "", true}, // strings.Contains(x, "") == true
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := matchesCondition(tt.text, tt.condition)
			if got != tt.expect {
				t.Errorf("matchesCondition(%q, %q) = %v, want %v", tt.text, tt.condition, got, tt.expect)
			}
		})
	}
}

// --- Executor.Execute tests ---

func TestExecutor_SimpleCommands(t *testing.T) {
	// Simulates: connect → initial prompt → send command → read output
	session := newMockSession(
		"Switch>",                // initial prompt read
		"show version\nCisco...", // output after "show version"
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "show version"},
	})

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 1 {
		t.Fatalf("expected 1 output, got %d", len(result.Output))
	}
	if result.Output[0].Command != "show version" {
		t.Errorf("expected command 'show version', got %q", result.Output[0].Command)
	}
	if result.Output[0].Output != "show version\nCisco..." {
		t.Errorf("unexpected output: %q", result.Output[0].Output)
	}
	// Verify the command was written with newline
	if len(session.written) != 1 || session.written[0] != "show version\n" {
		t.Errorf("expected write 'show version\\n', got %v", session.written)
	}
}

func TestExecutor_IfConditionSkip(t *testing.T) {
	// Initial prompt is "Switch#" (already in enable mode)
	// The "en" command has if: "/>\s*$/" which should NOT match "#"
	session := newMockSession(
		"Switch#",                              // initial prompt
		"terminal length 0\nSwitch#",           // "terminal length 0" output
		"show interfaces\nGi1/0/1...\nSwitch#", // "show interfaces" output
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "en", If: `/>\s*$/`},
		{Command: "terminal length 0"},
		{Command: "show interfaces"},
	})

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	// "en" should be skipped, so only 2 outputs
	if len(result.Output) != 2 {
		t.Fatalf("expected 2 outputs (en skipped), got %d", len(result.Output))
	}
	if result.Output[0].Command != "terminal length 0" {
		t.Errorf("first output should be 'terminal length 0', got %q", result.Output[0].Command)
	}
}

func TestExecutor_IfConditionMatch(t *testing.T) {
	// Initial prompt is "Switch>" (user exec mode)
	// The "en" command has if: "/>\s*$/" which SHOULD match ">"
	session := newMockSession(
		"Switch>",   // initial prompt
		"Password:", // output after "en" (with expect)
		"Switch#",   // output after password (with expect)
		"Switch#",   // output after "terminal length 0" (with expect)
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "en", If: `/>\s*$/`, Expect: `/Password:/`},
		{Command: "secret", If: `/Password:/`, Expect: `/#\s*$/`},
		{Command: "terminal length 0", Expect: `/#\s*$/`},
	})

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 3 {
		t.Fatalf("expected 3 outputs, got %d", len(result.Output))
	}
}

func TestExecutor_ExpectTimeout(t *testing.T) {
	// Session returns data that never matches the expect pattern
	session := newMockSession(
		"Switch>",         // initial prompt
		"unexpected data", // doesn't match "#"
		"",                // no more data - timeout
	)

	exec := &Executor{
		ReadTimeout:    100 * time.Millisecond,
		CommandTimeout: 300 * time.Millisecond,
	}

	result := exec.Execute(session, []Command{
		{Command: "show ver", Expect: "#"},
	})

	if result.Success {
		t.Fatal("expected failure due to timeout")
	}
	if result.Error != "Timeout waiting for expected pattern: #" {
		t.Errorf("unexpected error: %q", result.Error)
	}
}

func TestExecutor_EmptyOutput(t *testing.T) {
	session := newMockSession(
		"Switch#", // initial prompt
		"",        // no output for command
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "en"},
	})

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 1 {
		t.Fatalf("expected 1 output, got %d", len(result.Output))
	}
	if result.Output[0].Output != "" {
		t.Errorf("expected empty output, got %q", result.Output[0].Output)
	}
}

func TestExecutor_OutputSliceNeverNil(t *testing.T) {
	// Even with no commands, output should be an empty slice (not nil).
	session := newMockSession("Switch#")

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{})

	if !result.Success {
		t.Fatalf("expected success")
	}
	if result.Output == nil {
		t.Fatal("output should not be nil")
	}
	if len(result.Output) != 0 {
		t.Fatalf("expected 0 outputs, got %d", len(result.Output))
	}
}

func TestExecutor_IfSubstringMatch(t *testing.T) {
	session := newMockSession(
		"Enter Password:", // initial prompt
		"Switch#",         // after sending password
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "mypassword", If: "Password:"},
	})

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 1 {
		t.Fatalf("expected 1 output, got %d", len(result.Output))
	}
}

func TestExecutor_IfSubstringNoMatch(t *testing.T) {
	session := newMockSession(
		"Switch#", // initial prompt — doesn't contain "Password:"
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "mypassword", If: "Password:"},
	})

	if !result.Success {
		t.Fatalf("expected success")
	}
	// Command skipped, no output
	if len(result.Output) != 0 {
		t.Fatalf("expected 0 outputs (skipped), got %d", len(result.Output))
	}
}

func TestExecutor_CiscoEnableFlow(t *testing.T) {
	// Full Cisco enable flow: en → password → terminal length 0 → show cmd
	session := newMockSession(
		"Switch>",                    // initial prompt
		"Password:",                  // after "en" (expect matches)
		"Switch#",                    // after password (expect matches)
		"terminal length 0\nSwitch#", // after "terminal length 0"
		"show interface status\nGi1/0/1 connected\nSwitch#", // show command
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	commands := []Command{
		{Command: "en", If: `/>\s*$/`, Expect: `/Password:/`},
		{Command: "enable-pass", If: `/Password:/`, Expect: `/#\s*$/`},
		{Command: "terminal length 0", Expect: `/#\s*$/`},
		{Command: "show interface status", Expect: `/#\s*$/`},
	}

	result := exec.Execute(session, commands)

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 4 {
		t.Fatalf("expected 4 outputs, got %d", len(result.Output))
	}

	// Verify commands were written in order
	expectedWrites := []string{"en\n", "enable-pass\n", "terminal length 0\n", "show interface status\n"}
	if len(session.written) != len(expectedWrites) {
		t.Fatalf("expected %d writes, got %d", len(expectedWrites), len(session.written))
	}
	for i, exp := range expectedWrites {
		if session.written[i] != exp {
			t.Errorf("write[%d] = %q, want %q", i, session.written[i], exp)
		}
	}
}

func TestExecutor_AlreadyInEnableMode(t *testing.T) {
	// Connection is already in enable mode (pooled connection reuse).
	// The "en" and password commands should be skipped.
	session := newMockSession(
		"Switch#",                           // initial prompt (already in enable)
		"terminal length 0\nSwitch#",        // "terminal length 0" output
		"show vlan brief\nVLAN...\nSwitch#", // show command
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	commands := []Command{
		{Command: "en", If: `/>\s*$/`, Expect: `/Password:/`},
		{Command: "enable-pass", If: `/Password:/`, Expect: `/#\s*$/`},
		{Command: "terminal length 0", Expect: `/#\s*$/`},
		{Command: "show vlan brief", Expect: `/#\s*$/`},
	}

	result := exec.Execute(session, commands)

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	// Only terminal length 0 and show vlan brief should execute
	if len(result.Output) != 2 {
		t.Fatalf("expected 2 outputs (en + password skipped), got %d", len(result.Output))
	}
	if result.Output[0].Command != "terminal length 0" {
		t.Errorf("first command should be 'terminal length 0', got %q", result.Output[0].Command)
	}
	if result.Output[1].Command != "show vlan brief" {
		t.Errorf("second command should be 'show vlan brief', got %q", result.Output[1].Command)
	}
}
