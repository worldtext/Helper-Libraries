package net.worldtext.api;

/**
 * HTTP 403: the token is valid but lacks the permission this operation needs.
 *
 * <p>{@link #getRequiredScope()} names the missing permission (for example
 * {@code "admin"}), so the caller can tell the user exactly which box to tick
 * on the token; it is read from the {@code WWW-Authenticate} header and is
 * {@code null} when the gateway did not send one.
 */
public class PermissionDeniedException extends WorldTextException {

    private static final long serialVersionUID = 1L;

    private final String requiredScope;

    public PermissionDeniedException(String message, Integer httpStatus, String errorCode,
                                     String desc, String requiredScope) {
        super(message, httpStatus, errorCode, desc);
        this.requiredScope = requiredScope;
    }

    /** The permission this operation needs, e.g. {@code "sms"}, or null if unknown. */
    public String getRequiredScope() {
        return requiredScope;
    }

    @Override
    public boolean isRetryable() {
        return false;
    }

    @Override
    public String toString() {
        return getClass().getSimpleName()
                + "[httpStatus=" + getHttpStatus()
                + ", errorCode=" + getErrorCode()
                + ", requiredScope=" + requiredScope
                + "]";
    }
}