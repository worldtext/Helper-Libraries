package net.worldtext.api;

import java.io.BufferedReader;
import java.io.File;
import java.io.IOException;
import java.io.InputStreamReader;
import java.lang.reflect.InvocationTargetException;
import java.lang.reflect.Method;
import java.lang.reflect.Modifier;
import java.math.BigDecimal;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.TimeUnit;
import java.util.logging.Handler;
import java.util.logging.Level;
import java.util.logging.LogRecord;
import java.util.logging.Logger;

/**
 * Test suite and runner for the World Text Java client.
 *
 * <p>Run with {@code java net.worldtext.api.TestRunner}; it exits 0 when every
 * test passes and 1 when any fails. Each public no-argument {@code test...}
 * method is one test.
 *
 * <p>The client tests start the shared mock gateway
 * ({@code ../fixtures/mock_gateway.py}) as a subprocess on port 0, read the
 * {@code PORT n} line it prints, then talk to {@code 127.0.0.1} only. No test
 * touches the network or a real account. When {@code python3} is not installed
 * the gateway tests are reported as skipped, not failed.
 *
 * <p>The JSON tests need no gateway at all and run first.
 */
public final class TestRunner {

    /** A test body that may throw anything. */
    private interface Body {
        void run() throws Exception;
    }

    private static final String SECRET = "wt_live_supersecretvalue_that_must_never_leak";

    private static int passed;
    private static int failed;
    private static int skipped;
    private static final List<String> failures = new ArrayList<String>();

    private static MockGateway mock;

    private TestRunner() {
    }

    public static void main(String[] args) throws Exception {
        String filter = args.length > 0 ? args[0] : null;

        // Clients under test are created with short timeouts; keep the
        // library's FINE-level request logging off the console either way.
        Logger.getLogger("net.worldtext.api").setLevel(Level.OFF);

        startMock();

        int run = 0;
        for (Method method : orderedTests()) {
            String name = method.getDeclaringClass().getSimpleName() + "." + method.getName();
            if (filter != null && !name.contains(filter)) {
                continue;
            }
            run++;
            System.out.print("  " + name + " ... ");
            if (requiresGateway(method) && mock == null) {
                skipped++;
                System.out.println("SKIPPED (python3/mock gateway unavailable)");
                continue;
            }
            try {
                method.invoke(null);
                passed++;
                System.out.println("ok");
            } catch (InvocationTargetException e) {
                Throwable cause = e.getCause();
                failed++;
                failures.add(name + ": " + cause);
                System.out.println("FAILED");
                System.out.println("      " + cause);
                StackTraceElement[] trace = cause.getStackTrace();
                for (int i = 0; i < Math.min(3, trace.length); i++) {
                    System.out.println("      at " + trace[i]);
                }
            }
        }

        stopMock();

        System.out.println();
        System.out.println(String.format("%d tests: %d passed, %d failed, %d skipped",
                run, passed, failed, skipped));
        if (skipped > 0) {
            System.out.println("NOTE: integration tests were skipped - install python3 to run them.");
        }
        if (failed > 0) {
            for (String failure : failures) {
                System.out.println("  FAILED " + failure);
            }
            System.exit(1);
        }
        if (passed == 0) {
            System.out.println("  no tests ran");
            System.exit(1);
        }
        System.exit(0);
    }

    /**
     * Tests in declaration order: Java reflection does not promise an order, so
     * sort by name and rely on the prefixes json/ then client/.
     */
    private static List<Method> orderedTests() {
        List<Method> methods = new ArrayList<Method>();
        for (Method method : TestRunner.class.getDeclaredMethods()) {
            if (method.getName().startsWith("test")
                    && method.getParameterTypes().length == 0
                    && Modifier.isStatic(method.getModifiers())) {
                methods.add(method);
            }
        }
        Collections.sort(methods, new java.util.Comparator<Method>() {
            @Override
            public int compare(Method a, Method b) {
                return a.getName().compareTo(b.getName());
            }
        });
        return methods;
    }

