package net.worldtext.api;

/**
 * HTTP 401: the token is missing, malformed, unknown or revoked.
 *
 * <p>Retrying with the same token will not help; obtain a new one.
 */
public class AuthenticationException extends WorldTextException {

    private static final long serialVersionUID = 1L;

    public AuthenticationException(String message, Integer httpStatus, String errorCode, String desc) {
        super(message, httpStatus, errorCode, desc);
    }

    @Override
    public boolean isRetryable() {
        return false;
    }
}