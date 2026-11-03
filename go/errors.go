package worldtext

import (
	"errors"
	"fmt"
)

// Sentinel errors for use with errors.Is. Every error returned by this package
// matches exactly one of these three, so callers can branch on the kind of
// failure without inspecting messages:
//
//	if errors.Is(err, worldtext.ErrPermission) {
//		var pe *worldtext.PermissionError
//		errors.As(err, &pe)
//		fmt.Println("token needs the", pe.RequiredScope, "permission")
//	}
var (
	// ErrAuthentication matches responses with HTTP 401: the token was
	// missing, malformed, unknown or revoked.
	ErrAuthentication = errors.New("worldtext: authentication failed")

	// ErrPermission matches responses with HTTP 403: the token is valid but
	// does not carry the permission the operation needs.
	ErrPermission = errors.New("worldtext: permission denied")

	// ErrAPI matches every other failure: argument validation (400),
	// insufficient funds, server errors, transport problems.
	ErrAPI = errors.New("worldtext: api error")
)

// StatusFlag is the API's "status" string: "0" success, "1" permanent
// failure, "2"/"3" temporary failure that may be retried.
type StatusFlag string

// The values the gateway uses for the "status" field.
const (
	StatusSuccess   StatusFlag = "0"
	StatusPermanent StatusFlag = "1"
	StatusTemporary StatusFlag = "2"
	StatusRetry     StatusFlag = "3"
)

// Error is the base type for every failure returned by this package.
//
// It carries the machine-readable facts - HTTP status, API error code and the
// gateway description - so callers never have to match on message text. The
// API token is never stored here: an Error is always safe to log.
//
// The concrete type handed back is always an *AuthenticationError,
// *PermissionError or *APIError, all of which match their kind with errors.Is
// and can be inspected for these fields with errors.As:
//
//	var base *worldtext.Error
//	if errors.As(err, &base) && base.Retryable() { ... }
type Error struct {
	// HTTPStatus is the status code that produced the error, or 0 for a
	// transport failure where no response was received.
	HTTPStatus int
	// Code is the gateway's numeric error code, e.g. "1200"; empty if the
	// response carried none.
	Code string
	// Desc is the gateway's description of the failure, or the transport
	// error. It never contains the token.
	Desc string
	// Status is the envelope's "status" flag, when one was supplied.
	Status StatusFlag
	// Err is the underlying cause for a transport failure, if any.
	Err error

	// Msg is the display message. When empty, Error() composes one from
	// Desc, Code and HTTPStatus.
	Msg string
}

// Error implements error.
func (e *Error) Error() string {
	if e.Msg != "" {
		return e.Msg
	}
	return fmt.Sprintf("worldtext: %s (error %s, HTTP %d)", e.Desc, e.Code, e.HTTPStatus)
}

// Unwrap reports the sentinel matching this error's kind, and the underlying
// transport cause if there is one. It never returns the token.
func (e *Error) Unwrap() []error {
	out := make([]error, 0, 2)
	switch e.HTTPStatus {
	case 401:
		out = append(out, ErrAuthentication)
	case 403:
		out = append(out, ErrPermission)
	default:
		out = append(out, ErrAPI)
	}
	if e.Err != nil {
		out = append(out, e.Err)
	}
	return out
}

// Retryable reports whether the failure is worth retrying: the envelope said
// "temporary" (status "2" or "3"), or the server answered with a 5xx.
func (e *Error) Retryable() bool {
	if e.Status == StatusTemporary || e.Status == StatusRetry {
		return true
	}
	return e.HTTPStatus >= 500
}

// newKindError chooses the concrete type for an envelope, by HTTP status.
func newKindError(base *Error, requiredScope string) error {
	switch base.HTTPStatus {
	case 401:
		return &AuthenticationError{Base: base}
	case 403:
		return &PermissionError{Base: base, RequiredScope: requiredScope}
	default:
		return &APIError{Base: base}
	}
}

// AuthenticationError is returned for HTTP 401. It matches
// errors.Is(err, ErrAuthentication).
type AuthenticationError struct {
	// Base is the shared detail: HTTP status, API code, description.
	Base *Error
}

// Error implements error.
func (e *AuthenticationError) Error() string { return e.Base.Error() }

// Unwrap reports ErrAuthentication and the embedded *Error, so both
// errors.Is(err, ErrAuthentication) and errors.As(err, &base) work.
func (e *AuthenticationError) Unwrap() []error { return []error{ErrAuthentication, e.Base} }

// PermissionError is returned for HTTP 403. It matches
// errors.Is(err, ErrPermission).
//
// RequiredScope is the permission the operation needs, taken from the
// WWW-Authenticate header, e.g. "admin" - it tells the caller which permission
// to add to their token. It is empty when the gateway did not say.
type PermissionError struct {
	// Base is the shared detail: HTTP status, API code, description.
	Base *Error

	RequiredScope string
}

// Error implements error.
func (e *PermissionError) Error() string {
	return fmt.Sprintf("worldtext: permission denied: %s (scope %q required)", e.Base.Error(), e.RequiredScope)
}

// Unwrap reports ErrPermission and the embedded *Error.
func (e *PermissionError) Unwrap() []error { return []error{ErrPermission, e.Base} }

// Retryable is always false for a permission failure; present so every
// concrete error type offers it.
func (e *PermissionError) Retryable() bool { return e.Base.Retryable() }

// APIError is every other failure: validation (400), insufficient funds,
// timeouts, 5xx, malformed responses. It matches errors.Is(err, ErrAPI).
type APIError struct {
	// Base is the shared detail: HTTP status, API code, description.
	Base *Error
}

// Error implements error.
func (e *APIError) Error() string { return e.Base.Error() }

// Unwrap reports ErrAPI and the embedded *Error.
func (e *APIError) Unwrap() []error { return []error{ErrAPI, e.Base} }

// Retryable reports whether the failure is worth retrying.
func (e *APIError) Retryable() bool { return e.Base.Retryable() }

// ConfigError reports a client that could not be constructed: a missing token
// or an unusable base URL. It never contains the token.
type ConfigError struct {
	Msg string
}

// Error implements error.
func (e *ConfigError) Error() string { return "worldtext: " + e.Msg }
