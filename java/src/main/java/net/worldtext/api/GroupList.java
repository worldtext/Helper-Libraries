package net.worldtext.api;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;

/**
 * Result of {@link WorldText#listGroups}. Requires {@code groups}.
 *
 * <p>Holds one {@link Group} per entry of the response's {@code group} array.
 */
public final class GroupList extends Model {

    private final List<Group> groups;

    public GroupList(Map<String, Object> raw) {
        super(raw);
        List<Group> built = new ArrayList<Group>();
        Object value = raw.get("group");
        if (value instanceof Map) {
            // A single group may be sent bare rather than in an array.
            built.add(new Group(Json.map(raw, "group")));
        } else if (value instanceof List) {
            for (Object item : Json.list(raw, "group")) {
                if (item instanceof Map) {
                    @SuppressWarnings("unchecked")
                    Map<String, Object> object = (Map<String, Object>) item;
                    built.add(new Group(object));
                }
            }
        }
        this.groups = Collections.unmodifiableList(built);
    }

    /** Every group on the account, in the order sent. */
    public List<Group> getGroups() {
        return groups;
    }

    /** Number of groups listed. */
    public int size() {
        return groups.size();
    }
}