package net.worldtext.api;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;

/** The numbers in a group with their status. Requires {@code groups}. */
public final class GroupDetails extends Model {

    private final List<GroupEntry> entries;

    public GroupDetails(Map<String, Object> raw) {
        super(raw);
        List<Object> items = raw.containsKey("details") && raw.get("details") instanceof List
                ? Json.list(raw, "details")
                : Json.list(raw, "entry");
        List<GroupEntry> built = new ArrayList<GroupEntry>(items.size());
        for (Object item : items) {
            if (item instanceof Map) {
                @SuppressWarnings("unchecked")
                Map<String, Object> object = (Map<String, Object>) item;
                built.add(new GroupEntry(firstOf(object, "number"), firstOf(object, "status")));
            } else if (item instanceof String) {
                built.add(new GroupEntry((String) item, null));
            }
        }
        this.entries = Collections.unmodifiableList(built);
    }

    /** One {@link GroupEntry} per number, in the order sent. */
    public List<GroupEntry> getEntries() {
        return entries;
    }
}