package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/** One group in {@link GroupList}. Requires {@code groups}. */
public final class Group extends Model {

    private final String grpid;
    private final String name;
    private final String size;

    public Group(Map<String, Object> raw) {
        super(raw);
        this.grpid = firstOf(getRaw(), "grpid", "groupid", "id");
        this.name = firstOf(getRaw(), "name");
        this.size = firstOf(getRaw(), "size", "entries");
    }

    /**
     * Group id, e.g. {@code "4242"}. Read from {@code grpid}, {@code groupid} or
     * {@code id}: the API is inconsistent about the name across routes.
     */
    public String getGrpid() {
        return grpid;
    }

    /** Group name as set when it was created. */
    public String getName() {
        return name;
    }

    /** Number of numbers in the group, as sent (e.g. {@code "2"}). */
    public String getSize() {
        return size;
    }

    /** Group size as a decimal for arithmetic, or {@code null} if not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(size);
    }
}