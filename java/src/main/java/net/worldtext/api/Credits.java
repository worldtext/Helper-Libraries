package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/**
 * Account credit balance. Requires the {@code admin} permission.
 *
 * <p>The balance arrives as a JSON string ({@code "credits":"2950"}); it is
 * kept that way so nothing is lost in a float round-trip.
 */
public final class Credits extends Model {

    private final String credits;

    public Credits(Map<String, Object> raw) {
        super(raw);
        this.credits = firstOf(getRaw(), "credits");
    }

    /** The balance exactly as the gateway sent it, e.g. {@code "2950"}. */
    public String getCredits() {
        return credits;
    }

    /** The balance as a decimal for arithmetic, or {@code null} if not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(credits);
    }
}