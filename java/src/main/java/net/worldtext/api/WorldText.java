package net.worldtext.api;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.UnsupportedEncodingException;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.MalformedURLException;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.Charset;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.logging.Logger;

/**
 * Client for the World Text HTTP API (spec version 3.0), bearer authentication.
 *
 * <p>Usage:
 * <pre>{@code
 * WorldText wt = new WorldText("wt_live_xxxxxxxx");
 * System.out.println(wt.getCredits().getCredits());   // "2950" - a string, as sent
 * wt.sendSms("447989000001", "Hello from Java");
 * }</pre>
 *
 * <h2>Threading</h2>
 * Instances hold only the token, base URL and timeout, and are immutable after
 * construction, so one client may be shared between threads.
 *
 * <h2>Token safety</h2>
 * The token is held in a private field and never interpolated into a message,
 * exception, {@link #toString()} or log line; where a token would otherwise
 * appear, {@link #redact(String)} substitutes {@code <redacted:N chars>}.
 *
 * <h2>Errors</h2>
 * Every method throws a {@link WorldTextException} subclass on failure:
 * {@link AuthenticationException} (401), {@link PermissionDeniedException} (403,
 * with {@link PermissionDeniedException#getRequiredScope()}), {@link ApiException}
 * (any other gateway error envelope) or {@link TransportException} (no response).
 */
public class WorldText {

    /** The production gateway. */
    public static final String DEFAULT_BASE_URL = "https://sms.world-text.com/v2.0";

    /** Default per-request timeout, seconds. */
    public static final int DEFAULT_TIMEOUT_SECONDS = 30;

    private static final Logger LOG = Logger.getLogger("net.worldtext.api");

    private final String token;
    private final String baseUrl;
    private final int timeoutSeconds;

    /**
     * Create a client for the production gateway.
     *
     * @param token API bearer token; must not be empty
     */
    public WorldText(String token) {
        this(token, DEFAULT_BASE_URL, DEFAULT_TIMEOUT_SECONDS);
    }

    /**
     * Create a client.
     *
     * @param token          API bearer token; must not be empty
     * @param baseUrl        gateway base URL, e.g. {@code http://127.0.0.1:8080},
     *                       for testing or a regional host
     * @param timeoutSeconds per-request timeout in seconds; must be positive
     * @throws IllegalArgumentException if the token or base URL is missing or invalid
     */
    public WorldText(String token, String baseUrl, int timeoutSeconds) {
        if (token == null || token.isEmpty()) {
            throw new IllegalArgumentException("a token is required");
        }
        if (baseUrl == null || baseUrl.isEmpty()) {
            throw new IllegalArgumentException("a base URL is required");
        }
        if (timeoutSeconds <= 0) {
            throw new IllegalArgumentException("timeoutSeconds must be positive");
        }
        this.token = token;
        this.baseUrl = trimTrailingSlashes(baseUrl);
        this.timeoutSeconds = timeoutSeconds;
    }

    /**
     * Return a log-safe form of a token: never the token itself.
     *
     * @param token the token, or {@code null}
     * @return {@code <none>} for a missing token, else {@code <redacted:N chars>}
     */
    public static String redact(String token) {
        if (token == null || token.isEmpty()) {
            return "<none>";
        }
        return "<redacted:" + token.length() + " chars>";
    }

    /** The gateway base URL, without a trailing slash. Contains no credentials. */
    public String getBaseUrl() {
        return baseUrl;
    }

    /** The per-request timeout in seconds. */
    public int getTimeoutSeconds() {
        return timeoutSeconds;
    }

    /** Deliberately shows no token. */
    @Override
    public String toString() {
        return "WorldText[baseUrl=" + baseUrl + ", token=" + redact(token)
                + ", timeoutSeconds=" + timeoutSeconds + "]";
    }

    // ------------------------------------------------------------------ admin

    /** Liveness check. Requires the {@code admin} permission. */
    public PingResult ping() {
        return new PingResult(request("GET", "/admin/ping", null));
    }

    /** Credit balance. Requires the {@code admin} permission. */
    public Credits getCredits() {
        return new Credits(request("GET", "/admin/credits", null));
    }

    /** Name of the host that served the request. Requires {@code admin}. */
    public HostName getHost() {
        return new HostName(request("GET", "/admin/host", null));
    }

    // -------------------------------------------------------------------- sms

