package net.worldtext.api;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;

/**
 * Result of {@link WorldText#sendSms} or {@link WorldText#sendGroupSms}.
 *
 * <p>A send returns one {@link SentMessage} per destination, under {@code result}.
 */
public final class SendResult extends Model {

    private final List<SentMessage> messages;

    public SendResult(Map<String, Object> raw) {
        super(raw);
        List<SentMessage> built = new ArrayList<SentMessage>();
        for (Map<String, Object> item : items(getRaw())) {
            built.add(new SentMessage(item));
        }
        this.messages = Collections.unmodifiableList(built);
    }

    /** One entry per destination. */
    public List<SentMessage> getMessages() {
        return messages;
    }

    /**
     * First message id, or {@code null} - the common single-recipient case.
     * Use {@link #getMessages()} for the multi-recipient case.
     */
    public String getMsgid() {
        return messages.isEmpty() ? null : messages.get(0).getMsgid();
    }

    /** {@code result} as a list of objects, tolerating a single bare object. */
    static List<Map<String, Object>> items(Map<String, Object> raw) {
        List<Map<String, Object>> out = new ArrayList<Map<String, Object>>();
        Object value = raw.get("result");
        if (value instanceof Map) {
            out.add(Json.map(raw, "result"));
        } else if (value instanceof List) {
            for (Object element : Json.list(raw, "result")) {
                if (element instanceof Map) {
                    @SuppressWarnings("unchecked")
                    Map<String, Object> object = (Map<String, Object>) element;
                    out.add(object);
                }
            }
        }
        return out;
    }
}