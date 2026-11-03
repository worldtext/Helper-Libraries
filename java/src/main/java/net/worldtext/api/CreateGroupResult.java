package net.worldtext.api;

import java.util.Map;

/** Result of {@link WorldText#createGroup}. Requires {@code groups}. */
public final class CreateGroupResult extends Model {

    private final String grpid;

    public CreateGroupResult(Map<String, Object> raw) {
        super(raw);
        this.grpid = firstOf(getRaw(), "grpid", "groupid", "id");
    }

    /** The new group's id, e.g. {@code "4242"}. */
    public String getGrpid() {
        return grpid;
    }
}