    /** Gateway tests declare {@code throws IOException}; JSON tests do not. */
    private static boolean requiresGateway(Method method) {
        for (Class<?> type : method.getExceptionTypes()) {
            if (type == IOException.class) {
                return true;
            }
        }
        return false;
    }

    // =========================================================== JSON parser

    static void testJsonParsesStringifiedEnvelope() throws Exception {
        Map<String, Object> payload =
                Json.parseObject("{\"status\":\"0\",\"credits\":\"2950\"}");
        eq("2950", Json.string(payload, "credits"), "credits");
        eq("0", Json.string(payload, "status"), "status");
    }

    static void testJsonDecodesEscapes() throws Exception {
        // JSON: {"message":"He said \"hi\" \n over \n two lines: \\ worked\t"}
        // The data therefore holds quotes, newlines, a tab and a backslash.
        String text = "{\"message\":\"He said \\\"hi\\\" \\n over \\n two lines: \\\\ worked\\t\"}";
        Map<String, Object> payload = Json.parseObject(text);
        eq("He said \"hi\" \n over \n two lines: \\ worked\t",
                Json.string(payload, "message"), "escaped message");
    }

    static void testJsonEscapesThatMatterToGateway() throws Exception {
        Map<String, Object> payload = Json.parseObject(
                "{\"a\":\"C:\\\\path\\\\to\",\"b\":\"one\\ntwo\",\"c\":\"\\u00e9\\u20ac\\ud83d\\ude00\","
                        + "\"d\":\"\\/slash\\\"quote\\\"\"}");
        eq("C:\\path\\to", Json.string(payload, "a"), "backslashes");
        eq("one\ntwo", Json.string(payload, "b"), "newline");
        eq("\u00e9\u20ac\ud83d\ude00", Json.string(payload, "c"), "unicode and surrogate pair");
        eq("/slash\"quote\"", Json.string(payload, "d"), "slash and quotes");
    }

    static void testJsonParsesNestingAndTypes() throws Exception {
        Map<String, Object> payload = Json.parseObject(
                "{\"status\":\"0\",\"result\":[{\"msgid\":\"10001\",\"dstaddr\":\"447989000001\"}],"
                        + "\"count\":2,\"ratio\":1.5,\"big\":12345678901234567890123,"
                        + "\"flag\":true,\"off\":false,\"nothing\":null,\"empty\":{},\"none\":[]}");
        List<Object> result = Json.list(payload, "result");
        eq(1, result.size(), "result length");
        @SuppressWarnings("unchecked")
        Map<String, Object> first = (Map<String, Object>) result.get(0);
        eq("10001", Json.string(first, "msgid"), "nested msgid");
        eq(Boolean.TRUE, payload.get("flag"), "true");
        eq(Boolean.FALSE, payload.get("off"), "false");
        isTrue(payload.containsKey("nothing") && payload.get("nothing") == null, "null preserved");
        eq(new BigDecimal("2"), payload.get("count"), "integer as BigDecimal");
        eq(new BigDecimal("1.5"), payload.get("ratio"), "decimal as BigDecimal");
        eq(new BigDecimal("12345678901234567890123"), payload.get("big"), "no precision loss");
        isTrue(Json.list(payload, "none").isEmpty(), "empty array");
        isTrue(Json.map(payload, "empty").isEmpty(), "empty object");
    }

    static void testJsonSurvivesGoogleFormDecoding() throws Exception {
        // A gateway that forms its JSON from form-decoded text must produce
        // escaped output; this is what it looks like when it does.
        String body = "{\"status\":\"0\",\"desc\":\"got \\\"x\\\" and a backslash: \\\\\"}";
        eq("got \"x\" and a backslash: \\", Json.string(Json.parseObject(body), "desc"), "desc");
    }

