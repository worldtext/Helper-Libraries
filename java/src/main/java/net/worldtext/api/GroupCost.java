package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/**
 * Cost of sending to a whole group. Requires {@code sms}, not {@code groups}.
 */
public final class GroupCost extends Model {

    private final String credits;
    private final String entrycount;

    public GroupCost(Map<String, Object> raw) {
        super(raw);
        this.credits = firstOf(getRaw(), "credits");
        this.entrycount = firstOf(getRaw(), "entrycount", "entries");
    }

    /** Total price exactly as sent, e.g. {@code "2.0"}. */
    public String getCredits() {
        return credits;
    }

    /** Number of numbers the price covers, e.g. {@code "2"}. */
    public String getEntrycount() {
        return entrycount;
    }

    /** Total price as a decimal for arithmetic, or {@code null} if not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(credits);
    }
}