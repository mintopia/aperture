package ssh

import (
	"fmt"
	"io"
	"log/slog"
	"regexp"
	"strings"
	"time"
)

type Command struct {
	Command string `json:"command"`
	If      string `json:"if,omitempty"`
	Expect  string `json:"expect,omitempty"`
}

type CommandOutput struct {
	Command string `json:"command"`
	Output  string `json:"output"`
}

type CommandResult struct {
	Success bool            `json:"success"`
	Output  []CommandOutput `json:"output"`
	Error   string          `json:"error,omitempty"`
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
			"command", SanitizeForLog(cmd.Command),
			"if", cmd.If,
			"expect", cmd.Expect,
		)

		if cmd.If != "" {
			lastLine := getLastLine(lastOutput)
			matches := matchesCondition(lastLine, prompt.expand(cmd.If))
			logger.Debug("if condition check",
				"condition", cmd.If,
				"last_line", lastLine,
				"matches", matches,
			)
			if !matches {
				logger.Debug("skipping command (if condition not met)", "index", i)
				continue
			}
		}

		logger.Debug("sending command", "index", i, "command", SanitizeForLog(cmd.Command))
		if err := session.Write(cmd.Command + "\n"); err != nil {
			logger.Error("failed to send command",
				"index", i,
				"command", SanitizeForLog(cmd.Command),
				"error", err,
			)
			return &CommandResult{
				Success: false,
				Output:  output,
				Error:   fmt.Sprintf("Failed to send command: %s", err),
			}
		}

		if cmd.Expect != "" {
			logger.Debug("waiting for expected pattern",
				"index", i,
				"expect", cmd.Expect,
				"timeout", e.CommandTimeout,
			)
			result, err := e.readUntilExpect(session, cmd.Expect, prompt)
			if err != nil {
				logger.Error("expect pattern timeout",
					"index", i,
					"command", SanitizeForLog(cmd.Command),
					"expect", cmd.Expect,
					"buffer_length", len(result),
					"last_line", getLastLine(result),
					"raw_tail", safeTail(result, 200),
					"error", err,
				)
				return &CommandResult{
					Success: false,
					Output:  output,
					Error:   err.Error(),
				}
			}
			logger.Debug("expected pattern matched",
				"index", i,
				"output_length", len(result),
				"last_line", getLastLine(result),
			)
			lastOutput = result
		} else {
			logger.Debug("reading response (no expect)", "index", i, "timeout", e.ReadTimeout)
			lastOutput = session.Read(e.ReadTimeout)
			logger.Debug("response received",
				"index", i,
				"output_length", len(lastOutput),
				"last_line", getLastLine(lastOutput),
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

func (e *Executor) readUntilExpect(session Session, expect string, prompt *promptTracker) (string, error) {
	var buffer strings.Builder
	deadline := time.Now().Add(e.CommandTimeout)
	iterations := 0
	logger := e.logger()
	expect = prompt.expand(expect)

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
			logger.Debug("readUntilExpect chunk",
				"iteration", iterations,
				"chunk_length", len(chunk),
				"buffer_length", buffer.Len(),
				"last_line", lastLine,
				"expect", expect,
				"matches", matchesCondition(lastLine, expect),
			)
			if matchesCondition(lastLine, expect) {
				return buffer.String(), nil
			}
		}
	}

	logger.Debug("readUntilExpect exhausted",
		"iterations", iterations,
		"buffer_length", buffer.Len(),
		"last_line", getLastLine(buffer.String()),
		"raw_tail", safeTail(buffer.String(), 200),
	)
	return buffer.String(), fmt.Errorf("Timeout waiting for expected pattern: %s", expect)
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

func matchesCondition(text, condition string) bool {
	if len(condition) > 2 && condition[0] == '/' && condition[len(condition)-1] == '/' {
		pattern := condition[1 : len(condition)-1]
		matched, err := regexp.MatchString(pattern, text)
		return err == nil && matched
	}
	return strings.Contains(text, condition)
}

func (e *Executor) logger() *slog.Logger {
	if e != nil && e.Logger != nil {
		return e.Logger
	}
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func SanitizeForLog(cmd string) string {
	if len(cmd) < 50 &&
		!strings.Contains(cmd, " ") &&
		!strings.HasPrefix(cmd, "show") &&
		!strings.HasPrefix(cmd, "terminal") &&
		!strings.HasPrefix(cmd, "en") &&
		!strings.HasPrefix(cmd, "configure") &&
		!strings.HasPrefix(cmd, "interface") &&
		!strings.HasPrefix(cmd, "no ") &&
		!strings.HasPrefix(cmd, "shutdown") &&
		!strings.HasPrefix(cmd, "end") &&
		!strings.HasPrefix(cmd, "write") {
		return "****"
	}
	return cmd
}

func safeTail(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return "..." + s[len(s)-n:]
}