    static void testJsonRejectsMalformedInput() {
        String[] bad = {
            "",
            "   ",
            "{",
            "[",
            "{\"a\":}",
            "{\"a\":1,}",
            "[1,]",
            "{\"a\":1}{}",
            "{\"a\" 1}",
            "{a:1}",
            "{\"a\":\"unterminated}",
            "{\"a\":\"bad \\q escape\"}",
            "{\"a\":\"\\u00zz\"}",
            "{\"a\":\"\\u12\"}",
            "{\"a\":\"raw\ttab\"}",
            "{\"a\":01}",
            "{\"a\":1.}",
            "{\"a\":.5}",
            "{\"a\":-}",
            "{\"a\":1e}",
            "{\"a\":tru}",
            "{\"a\":'single'}",
            "{\"a\":NaN}",
            "null",
            "42",
        };
        for (String text : bad) {
            try {
                Json.parseObject(text);
                fail("expected ParseException for: " + text);
            } catch (Json.ParseException expected) {
                isTrue(expected.getMessage() != null && expected.getMessage().contains("offset"),
                        "message names an offset");
            }
        }
    }

    static void testJsonAcceptsEmptyObject() throws Exception {
        isTrue(Json.parseObject(" {} ").isEmpty(), "whitespace-padded empty object");
        isTrue(Json.parseObject("{\"a\":[]}").containsKey("a"), "empty array value");
        eq(null, Json.string(Json.parseObject("{\"a\":1}"), "a"), "non-string field yields null");
        eq(null, Json.string(null, "a"), "null object yields null");
    }

    // ================================================================ success

    static void testClientCreditsIsString() throws IOException {
        Credits credits = client("test-all").getCredits();
        eq("2950", credits.getCredits(), "credits");
        isTrue(credits.getCredits() instanceof String, "credits is a String, not a number");
        eq(0, new BigDecimal("2950").compareTo(credits.asDecimal()), "asDecimal");
    }

    static void testClientPingAndHost() throws IOException {
        WorldText wt = client("test-admin");
        isTrue(wt.ping().isOk(), "ping ok");
        eq("sms1.example", wt.getHost().getHostname(), "hostname");
    }

    static void testClientSmsCost() throws IOException {
        Cost cost = client("test-sms").getSmsCost("447989000001", "hi");
        eq("1.0", cost.getCredits(), "cost credits");
        eq(0, new BigDecimal("1.0").compareTo(cost.asDecimal()), "cost asDecimal");
    }

    static void testClientSendSmsParsesMsgid() throws IOException {
        SendResult result = client("test-sms").sendSms("447989000001", "hi");
        eq(1, result.getMessages().size(), "one message");
        eq("10001", result.getMsgid(), "msgid");
        eq("447989000001", result.getMessages().get(0).getDstaddr(), "dstaddr");
        isTrue(result.getMessages().get(0).getMsgid() instanceof String, "msgid is a String");
    }

    static void testClientSendSmsPassesOptionsThrough() throws IOException {
        Map<String, String> options = new LinkedHashMap<String, String>();
        options.put("sender", "MySender");
        options.put("schedule", "2026-10-26 09:00:00");
        options.put("udh", null); // null options are omitted, not sent empty
        eq("10001", client("test-sms").sendSms("447989000001", "hi", options).getMsgid(), "msgid");
    }

    static void testClientGroupLifecycle() throws IOException {
        WorldText wt = client("test-groups");
        eq("4242", wt.createGroup("g").getGrpid(), "created grpid");
        eq("1", wt.addGroupEntry("4242", "447989000001").getAdded(), "added");
        eq("2", wt.addGroupEntries("4242", Arrays.asList("447989000001", "447989000002")).getAdded(),
                "bulk added");
        // Models hand out unmodifiable lists; copy before sorting.
        List<String> numbers = new ArrayList<String>(wt.getGroupNumbers("4242").getNumbers());
        Collections.sort(numbers);
        eq(Arrays.asList("447989000001", "447989000002"), numbers, "numbers");
        List<GroupEntry> details = wt.getGroupDetails("4242").getEntries();
        eq(2, details.size(), "detail entries");
        eq("447989000001", details.get(0).getNumber(), "detail number");
        eq("5", details.get(0).getStatus(), "detail status");
        GroupList groups = wt.listGroups();
        eq(1, groups.size(), "group count");
        Group only = groups.getGroups().get(0);
        eq("4242", only.getGrpid(), "group grpid");
        eq("test-group", only.getName(), "group name");
        eq("2", only.getSize(), "group size");
        eq("2", wt.deleteGroupContents("4242").getDeleted(), "deleted contents");
        wt.deleteGroup("4242");
        wt.deleteGroupEntry("4242", "447989000001");
    }

