package ssh

import "time"

type Session interface {
	Write(data string) error

	Read(timeout time.Duration) string

	Close() error
}
