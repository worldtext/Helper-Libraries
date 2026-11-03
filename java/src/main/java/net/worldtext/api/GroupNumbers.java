package net.worldtext.api;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;

/** The numbers in a group. Requires {@code groups}. */
public final class GroupNumbers extends Model {

    private final List<String> numbers;

    public GroupNumbers(Map<String, Object> raw) {
        this(raw, readNumbers(raw));
    }

    private GroupNumbers(Map<String, Object> raw, List<String> numbers) {
        super(raw);
        this.numbers = Collections.unmodifiableList(numbers);
    }

    /**
     * The numbers, in the order sent. Each may arrive either bare
     * ({@code ["447989000001"]}) or as an object ({@code [{"number": ...}]});
     * both forms are flattened.
     */
    public List<String> getNumbers() {
        return numbers;
    }

    private static List<String> readNumbers(Map<String, Object> raw) {
        List<Object> items = raw.containsKey("numbers") && raw.get("numbers") instanceof List
                ? Json.list(raw, "numbers")
                : Json.list(raw, "entry");
        List<String> out = new ArrayList<String>(items.size());
        for (Object item : items) {
            if (item instanceof String) {
                out.add((String) item);
            } else if (item instanceof Map) {
                @SuppressWarnings("unchecked")
                Map<String, Object> object = (Map<String, Object>) item;
                out.add(firstOf(object, "number"));
            }
        }
        return out;
    }
}