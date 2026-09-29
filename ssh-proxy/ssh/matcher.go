package ssh

import (
	"fmt"
	"regexp"
	"strings"
)

type MatchType string

const (
	MatchLiteral MatchType = "literal"
	MatchRegex   MatchType = "regex"
)

type Matcher struct {
	Type  MatchType `json:"type"`
	Value string    `json:"value"`
}

func (m *Matcher) Validate() error {
	if m.Value == "" {
		return fmt.Errorf("matcher value must not be empty")
	}
	switch m.Type {
	case MatchLiteral:
		return nil
	case MatchRegex:
		if _, err := regexp.Compile((&promptTracker{}).expand(m.Value)); err != nil {
			return fmt.Errorf("invalid regex %q: %w", m.Value, err)
		}
		return nil
	}
	return fmt.Errorf("unknown matcher type %q", m.Type)
}

func (m *Matcher) String() string {
	if m == nil {
		return ""
	}
	return string(m.Type) + ":" + m.Value
}

func (m *Matcher) compile(p *promptTracker) (func(string) bool, error) {
	if err := m.Validate(); err != nil {
		return nil, err
	}
	if m.Type == MatchLiteral {
		want := m.Value
		if p.hostname != "" {
			want = strings.ReplaceAll(want, promptPlaceholder, p.hostname)
		}
		return func(text string) bool { return strings.Contains(text, want) }, nil
	}
	re, err := regexp.Compile(p.expand(m.Value))
	if err != nil {
		return nil, err
	}
	return re.MatchString, nil
}
