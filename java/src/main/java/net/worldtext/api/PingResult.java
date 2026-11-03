package net.worldtext.api;

import java.util.Map;

/** Response to {@link WorldText#ping()}. Requires the {@code admin} permission. */
public final class PingResult extends Model {

    private final boolean ok;

    public PingResult(Map<String, Object> raw) {
        super(raw);
        this.ok = StatusValue.isSuccess(Json.string(getRaw(), "status"));
    }

    /** True when the gateway answered with {@code "status":"0"}. */
    public boolean isOk() {
        return ok;
    }
}