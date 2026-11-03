package net.worldtext.api;

import java.util.Map;

/** HLR lookup. Requires {@code hlr}. Costs a credit. */
public final class HlrResult extends Model {

    private final String docomo;
    private final String region;
    private final String info;
    private final String error;

    public HlrResult(Map<String, Object> raw) {
        super(raw);
        this.docomo = firstOf(getRaw(), "docomo");
        this.region = firstOf(getRaw(), "region");
        this.info = firstOf(getRaw(), "info");
        this.error = firstOf(getRaw(), "error");
    }

    /** Raw docomo field as sent, e.g. {@code "74450"}. */
    public String getDocomo() {
        return docomo;
    }

    /** Network region, e.g. {@code "UK"}. */
    public String getRegion() {
        return region;
    }

    /** Human-readable network name. */
    public String getInfo() {
        return info;
    }

    /** Lookup error code, e.g. {@code "0"} for none. */
    public String getError() {
        return error;
    }
}