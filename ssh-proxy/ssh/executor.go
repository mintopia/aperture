package ssh

import (
	"errors"
	"fmt"
	"io"
	"log/slog"
	"regexp"
	"strings"
	"time"
)

type Command struct {
	Command   string   `json:"command"`
	Sensitive bool     `json:"sensitive,omitempty"`
	If        *Matcher `json:"if,omitempty"`
	Expect    *Matcher `json:"expect,omitempty"`
}

const redacted = "****"

func (c Command) LogString() string {
	if c.Sensitive {
		return redacted
	}
	return c.Command
}

func (c Command) logTail(s string) string {
	if c.Sensitive {
		return redacted
	}
	return s
}

type CommandOutput struct {
	Command string `json:"command"`
	Output  string `json:"output"`
}

type CommandResult struct {
	Success bool            `json:"success"`
	Output  []CommandOutput `json:"output"`
	Error   string          `json:"error,omitempty"`
	Err     error           `json:"-"`
}

type Executor struct {
	ReadTimeout    time.Duration
	CommandTimeout time.Duration
	Logger         *slog.Logger
}

func (e *Executor) Execute(session Session, commands []Command) *CommandResult {
	output := make([]CommandOutput, 0, len(commands))

	logger := e.logger()
	prompt := &promptTracker{}

	logger.Debug("reading initial prompt")
	lastOutput := session.Read(e.ReadTimeout)
	prompt.learn(lastOutput)
	logger.Debug("initial prompt received",
		"output_length", len(lastOutput),
		"last_line", getLastLine(lastOutput),
		"raw_tail", safeTail(lastOutput, 100),
	)

	for i, cmd := range commands {
		logger.Debug("processing command",
			"index", i,
			"command", cmd.LogString(),
			"if", cmd.If.String(),
			"expect", cmd.Expect.String(),
		)

		if cmd.If != nil {
			ifMatch, err := cmd.If.compile(prompt)
			if err != nil {
				return failure(output, fmt.Sprintf("Invalid if condition: %s", err), err)
			}
			lastLine := getLastLine(lastOutput)
			matches := ifMatch(lastLine)
			logger.Debug("if condition check",
				"condition", cmd.If.String(),
				"last_line", lastLine,
				"matches", matches,
			)
			if !matches {
				logger.Debug("skipping command (if condition not met)", "index", i)
				continue
			}
		}

		var expectMatch func(string) bool
		if cmd.Expect != nil {
			var err error
			if expectMatch, err = cmd.Expect.compile(prompt); err != nil {
				return failure(output, fmt.Sprintf("Invalid expect pattern: %s", err), err)
			}
		}

		logger.Debug("sending command", "index", i, "command", cmd.LogString())
		if err := session.Write(cmd.Command + "\n"); err != nil {
			logger.Error("failed to send command",
				"index", i,
				"command", cmd.LogString(),
				"error", err,
			)
			return failure(output, fmt.Sprintf("Failed to send command: %s", err), err)
		}

		if expectMatch != nil {
			logger.Debug("waiting for expected pattern",
				"index", i,
				"expect", cmd.Expect.String(),
				"timeout", e.CommandTimeout,
			)
			result, err := e.readUntilExpect(session, cmd, expectMatch)
			if err != nil {
				logger.Error("expect pattern timeout",
					"index", i,
					"command", cmd.LogString(),
					"expect", cmd.Expect.String(),
					"buffer_length", len(result),
					"last_line", cmd.logTail(getLastLine(result)),
					"raw_tail", cmd.logTail(safeTail(result, 200)),
					"error", err,
				)
				return failure(output, err.Error(), err)
			}
			logger.Debug("expected pattern matched",
				"index", i,
				"output_length", len(result),
				"last_line", cmd.logTail(getLastLine(result)),
			)
			lastOutput = result
		} else {
			logger.Debug("reading response (no expect)", "index", i, "timeout", e.ReadTimeout)
			lastOutput = session.Read(e.ReadTimeout)
			logger.Debug("response received",
				"index", i,
				"output_length", len(lastOutput),
				"last_line", cmd.logTail(getLastLine(lastOutput)),
			)
		}

		output = append(output, CommandOutput{
			Command: cmd.Command,
			Output:  lastOutput,
		})
	}

	logger.Debug("all commands executed successfully", "output_count", len(output))
	return &CommandResult{
		Success: true,
		Output:  output,
	}
}

func failure(output []CommandOutput, msg string, err error) *CommandResult {
	return &CommandResult{Success: false, Output: output, Error: msg, Err: err}
}

var ErrExpectTimeout = errors.New("Timeout waiting for expected pattern")

func (e *Executor) readUntilExpect(session Session, cmd Command, match func(string) bool) (string, error) {
	var buffer strings.Builder
	deadline := time.Now().Add(e.CommandTimeout)
	iterations := 0
	logger := e.logger()

	for time.Now().Before(deadline) {
		remaining := time.Until(deadline)
		if remaining <= 0 {
			break
		}

		readTimeout := 500 * time.Millisecond
		if readTimeout > remaining {
			readTimeout = remaining
		}

		chunk := session.Read(readTimeout)
		iterations++
		if chunk != "" {
			buffer.WriteString(chunk)
			lastLine := getLastLine(buffer.String())
			matched := match(lastLine)
			logger.Debug("readUntilExpect chunk",
				"iteration", iterations,
				"chunk_length", len(chunk),
				"buffer_length", buffer.Len(),
				"last_line", cmd.logTail(lastLine),
				"matches", matched,
			)
			if matched {
				return buffer.String(), nil
			}
		}
	}

	logger.Debug("readUntilExpect exhausted",
		"iterations", iterations,
		"buffer_length", buffer.Len(),
		"last_line", cmd.logTail(getLastLine(buffer.String())),
		"raw_tail", cmd.logTail(safeTail(buffer.String(), 200)),
	)
	return buffer.String(), fmt.Errorf("%w: %s", ErrExpectTimeout, cmd.Expect.Value)
}

const promptPlaceholder = "{prompt}"

var promptLineRe = regexp.MustCompile(`^([^\s()#>]+)(?:\([^)]*\))?[>#]\s*$`)

type promptTracker struct {
	hostname string
}

func (p *promptTracker) learn(output string) {
	if m := promptLineRe.FindStringSubmatch(getLastLine(output)); m != nil {
		p.hostname = m[1]
	}
}

func (p *promptTracker) expand(pattern string) string {
	host := `[^\s()#>]+`
	if p.hostname != "" {
		host = regexp.QuoteMeta(p.hostname)
	}
	return strings.ReplaceAll(pattern, promptPlaceholder, host)
}

func getLastLine(output string) string {
	trimmed := strings.TrimSpace(output)
	if trimmed == "" {
		return ""
	}
	lines := strings.Split(trimmed, "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if lines[i] != "" {
			return lines[i]
		}
	}
	return ""
}

func (e *Executor) logger() *slog.Logger {
	if e != nil && e.Logger != nil {
		return e.Logger
	}
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func safeTail(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return "..." + s[len(s)-n:]
}
