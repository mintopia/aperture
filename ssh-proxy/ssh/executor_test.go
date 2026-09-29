package ssh

import (
	"bytes"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"testing"
	"time"
)

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

func re(v string) *Matcher  { return &Matcher{Type: MatchRegex, Value: v} }
func lit(v string) *Matcher { return &Matcher{Type: MatchLiteral, Value: v} }

func TestMatcher(t *testing.T) {
	tests := []struct {
		name string
		m    *Matcher
		text string
		want bool
	}{
		{"literal substring", lit("Password:"), "Enter Password: now", true},
		{"literal no match", lit("Password:"), "Username:", false},
		{"literal is not regex", lit(`>\s*$`), "Switch>", false},
		{"literal slash-wrapped is literal", lit("/foo/"), "a /foo/ b", true},
		{"literal slash-wrapped does not act as regex", lit("/foo/"), "foo", false},
		{"regex match", re(`>\s*$`), "Switch>  ", true},
		{"regex no match", re(`>\s*$`), "Switch#", false},
		{"regex slash-wrapped is not stripped", re("/foo/"), "foo", false},
		{"regex config mode", re(`\(config\)#$`), "Switch(config)#", true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			match, err := tt.m.compile(&promptTracker{})
			if err != nil {
				t.Fatal(err)
			}
			if got := match(tt.text); got != tt.want {
				t.Errorf("got %v, want %v", got, tt.want)
			}
		})
	}
}

func TestMatcher_Validate(t *testing.T) {
	bad := []*Matcher{
		{Type: "glob", Value: "x"},
		{Type: "", Value: "x"},
		{Type: MatchLiteral, Value: ""},
		{Type: MatchRegex, Value: ""},
		{Type: MatchRegex, Value: "[invalid"},
	}
	for _, m := range bad {
		if err := m.Validate(); err == nil {
			t.Errorf("expected error for %+v", m)
		}
	}
	for _, m := range []*Matcher{lit("x"), re(`^{prompt}#$`)} {
		if err := m.Validate(); err != nil {
			t.Errorf("unexpected error for %+v: %v", m, err)
		}
	}
}

type failingWriteSession struct {
	*mockSession
	err error
}

func (f *failingWriteSession) Write(string) error { return f.err }

func TestExecutor_WriteFailureCarriesTypedError(t *testing.T) {
	exec := &Executor{ReadTimeout: time.Second, CommandTimeout: time.Second}
	lost := &failingWriteSession{newMockSession("Switch#"), fmt.Errorf("%w: broken pipe", ErrConnectionLost)}
	result := exec.Execute(lost, []Command{{Command: "show ver"}})
	if result.Success || !errors.Is(result.Err, ErrConnectionLost) {
		t.Fatalf("expected ErrConnectionLost, got success=%v err=%v", result.Success, result.Err)
	}
}

func TestExecutor_ExpectTimeoutIsNotConnectionLost(t *testing.T) {
	exec := &Executor{ReadTimeout: 10 * time.Millisecond, CommandTimeout: 50 * time.Millisecond}
	result := exec.Execute(newMockSession("Switch#"), []Command{{Command: "x", Expect: lit("never")}})
	if result.Success || !errors.Is(result.Err, ErrExpectTimeout) || errors.Is(result.Err, ErrConnectionLost) {
		t.Fatalf("unexpected result: %+v", result)
	}
	if !strings.HasPrefix(result.Error, "Timeout waiting for expected pattern") {
		t.Errorf("error message changed: %q", result.Error)
	}
}

func TestExecutor_SlashWrappedCommandIfIsLiteral(t *testing.T) {
	exec := &Executor{ReadTimeout: time.Second, CommandTimeout: time.Second}
	session := newMockSession("Switch#")
	result := exec.Execute(session, []Command{{Command: "x", If: lit("/#/")}})
	if !result.Success || len(result.Output) != 0 {
		t.Fatalf("literal '/#/' must not match 'Switch#': %+v", result)
	}
}

