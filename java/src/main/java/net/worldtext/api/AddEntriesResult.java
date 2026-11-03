package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/** Result of adding one or more numbers to a group. Requires {@code groups}. */
public final class AddEntriesResult extends Model {

    private final String added;
    private final String failed;

    public AddEntriesResult(Map<String, Object> raw) {
        super(raw);
        this.added = firstOf(getRaw(), "added");
        this.failed = firstOf(getRaw(), "failed");
    }

    /** How many numbers were added, as sent, e.g. {@code "2"}. */
    public String getAdded() {
        return added;
    }

    /** How many were rejected, as sent, e.g. {@code "0"}. */
    public String getFailed() {
        return failed;
    }

    /** Numbers added as a decimal for arithmetic, or {@code null} if not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(added);
    }
}