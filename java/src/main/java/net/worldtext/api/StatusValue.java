package net.worldtext.api;

/**
 * The API's {@code status} flag, named for readability.
 *
 * <p>{@code "0"} is success, {@code "1"} a permanent failure, and {@code "2"}
 * or {@code "3"} a temporary failure that may be retried. These flag values
 * are unrelated to the per-message delivery {@code state} values.
 */
public final class StatusValue {

    /** Request succeeded. */
    public static final String SUCCESS = "0";

    /** Request failed and will fail again; do not retry. */
    public static final String PERMANENT = "1";

    /** First temporary-failure flag; the request may be retried. */
    public static final String TEMPORARY_1 = "2";

    /** Second temporary-failure flag; the request may be retried. */
    public static final String TEMPORARY_2 = "3";

    private StatusValue() {
    }

    /** True for {@code "2"} and {@code "3"}: the caller may retry. */
    public static boolean isTemporary(String status) {
        return TEMPORARY_1.equals(status) || TEMPORARY_2.equals(status);
    }

    /** True for {@code "0"}. */
    public static boolean isSuccess(String status) {
        return SUCCESS.equals(status);
    }
}