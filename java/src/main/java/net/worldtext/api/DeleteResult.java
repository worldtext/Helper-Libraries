package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/**
 * Result of a delete operation: destroying a group, removing an entry, or
 * emptying a group. Requires {@code groups}.
 */
public final class DeleteResult extends Model {

    private final String deleted;

    public DeleteResult(Map<String, Object> raw) {
        super(raw);
        this.deleted = firstOf(getRaw(), "deleted", "rows");
    }

    /**
     * Rows removed, as sent (e.g. {@code "2"}), or {@code null} when the route
     * reports only success - deleting a group itself says nothing more.
     */
    public String getDeleted() {
        return deleted;
    }

    /** Rows removed as a decimal for arithmetic, or {@code null} if absent/not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(deleted);
    }
}