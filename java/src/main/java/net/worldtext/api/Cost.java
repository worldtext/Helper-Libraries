package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/** Price of a single message, without sending it. Requires {@code sms}. */
public final class Cost extends Model {

    private final String credits;

    public Cost(Map<String, Object> raw) {
        super(raw);
        this.credits = firstOf(getRaw(), "credits");
    }

    /** The price exactly as sent, e.g. {@code "1.0"}. */
    public String getCredits() {
        return credits;
    }

    /** The price as a decimal for arithmetic, or {@code null} if not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(credits);
    }
}