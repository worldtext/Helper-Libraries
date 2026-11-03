package net.worldtext.api;

import java.math.BigDecimal;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Base class for typed responses.
 *
 * <p>Each model keeps the raw JSON object it was built from, so fields this
 * version does not know about are still reachable via {@link #getRaw()} and
 * {@link #get(String)}. Models hold no credentials and are safe to log, but
 * they do hold message text and phone numbers, which may be sensitive.
 *
 * <p>Subclasses are immutable after construction and equal when they are the
 * same type built from the same raw JSON, which lets tests compare whole
 * responses without field-by-field asserts.
 */
public abstract class Model {

    private final Map<String, Object> raw;

    protected Model(Map<String, Object> raw) {
        this.raw = raw == null
                ? Collections.<String, Object>emptyMap()
                : Collections.unmodifiableMap(new LinkedHashMap<String, Object>(raw));
    }

    /** The complete JSON object the response was parsed from, unmodifiable. */
    public Map<String, Object> getRaw() {
        return raw;
    }

    /**
     * A raw field as a String, or {@code null} when absent or not a string.
     * Use this to reach fields the typed accessors do not cover yet.
     */
    public String get(String field) {
        return Json.string(raw, field);
    }

    /**
     * The first of {@code names} present in the response, as a string.
     *
     * <p>Exists because the API is inconsistent about naming: a group id
     * arrives as {@code grpid} on some routes and {@code groupid} on others.
     * Numbers sent bare (not stringified) are converted here, which is the only
     * place the library normalises a JSON number into text.
     */
    static String firstOf(Map<String, Object> raw, String... names) {
        for (String name : names) {
            Object value = raw.get(name);
            if (value == null) {
                continue;
            }
            if (value instanceof String) {
                return (String) value;
            }
            return value.toString();
        }
        return null;
    }

    /**
     * Parse a stringified number into a decimal for arithmetic, or
     * {@code null} when the value is absent or not numeric.
     */
    static BigDecimal toDecimal(String value) {
        if (value == null || value.isEmpty()) {
            return null;
        }
        try {
            return new BigDecimal(value.trim());
        } catch (NumberFormatException e) {
            return null;
        }
    }

    @Override
    public boolean equals(Object other) {
        if (this == other) {
            return true;
        }
        if (other == null || other.getClass() != getClass()) {
            return false;
        }
        return raw.equals(((Model) other).raw);
    }

    @Override
    public int hashCode() {
        return getClass().hashCode() * 31 + raw.hashCode();
    }

    @Override
    public String toString() {
        return getClass().getSimpleName() + raw;
    }
}