package net.worldtext.api;

/**
 * Any other error response from the gateway.
 *
 * <p>This covers argument validation ({@code 1200} on HTTP 400), insufficient
 * funds, unknown commands ({@code 1201}) and 5xx failures. Check
 * {@link #isRetryable()} before trying again, and {@link #getErrorCode()} to
 * distinguish cases.
 */
public class ApiException extends WorldTextException {

    private static final long serialVersionUID = 1L;

    public ApiException(String message, Integer httpStatus, String errorCode, String desc) {
        super(message, httpStatus, errorCode, desc);
    }
}