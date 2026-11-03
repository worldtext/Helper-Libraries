package net.worldtext.api;

import java.util.Map;

/** Mobile number portability status. Requires {@code hlr}. */
public final class MnpResult extends Model {

    private final String mnpportstatus;
    private final String mnpportdate;

    public MnpResult(Map<String, Object> raw) {
        super(raw);
        this.mnpportstatus = firstOf(getRaw(), "mnpportstatus");
        this.mnpportdate = firstOf(getRaw(), "mnpportdate");
    }

    /** Porting status, e.g. {@code "ported"}. */
    public String getMnpportstatus() {
        return mnpportstatus;
    }

    /** Porting date, e.g. {@code "2020-01-01"}. */
    public String getMnpportdate() {
        return mnpportdate;
    }
}