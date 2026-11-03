"""Typed errors for the World Text API.

Callers should be able to branch on *why* a call failed without matching on
message text, so every failure carries the HTTP status and, where the gateway
supplied one, the numeric API error code from the JSON envelope.

The token is never held here. Errors are safe to log.
"""

from typing import Optional


class WorldTextError(Exception):
    """Base class for every failure raised by this client.

    Attributes:
        http_status: HTTP status returned, or None for a transport failure.
        error_code:  gateway error code as a string, e.g. '1200', or None.
        desc:        gateway description, or the transport error.
    """

    def __init__(self, message, http_status=None, error_code=None, desc=None):
        super().__init__(message)
        self.http_status = http_status
        self.error_code = error_code
        self.desc = desc

    @property
    def retryable(self):
        """True when the gateway said the failure is temporary.

        The envelope's ``status`` of '2' or '3' means "may be retried"; '1' is
        permanent. 5xx from a proxy is also treated as retryable.
        """
        return self.status_flag in ("2", "3") or (
            self.http_status is not None and self.http_status >= 500
        )

    # Set by the client when parsing an error envelope; default to permanent.
    status_flag = "1"

    def __repr__(self):
        return "<%s http_status=%r error_code=%r>" % (
            type(self).__name__,
            self.http_status,
            self.error_code,
        )


class AuthenticationError(WorldTextError):
    """401: the token is missing, malformed, unknown or revoked."""


class PermissionError_(WorldTextError):
    """403: the token is valid but lacks the permission this operation needs."""

    def __init__(self, message, http_status=403, error_code=None, desc=None,
                 required_scope=None):
        super().__init__(message, http_status, error_code, desc)
        self.required_scope = required_scope


class ApiError(WorldTextError):
    """Any other error response: validation (400), insufficient funds, 5xx."""