func TestCommand_LogString(t *testing.T) {
	if got := (Command{Command: "enable secret hunter2", Sensitive: true}).LogString(); got != "****" {
		t.Errorf("sensitive command not redacted: %q", got)
	}
	if got := (Command{Command: "show version"}).LogString(); got != "show version" {
		t.Errorf("non-sensitive command altered: %q", got)
	}
	if got := (Command{Command: "hunter2"}).LogString(); got != "hunter2" {
		t.Errorf("short single-word non-sensitive command must log verbatim: %q", got)
	}
}

func TestExecutor_SensitiveCommandNeverLogged(t *testing.T) {
	var buf bytes.Buffer
	logger := slog.New(slog.NewTextHandler(&buf, &slog.HandlerOptions{Level: slog.LevelDebug}))
	exec := &Executor{ReadTimeout: time.Second, CommandTimeout: time.Second, Logger: logger}
	session := newMockSession("Password:", "hunter2\nSwitch#")
	result := exec.Execute(session, []Command{
		{Command: "hunter2", Sensitive: true, If: lit("Password:"), Expect: re(`#\s*$`)},
	})
	if !result.Success {
		t.Fatalf("unexpected failure: %s", result.Error)
	}
	if strings.Contains(buf.String(), "hunter2") {
		t.Errorf("secret leaked into logs:\n%s", buf.String())
	}
	if result.Output[0].Command != "hunter2" {
		t.Errorf("response output must keep the real command, got %q", result.Output[0].Command)
	}
}

func TestExecutor_SimpleCommands(t *testing.T) {
	session := newMockSession(
		"Switch>",
		"show version\nCisco...",
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
	if len(session.written) != 1 || session.written[0] != "show version\n" {
		t.Errorf("expected write 'show version\\n', got %v", session.written)
	}
}

func TestExecutor_IfConditionSkip(t *testing.T) {
	session := newMockSession(
		"Switch#",
		"terminal length 0\nSwitch#",
		"show interfaces\nGi1/0/1...\nSwitch#",
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "en", If: re(`>\s*$`)},
		{Command: "terminal length 0"},
		{Command: "show interfaces"},
	})

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 2 {
		t.Fatalf("expected 2 outputs (en skipped), got %d", len(result.Output))
	}
	if result.Output[0].Command != "terminal length 0" {
		t.Errorf("first output should be 'terminal length 0', got %q", result.Output[0].Command)
	}
}

func TestExecutor_IfConditionMatch(t *testing.T) {
	session := newMockSession(
		"Switch>",
		"Password:",
		"Switch#",
		"Switch#",
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "en", If: re(`>\s*$`), Expect: re(`Password:`)},
		{Command: "secret", If: re(`Password:`), Expect: re(`#\s*$`)},
		{Command: "terminal length 0", Expect: re(`#\s*$`)},
	})

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 3 {
		t.Fatalf("expected 3 outputs, got %d", len(result.Output))
	}
}

