package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Map;

/** Country and network for a number. Requires {@code hlr}. */
public final class NumberInfo extends Model {

    private final String cc;
    private final String mcc;
    private final String mnc;
    private final String country;
    private final String network;
    private final String credits;

    public NumberInfo(Map<String, Object> raw) {
        super(raw);
        this.cc = firstOf(getRaw(), "cc");
        this.mcc = firstOf(getRaw(), "mcc");
        this.mnc = firstOf(getRaw(), "mnc");
        this.country = firstOf(getRaw(), "country");
        this.network = firstOf(getRaw(), "network");
        this.credits = firstOf(getRaw(), "credits");
    }

    /** Country calling code, e.g. {@code "44"}. */
    public String getCc() {
        return cc;
    }

    /** Mobile country code, e.g. {@code "234"}. */
    public String getMcc() {
        return mcc;
    }

    /** Mobile network code, e.g. {@code "10"}. */
    public String getMnc() {
        return mnc;
    }

    /** Country name, e.g. {@code "United Kingdom"}. */
    public String getCountry() {
        return country;
    }

    /** Network name, e.g. {@code "O2-UK"}. */
    public String getNetwork() {
        return network;
    }

    /** Credits charged for the lookup, e.g. {@code "1.0"}. */
    public String getCredits() {
        return credits;
    }

    /** Credits charged as a decimal for arithmetic, or {@code null} if not numeric. */
    public BigDecimal asDecimal() {
        return toDecimal(credits);
    }
}