    static void testClientAcceptsGroupidSpelling() throws Exception {
        // Some routes say "groupid" rather than "grpid"; both must work.
        Map<String, Object> raw = new LinkedHashMap<String, Object>();
        raw.put("status", "0");
        raw.put("groupid", "77");
        eq("77", new CreateGroupResult(raw).getGrpid(), "groupid spelling");
        Map<String, Object> group = new LinkedHashMap<String, Object>();
        group.put("id", "78");
        group.put("name", "x");
        group.put("entries", "9");
        Group listed = new Group(group);
        eq("78", listed.getGrpid(), "id spelling");
        eq("9", listed.getSize(), "entries spelling");
    }

    static void testClientHlrOperations() throws IOException {
        WorldText wt = client("test-hlr");
        eq("UK", wt.lookupHlr("447989000001").getRegion(), "hlr region");
        eq("ported", wt.lookupMnp("447989000001").getMnpportstatus(), "mnp status");
        NumberInfo info = wt.getNumberInfo("447989000001");
        eq("O2-UK", info.getNetwork(), "network");
        eq("44", info.getCc(), "cc");
    }

    static void testClientSmsStatus() throws IOException {
        SmsStatus status = client("test-sms").querySmsStatus("10001");
        eq("5", status.getState(), "state");
        isTrue(status.isFinal(), "final");
    }

    static void testClientGroupCostNeedsSmsNotGroups() throws IOException {
        // The spec says group/cost requires sms; a groups token must be refused.
        GroupCost cost = client("test-http").getGroupCost("4242");
        eq("2.0", cost.getCredits(), "group cost");
        eq("2", cost.getEntrycount(), "entry count");
        try {
            client("test-email").getGroupCost("4242");
            fail("a groups-only token must not be able to price a group send");
        } catch (PermissionDeniedException expected) {
            eq("http", expected.getRequiredScope(), "required scope");
        }
        eq("10002", client("test-http").sendGroupSms("4242", "hi").getMsgid(), "group send msgid");
    }

    // ================================================================= errors

    static void testClientBadTokenIs401() throws IOException {
        try {
            client("not-a-token").getCredits();
            fail("expected AuthenticationException");
        } catch (AuthenticationException e) {
            eq(Integer.valueOf(401), e.getHttpStatus(), "httpStatus");
            eq("1000", e.getErrorCode(), "errorCode");
            isTrue(e.getDesc() != null && !e.getDesc().isEmpty(), "desc present");
            isFalse(e.isRetryable(), "401 is not retryable");
        }
    }

    static void testClientWrongScopeIs403WithScope() throws IOException {
        try {
            client("test-email").getCredits();
            fail("expected PermissionDeniedException");
        } catch (PermissionDeniedException e) {
            eq(Integer.valueOf(403), e.getHttpStatus(), "httpStatus");
            // The required scope is surfaced so the caller can say which to add.
            eq("http", e.getRequiredScope(), "requiredScope");
            isTrue(e.toString().contains("http"), "toString shows the interface");
            isFalse(e.isRetryable(), "403 is not retryable");
        }
        // And the reverse direction: an email credential cannot send SMS.
        try {
            client("test-email").sendSms("447989000001", "hi");
            fail("expected PermissionDeniedException");
        } catch (PermissionDeniedException e) {
            eq("http", e.getRequiredScope(), "requiredScope");
        }
    }

