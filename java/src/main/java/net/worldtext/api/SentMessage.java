package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/** One destination's outcome within a {@link SendResult}. */
public final class SentMessage extends Model {

    private final String msgid;
    private final String dstaddr;
    private final String credits;
    private final String status;
    private final String sendtime;

    public SentMessage(Map<String, Object> raw) {
        super(raw);
        this.msgid = firstOf(getRaw(), "msgid");
        this.dstaddr = firstOf(getRaw(), "dstaddr");
        this.credits = firstOf(getRaw(), "credits");
        this.status = firstOf(getRaw(), "status");
        this.sendtime = firstOf(getRaw(), "sendtime");
    }

    /** Message id, e.g. {@code "10001"} - a string, as sent. */
    public String getMsgid() {
        return msgid;
    }

    /** Destination number, e.g. {@code "447989000001"}. */
    public String getDstaddr() {
        return dstaddr;
    }

    /** Credits charged for this message, e.g. {@code "1.0"}. */
    public String getCredits() {
        return credits;
    }

    /** Per-message status as sent, e.g. {@code "5"}. */
    public String getStatus() {
        return status;
    }

    /** When it was accepted, e.g. {@code "2026-10-25 12:00:00"}. */
    public String getSendtime() {
        return sendtime;
    }

    /** Credits charged as a decimal for arithmetic, or {@code null} if not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(credits);
    }
}