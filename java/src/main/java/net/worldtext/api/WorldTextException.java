package net.worldtext.api;

/**
 * Base class for every failure raised by this client.
 *
 * <p>Callers should branch on <em>why</em> a call failed without matching on
 * message text, so every failure carries the HTTP status and, where the gateway
 * supplied one, the numeric API error code from the JSON envelope:
 *
 * <pre>{@code
 * try {
 *     client.getCredits();
 * } catch (AuthenticationException e) {   // 401
 *     ...
 * } catch (PermissionDeniedException e) { // 403
 *     System.err.println("token needs the " + e.getRequiredScope() + " permission");
 * } catch (ApiException e) {              // 400, 5xx, ...
 *     if (e.isRetryable()) { ... }
 * }
 * }</pre>
 *
 * <p>Errors never hold the token: they are safe to log and to show to a user.
 */
public class WorldTextException extends RuntimeException {

    private static final long serialVersionUID = 1L;

    private final Integer httpStatus;
    private final String errorCode;
    private final String desc;
    private String statusFlag = StatusValue.PERMANENT;

    /**
     * @param message    human-readable summary, already redacted
     * @param httpStatus HTTP status, or {@code null} for a transport failure
     * @param errorCode  gateway error code as a string, e.g. {@code "1200"}, or null
     * @param desc       gateway description, or the transport error text
     */
    public WorldTextException(String message, Integer httpStatus, String errorCode, String desc) {
        super(message);
        this.httpStatus = httpStatus;
        this.errorCode = errorCode;
        this.desc = desc;
    }

    /** HTTP status returned, or {@code null} if the request never got a response. */
    public Integer getHttpStatus() {
        return httpStatus;
    }

    /** Gateway error code, e.g. {@code "1200"}, or {@code null} if none was supplied. */
    public String getErrorCode() {
        return errorCode;
    }

    /** Gateway description, or the transport error text. */
    public String getDesc() {
        return desc;
    }

    /**
     * The envelope's {@code status} flag: {@link StatusValue#SUCCESS "0"},
     * {@link StatusValue#PERMANENT "1"} or a temporary {@code "2"}/{@code "3"}.
     * Defaults to permanent when the gateway did not say.
     */
    public String getStatusFlag() {
        return statusFlag;
    }

    void setStatusFlag(String statusFlag) {
        this.statusFlag = statusFlag != null ? statusFlag : StatusValue.PERMANENT;
    }

    /**
     * Whether the same call may succeed if retried.
     *
     * <p>True when the gateway flagged the failure as temporary
     * ({@link StatusValue#isTemporary(String)}) or when a proxy returned a 5xx.
     * Authentication and permission failures are never retryable.
     */
    public boolean isRetryable() {
        if (StatusValue.isTemporary(statusFlag)) {
            return true;
        }
        return httpStatus != null && httpStatus >= 500;
    }

    @Override
    public String toString() {
        return getClass().getSimpleName()
                + "[httpStatus=" + httpStatus
                + ", errorCode=" + errorCode
                + ", retryable=" + isRetryable() + "]";
    }
}