    static void testClientMissingArgumentIs400() throws IOException {
        try {
            client("test-sms").getSmsCost("");
            fail("expected ApiException");
        } catch (ApiException e) {
            eq(Integer.valueOf(400), e.getHttpStatus(), "httpStatus");
            eq("1200", e.getErrorCode(), "errorCode");
            isFalse(e.isRetryable(), "400 is not retryable");
        }
    }

    static void testClientErrorsShareBaseClass() throws IOException {
        try {
            client("nope").getCredits();
            fail("expected WorldTextException");
        } catch (WorldTextException e) {
            isTrue(e instanceof AuthenticationException, "authentication subtype");
        }
        try {
            client("test-email").getCredits();
            fail("expected WorldTextException");
        } catch (WorldTextException e) {
            isTrue(e instanceof PermissionDeniedException, "permission subtype");
        }
    }

    static void testTransportFailureIsTyped() {
        // Port 1 on loopback: nothing listens, connection refused immediately.
        WorldText wt = new WorldText("test-all", "http://127.0.0.1:1", 2);
        try {
            wt.getCredits();
            fail("expected TransportException");
        } catch (TransportException e) {
            eq(null, e.getHttpStatus(), "no HTTP status");
            isTrue(e.getCause() != null, "cause retained");
            isTrue(e.getMessage().contains("127.0.0.1:1"), "message names the base URL");
            isFalse(e.toString().contains("test-all"), "no token in toString");
        }
    }

    static void testScopeFromHeaderParsing() {
        eq("sms", WorldText.scopeFromHeader(Collections.singletonList(
                "Bearer error=\"insufficient_scope\", scope=\"sms\"")), "quoted scope");
        eq("admin", WorldText.scopeFromHeader(Collections.singletonList(
                "Bearer error=\"insufficient_scope\",scope=admin")), "unquoted scope");
        eq(null, WorldText.scopeFromHeader(null), "absent header");
        eq(null, WorldText.scopeFromHeader(Collections.singletonList("Bearer realm=x")), "no scope");
    }

    // ========================================================== token safety

    static void testTokenRedactedInToString() {
        WorldText wt = new WorldText(SECRET, "http://127.0.0.1:1", 2);
        String text = wt.toString();
        isFalse(text.contains(SECRET), "toString must not contain the token");
        isTrue(text.contains("<redacted:" + SECRET.length() + " chars>"), "shows redaction marker");
        eq("<none>", WorldText.redact(null), "null token");
        eq("<none>", WorldText.redact(""), "empty token");
    }

    static void testTokenRedactedInErrorMessages() throws IOException {
        WorldText wt = client(SECRET);
        try {
            wt.getCredits();
            fail("expected AuthenticationException");
        } catch (WorldTextException e) {
            isFalse(e.getMessage().contains(SECRET), "message must not contain the token");
            isFalse(e.toString().contains(SECRET), "toString must not contain the token");
            isFalse(String.valueOf(e.getDesc()).contains(SECRET), "desc must not contain the token");
        }
        // Also on the transport path, where messages are built from the URL.
        try {
            new WorldText(SECRET, "http://127.0.0.1:1", 2).getCredits();
            fail("expected TransportException");
        } catch (WorldTextException e) {
            isFalse(e.getMessage().contains(SECRET), "message must not contain the token");
            isFalse(e.toString().contains(SECRET), "toString must not contain the token");
        }
    }