    /** Cost of a message of no particular text. Requires {@code sms}. */
    public Cost getSmsCost(String dstaddr) {
        return getSmsCost(dstaddr, "");
    }

    /** Cost of a single message without sending it. Requires {@code sms}. */
    public Cost getSmsCost(String dstaddr, String message) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("dstaddr", dstaddr);
        params.put("message", message);
        return new Cost(request("GET", "/sms/cost", params));
    }

    /** Send one message. Requires {@code sms}. */
    public SendResult sendSms(String dstaddr, String message) {
        return sendSms(dstaddr, message, Collections.<String, String>emptyMap());
    }

    /**
     * Send to one destination. Requires {@code sms}.
     *
     * <p>{@code options} are passed through unchanged, so optional fields
     * ({@code sendid}, {@code schedule}, {@code sender}, {@code udh}) work
     * without this client having to enumerate them as the API grows. Entries
     * with a {@code null} or empty value are omitted.
     */
    public SendResult sendSms(String dstaddr, String message, Map<String, String> options) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("dstaddr", dstaddr);
        params.put("message", message);
        putAll(params, options);
        return new SendResult(request("PUT", "/sms/send", params));
    }

    /** Delivery state of a sent message. Requires {@code sms}. */
    public SmsStatus querySmsStatus(String msgid) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("msgid", msgid);
        return new SmsStatus(request("GET", "/sms/query", params));
    }

    // -------------------------------------------------------------------- hlr

    /** HLR lookup. Requires {@code hlr}. Costs a credit. */
    public HlrResult lookupHlr(String msisdn) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("msisdn", msisdn);
        return new HlrResult(request("GET", "/hlr/lookup", params));
    }

    /** Mobile number portability. Requires {@code hlr}. */
    public MnpResult lookupMnp(String msisdn) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("msisdn", msisdn);
        return new MnpResult(request("GET", "/hlr/mnp", params));
    }

    /** Country and network for a number. Requires {@code hlr}. */
    public NumberInfo getNumberInfo(String msisdn) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("msisdn", msisdn);
        return new NumberInfo(request("GET", "/hlr/info", params));
    }

    // ----------------------------------------------------------------- groups

    /** Create a group. Requires {@code groups}. */
    public CreateGroupResult createGroup(String name) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("name", name);
        return new CreateGroupResult(request("PUT", "/group/create", params));
    }

    /** Delete a group and its contents. Requires {@code groups}. */
    public DeleteResult deleteGroup(String grpid) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        return new DeleteResult(request("DELETE", "/group/destroy", params));
    }

    /** Add one number to a group. Requires {@code groups}. */
    public AddEntriesResult addGroupEntry(String grpid, String number) {
        return addGroupEntry(grpid, number, Collections.<String, String>emptyMap());
    }

    /**
     * Add one number to a group, with optional extra fields passed through.
     * Requires {@code groups}.
     */
    public AddEntriesResult addGroupEntry(String grpid, String number, Map<String, String> options) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        params.put("number", number);
        putAll(params, options);
        return new AddEntriesResult(request("PUT", "/group/entry", params));
    }

    /** Remove one number from a group. Requires {@code groups}. */
    public DeleteResult deleteGroupEntry(String grpid, String number) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        params.put("number", number);
        return new DeleteResult(request("DELETE", "/group/entry", params));
    }

    /** Add several numbers at once. Requires {@code groups}. */
    public AddEntriesResult addGroupEntries(String grpid, List<String> numbers) {
        if (numbers == null) {
            throw new IllegalArgumentException("numbers must not be null");
        }
        // The API takes the numbers one per line in a single field.
        StringBuilder joined = new StringBuilder();
        for (String number : numbers) {
            if (joined.length() > 0) {
                joined.append('\n');
            }
            joined.append(number);
        }
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        params.put("numbers", joined.toString());
        return new AddEntriesResult(request("PUT", "/group/entries", params));
    }

    /** Add several numbers at once, given as varargs. Requires {@code groups}. */
    public AddEntriesResult addGroupEntries(String grpid, String... numbers) {
        if (numbers == null) {
            throw new IllegalArgumentException("numbers must not be null");
        }
        List<String> list = new ArrayList<String>(numbers.length);
        Collections.addAll(list, numbers);
        return addGroupEntries(grpid, list);
    }

    /** Numbers in a group. Requires {@code groups}. */
    public GroupNumbers getGroupNumbers(String grpid) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        return new GroupNumbers(request("GET", "/group/numbers", params));
    }

    /** Numbers in a group with their status. Requires {@code groups}. */
    public GroupDetails getGroupDetails(String grpid) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        return new GroupDetails(request("GET", "/group/details", params));
    }

    /** All groups on the account. Requires {@code groups}. */
    public GroupList listGroups() {
        return new GroupList(request("GET", "/group/list", null));
    }

    /** Remove every number from a group, keeping the group. Requires {@code groups}. */
    public DeleteResult deleteGroupContents(String grpid) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        return new DeleteResult(request("DELETE", "/group/contents", params));
    }

    /** Cost of sending a message of no particular text to a group. Requires {@code sms}. */
    public GroupCost getGroupCost(String grpid) {
        return getGroupCost(grpid, "");
    }

    /**
     * Cost of sending to a whole group.
     *
     * <p>Requires {@code sms}, not {@code groups} - that is the gateway's actual
     * behaviour, not a documentation slip.
     */
    public GroupCost getGroupCost(String grpid, String message) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        params.put("message", message);
        return new GroupCost(request("GET", "/group/cost", params));
    }

    /** Send to every number in a group. Requires {@code sms}. */
    public SendResult sendGroupSms(String grpid, String message) {
        return sendGroupSms(grpid, message, Collections.<String, String>emptyMap());
    }

    /**
     * Send to every number in a group. Requires {@code sms}.
     *
     * <p>{@code options} are passed through unchanged, like {@link #sendSms}.
     */
    public SendResult sendGroupSms(String grpid, String message, Map<String, String> options) {
        Map<String, String> params = new LinkedHashMap<String, String>();
        params.put("grpid", grpid);
        params.put("message", message);
        putAll(params, options);
        return new SendResult(request("PUT", "/group/send", params));
    }

    // ------------------------------------------------------------------ core

    /**
     * Perform one request and return the decoded success envelope.
     *
     * <p>Only arguments with a non-null, non-empty value are sent, so an
     * omitted optional argument is absent from the request rather than sent as
     * an empty string, which the gateway would treat as a value. Write routes
     * send arguments form-encoded in a PUT body; read routes send them in the
     * query string.
     */
    private Map<String, Object> request(String method, String path, Map<String, String> params) {
        Map<String, String> clean = new LinkedHashMap<String, String>();
        if (params != null) {
            for (Map.Entry<String, String> entry : params.entrySet()) {
                if (entry.getValue() != null && !entry.getValue().isEmpty()) {
                    clean.put(entry.getKey(), entry.getValue());
                }
            }
        }

        boolean write = "PUT".equals(method) || "POST".equals(method);
        String url = baseUrl + path;
        byte[] body = null;
        if (write) {
            body = formEncode(clean).getBytes(utf8());
        } else if (!clean.isEmpty()) {
            url = url + "?" + formEncode(clean);
        }

        // Formatted here rather than with placeholders: java.util.logging keeps
        // the template as a LogRecord's message, so handlers would print "{0}".
        LOG.fine(method + " " + path + " (token " + redact(token) + ")");

        HttpURLConnection connection = null;
        try {
            connection = (HttpURLConnection) new URL(url).openConnection();
            connection.setRequestMethod(method);
            connection.setConnectTimeout(timeoutSeconds * 1000);
            connection.setReadTimeout(timeoutSeconds * 1000);
            connection.setUseCaches(false);
            connection.setInstanceFollowRedirects(false);
            connection.setRequestProperty("Authorization", "Bearer " + token);
            connection.setRequestProperty("Accept", "application/json");
            if (body != null) {
                connection.setDoOutput(true);
                connection.setRequestProperty("Content-Type",
                        "application/x-www-form-urlencoded; charset=UTF-8");
                connection.setFixedLengthStreamingMode(body.length);
                OutputStream out = connection.getOutputStream();
                try {
                    out.write(body);
                } finally {
                    out.close();
                }
            }

            int status = connection.getResponseCode();
            InputStream stream = status >= 400 ? connection.getErrorStream() : connection.getInputStream();
            String text = readFully(stream);
            List<String> wwwAuthenticate = connection.getHeaderFields().get("WWW-Authenticate");
            return decode(text, status, wwwAuthenticate);
        } catch (MalformedURLException e) {
            throw new IllegalArgumentException("invalid base URL for " + path);
        } catch (IOException e) {
            // Never surface the URL: a caller's parameters are built into it.
            throw new TransportException(
                    "transport failure contacting " + baseUrl + ": " + e, e);
        } finally {
            if (connection != null) {
                connection.disconnect();
            }
        }
    }

    /**
     * Turn one response into a success payload, or throw the matching error.
     */
    private Map<String, Object> decode(String text, int status, List<String> wwwAuthenticate) {
        Map<String, Object> payload;
        try {
            payload = text == null || text.trim().isEmpty()
                    ? new LinkedHashMap<String, Object>()
                    : Json.parseObject(text);
        } catch (Json.ParseException e) {
            ApiException failure = new ApiException(
                    "response was not JSON (HTTP " + status + ")", Integer.valueOf(status), null, null);
            failure.setStatusFlag(status >= 500 ? StatusValue.TEMPORARY_1 : StatusValue.PERMANENT);
            throw failure;
        }

        String statusFlag = Json.string(payload, "status");
        if (status >= 200 && status < 300
                && (statusFlag == null || StatusValue.isSuccess(statusFlag))) {
            return payload;
        }

        String code = Json.string(payload, "error");
        String desc = Json.string(payload, "desc");
        if (desc == null || desc.isEmpty()) {
            desc = "HTTP " + status;
        }
        String message = desc + " (error " + code + ", HTTP " + status + ")";

        if (status == 401) {
            throw new AuthenticationException(message, Integer.valueOf(status), code, desc);
        }
        if (status == 403) {
            throw new PermissionDeniedException(message, Integer.valueOf(status), code, desc,
                    scopeFromHeader(wwwAuthenticate));
        }

        ApiException failure = new ApiException(message, Integer.valueOf(status), code, desc);
        // "2"/"3" mean "may be retried"; propagate so callers can decide.
        failure.setStatusFlag(statusFlag);
        throw failure;
    }

    /**
     * Pull {@code scope="http"} out of a {@code WWW-Authenticate} header, if
     * present. Headers may be split across several values; each is searched.
     */
    static String scopeFromHeader(List<String> headerValues) {
        if (headerValues == null) {
            return null;
        }
        for (String header : headerValues) {
            if (header == null) {
                continue;
            }
            for (String rawPart : header.split(",")) {
                String part = rawPart.trim();
                if (part.startsWith("scope=")) {
                    String value = part.substring("scope=".length()).trim();
                    if (value.length() >= 2 && value.startsWith("\"") && value.endsWith("\"")) {
                        value = value.substring(1, value.length() - 1);
                    }
                    return value;
                }
            }
        }
        return null;
    }

    private static String formEncode(Map<String, String> params) {
        StringBuilder out = new StringBuilder();
        for (Map.Entry<String, String> entry : params.entrySet()) {
            if (out.length() > 0) {
                out.append('&');
            }
            try {
                out.append(URLEncoder.encode(entry.getKey(), "UTF-8"));
                out.append('=');
                out.append(URLEncoder.encode(entry.getValue(), "UTF-8"));
            } catch (UnsupportedEncodingException e) {
                // UTF-8 is required of every JVM; unreachable in practice.
                throw new IllegalStateException("UTF-8 unsupported", e);
            }
        }
        return out.toString();
    }

    private static String readFully(InputStream stream) throws IOException {
        if (stream == null) {
            return "";
        }
        try {
            ByteArrayOutputStream buffer = new ByteArrayOutputStream();
            byte[] chunk = new byte[4096];
            int read;
            while ((read = stream.read(chunk)) != -1) {
                buffer.write(chunk, 0, read);
            }
            return buffer.toString("UTF-8");
        } finally {
            stream.close();
        }
    }

    private static Charset utf8() {
        return Charset.forName("UTF-8");
    }

    private static String trimTrailingSlashes(String url) {
        int end = url.length();
        while (end > 0 && url.charAt(end - 1) == '/') {
            end--;
        }
        if (end == 0) {
            throw new IllegalArgumentException("a base URL is required");
        }
        return url.substring(0, end);
    }

    private static void putAll(Map<String, String> target, Map<String, String> options) {
        if (options == null) {
            return;
        }
        for (Map.Entry<String, String> entry : options.entrySet()) {
            if (entry.getValue() != null && !entry.getValue().isEmpty()) {
                target.put(entry.getKey(), entry.getValue());
            }
        }
    }
}