package net.worldtext.api;

import java.util.Map;

/**
 * Delivery state of a sent message. Requires {@code sms}.
 *
 * <p>{@code state} and {@code error} are the gateway's own codes, kept as the
 * strings it sent.
 */
public final class SmsStatus extends Model {

    private final String state;
    private final String error;
    private final String finaldate;

    public SmsStatus(Map<String, Object> raw) {
        super(raw);
        this.state = firstOf(getRaw(), "state");
        this.error = firstOf(getRaw(), "error");
        this.finaldate = firstOf(getRaw(), "finaldate");
    }

    /** Delivery state code, e.g. {@code "5"}. */
    public String getState() {
        return state;
    }

    /** Delivery error code, e.g. {@code "0"} for none. */
    public String getError() {
        return error;
    }

    /** When the final state was reached, or {@code null} while still in flight. */
    public String getFinaldate() {
        return finaldate;
    }

    /** False while the message is still in flight (no {@code finaldate} yet). */
    public boolean isFinal() {
        return finaldate != null && !finaldate.isEmpty();
    }
}