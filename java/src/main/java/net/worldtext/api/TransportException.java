package net.worldtext.api;

/**
 * The request never produced an HTTP response: DNS failure, connection
 * refused, TLS error or a timeout.
 *
 * <p>{@link #getHttpStatus()} is {@code null} in this case. The message names
 * the base URL only - never the full request URL, which can carry parameters.
 */
public class TransportException extends WorldTextException {

    private static final long serialVersionUID = 1L;

    public TransportException(String message, Throwable cause) {
        super(message, null, null, cause == null ? null : cause.toString());
        if (cause != null) {
            initCause(cause);
        }
    }

    @Override
    public String toString() {
        return getClass().getSimpleName() + "[" + getDesc() + "]";
    }
}