func TestExecutor_ExpectTimeout(t *testing.T) {
	session := newMockSession(
		"Switch>",
		"unexpected data",
		"",
	)

	exec := &Executor{
		ReadTimeout:    100 * time.Millisecond,
		CommandTimeout: 300 * time.Millisecond,
	}

	result := exec.Execute(session, []Command{
		{Command: "show ver", Expect: lit("#")},
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
		"Switch#",
		"",
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
		"Enter Password:",
		"Switch#",
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "mypassword", If: lit("Password:")},
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
		"Switch#",
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	result := exec.Execute(session, []Command{
		{Command: "mypassword", If: lit("Password:")},
	})

	if !result.Success {
		t.Fatalf("expected success")
	}
	if len(result.Output) != 0 {
		t.Fatalf("expected 0 outputs (skipped), got %d", len(result.Output))
	}
}

func TestExecutor_CiscoEnableFlow(t *testing.T) {
	session := newMockSession(
		"Switch>",
		"Password:",
		"Switch#",
		"terminal length 0\nSwitch#",
		"show interface status\nGi1/0/1 connected\nSwitch#",
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	commands := []Command{
		{Command: "en", If: re(`>\s*$`), Expect: re(`Password:`)},
		{Command: "enable-pass", If: re(`Password:`), Expect: re(`#\s*$`)},
		{Command: "terminal length 0", Expect: re(`#\s*$`)},
		{Command: "show interface status", Expect: re(`#\s*$`)},
	}

	result := exec.Execute(session, commands)

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
	if len(result.Output) != 4 {
		t.Fatalf("expected 4 outputs, got %d", len(result.Output))
	}

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
	session := newMockSession(
		"Switch#",
		"terminal length 0\nSwitch#",
		"show vlan brief\nVLAN...\nSwitch#",
	)

	exec := &Executor{
		ReadTimeout:    5 * time.Second,
		CommandTimeout: 30 * time.Second,
	}

	commands := []Command{
		{Command: "en", If: re(`>\s*$`), Expect: re(`Password:`)},
		{Command: "enable-pass", If: re(`Password:`), Expect: re(`#\s*$`)},
		{Command: "terminal length 0", Expect: re(`#\s*$`)},
		{Command: "show vlan brief", Expect: re(`#\s*$`)},
	}

	result := exec.Execute(session, commands)

	if !result.Success {
		t.Fatalf("expected success, got error: %s", result.Error)
	}
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

func TestExecutor_PromptPlaceholderIgnoresNonPromptLinesEndingInHash(t *testing.T) {
	session := newMockSession("sw1>", "config line\r\nbanner text #", "\r\nend\r\nsw1>")
	e := &Executor{ReadTimeout: 10 * time.Millisecond, CommandTimeout: time.Second}

	result := e.Execute(session, []Command{{Command: "show run", Expect: re(`^{prompt}(\([^)]*\))?[>#]\s*$`)}})

	if !result.Success {
		t.Fatalf("expected success, got %q", result.Error)
	}
	if got := result.Output[0].Output; !strings.HasSuffix(got, "end\r\nsw1>") {
		t.Fatalf("output truncated: %q", got)
	}
}

func TestExecutor_PromptPlaceholderRejectsOtherHostnames(t *testing.T) {
	session := newMockSession("sw1>", "sw2#\r\n", "sw1#")
	e := &Executor{ReadTimeout: 10 * time.Millisecond, CommandTimeout: time.Second}

	result := e.Execute(session, []Command{{Command: "x", Expect: re(`^{prompt}#\s*$`)}})

	if !result.Success || !strings.HasSuffix(result.Output[0].Output, "sw1#") {
		t.Fatalf("unexpected result: %+v", result)
	}
}

func TestExecutor_PromptPlaceholderMatchesConfigModePrompt(t *testing.T) {
	session := newMockSession("sw1#", "conf t\r\nsw1(config)#")
	e := &Executor{ReadTimeout: 10 * time.Millisecond, CommandTimeout: time.Second}

	result := e.Execute(session, []Command{{Command: "conf t", Expect: re(`^{prompt}\(config\)#\s*$`)}})

	if !result.Success {
		t.Fatalf("expected success, got %q", result.Error)
	}
}

func TestExecutor_PromptPlaceholderLearnsPromptFromFirstOutputWhenInitialReadEmpty(t *testing.T) {
	session := newMockSession("", "sw9#", "junk #\r\n", "sw9#")
	e := &Executor{ReadTimeout: 10 * time.Millisecond, CommandTimeout: time.Second}

	result := e.Execute(session, []Command{
		{Command: "a", Expect: re(`^{prompt}#\s*$`)},
		{Command: "b", Expect: re(`^{prompt}#\s*$`)},
	})

	if !result.Success || !strings.HasSuffix(result.Output[1].Output, "sw9#") {
		t.Fatalf("unexpected result: %+v", result)
	}
}
