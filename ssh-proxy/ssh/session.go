// Package ssh provides SSH connection management and command execution
// for interactive sessions with network devices (Cisco IOS).
package ssh

import "time"

// Session provides read/write access to an interactive SSH session.
// Implementations must be safe for sequential use but need not be safe
// for concurrent use — callers must serialize access.
type Session interface {
	// Write sends data to the SSH session's stdin.
	Write(data string) error

	// Read waits up to timeout for data from stdout.
	// Returns whatever data is available; empty string on timeout.
	Read(timeout time.Duration) string

	// Close terminates the SSH session and underlying connection.
	Close() error
}