    static void testLoggingRedactsToken() throws IOException {
        final List<String> records = new ArrayList<String>();
        Handler capture = new Handler() {
            @Override
            public void publish(LogRecord record) {
                records.add(record.getMessage());
            }

            @Override
            public void flush() {
            }

            @Override
            public void close() {
            }
        };
        Logger logger = Logger.getLogger("net.worldtext.api");
        Level previous = logger.getLevel();
        boolean previousUseParent = logger.getUseParentHandlers();
        logger.addHandler(capture);
        logger.setLevel(Level.FINE);
        logger.setUseParentHandlers(false);
        try {
            client(SECRET).getCredits();
        } catch (WorldTextException expected) {
            // The point is the log line, not the outcome.
        } finally {
            logger.removeHandler(capture);
            logger.setLevel(previous);
            logger.setUseParentHandlers(previousUseParent);
        }
        isFalse(records.isEmpty(), "expected at least one log record");
        for (String line : records) {
            isFalse(line.contains(SECRET), "log line must not contain the token: " + line);
            isTrue(line.contains("redacted"), "log line shows the redaction marker");
        }
    }

    // ============================================================== plumbing

    /** The mock gateway subprocess and the port it reported. */
    private static final class MockGateway {
        final Process process;
        final String baseUrl;

        MockGateway(Process process, String baseUrl) {
            this.process = process;
            this.baseUrl = baseUrl;
        }
    }

    private static void startMock() {
        String fixtures = locateFixtures();
        if (fixtures == null) {
            System.out.println("mock gateway not found; integration tests will be skipped");
            return;
        }
        String mockPath = fixtures + File.separator + "mock_gateway.py";
        try {
            ProcessBuilder builder = new ProcessBuilder("python3", mockPath, "0");
            builder.redirectErrorStream(true);
            Process process = builder.start();
            BufferedReader reader = new BufferedReader(
                    new InputStreamReader(process.getInputStream(), "UTF-8"));
            String line = reader.readLine();
            if (line == null || !line.startsWith("PORT ")) {
                process.destroyForcibly();
                System.out.println("mock gateway did not report a port (" + line
                        + "); integration tests will be skipped");
                return;
            }
            String port = line.substring("PORT ".length()).trim();
            mock = new MockGateway(process, "http://127.0.0.1:" + port);
        } catch (IOException e) {
            System.out.println("could not start python3 mock gateway (" + e.getMessage()
                    + "); integration tests will be skipped");
        }
    }

    /**
     * Find the fixtures directory: walk up from the working directory, or use
     * {@code -Dworldtext.fixtures=/path}.
     */
    private static String locateFixtures() {
        String override = System.getProperty("worldtext.fixtures");
        if (override != null) {
            File file = new File(override);
            return file.isDirectory() ? file.getAbsolutePath() : null;
        }
        File dir = new File(System.getProperty("user.dir")).getAbsoluteFile();
        while (dir != null) {
            File candidate = new File(dir, "fixtures");
            if (new File(candidate, "mock_gateway.py").isFile()) {
                return candidate.getAbsolutePath();
            }
            candidate = new File(dir, "helper-libraries" + File.separator + "fixtures");
            if (new File(candidate, "mock_gateway.py").isFile()) {
                return candidate.getAbsolutePath();
            }
            dir = dir.getParentFile();
        }
        return null;
    }

    private static void stopMock() {
        if (mock == null) {
            return;
        }
        mock.process.destroy();
        try {
            if (!mock.process.waitFor(5, TimeUnit.SECONDS)) {
                mock.process.destroyForcibly();
                mock.process.waitFor(5, TimeUnit.SECONDS);
            }
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            mock.process.destroyForcibly();
        }
        mock = null;
    }

    private static WorldText client(String token) {
        if (mock == null) {
            throw new IllegalStateException("mock gateway is not running");
        }
        return new WorldText(token, mock.baseUrl, 5);
    }

    // ============================================================ assertions

    private static void eq(Object expected, Object actual, String what) {
        boolean equal = expected == null ? actual == null : expected.equals(actual);
        if (!equal) {
            fail(what + ": expected <" + expected + "> but was <" + actual + ">");
        }
    }

    private static void isTrue(boolean condition, String what) {
        if (!condition) {
            fail(what + ": expected true");
        }
    }

    private static void isFalse(boolean condition, String what) {
        if (condition) {
            fail(what + ": expected false");
        }
    }

    private static void fail(String message) {
        throw new AssertionError(message);
    }
}