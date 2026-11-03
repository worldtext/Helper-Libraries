package net.worldtext.api;

import java.util.Map;

/** Name of the host that served the request. Requires {@code admin}. */
public final class HostName extends Model {

    private final String hostname;

    public HostName(Map<String, Object> raw) {
        super(raw);
        this.hostname = firstOf(getRaw(), "hostname");
    }

    public String getHostname() {
        return hostname;
    }
}