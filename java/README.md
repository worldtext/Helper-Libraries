# world-text-java

Java client for the World Text HTTP API (spec version 3.0), using bearer
authentication.

Java 8 or newer, **no dependencies**: transport is `HttpURLConnection` and JSON
is a small hand-written parser in `Json.java`. Nothing to download, no Maven
build required — `javac` and `java` are enough.

## Install

Copy the sources into your project (one package, no resources):

```bash
cp -r helper-libraries/java/src/main/java/net/worldtext/api \
      /path/to/your/project/src/main/java/net/worldtext/
```

Or build a jar and put it on your classpath:

```bash
cd helper-libraries/java
mkdir -p build/classes
javac -encoding UTF-8 -d build/classes $(find src/main/java -name '*.java')
jar cf worldtext-api.jar -C build/classes .
```

The library is compiled and tested with `-source`/`-target` 8 semantics; it
uses only JDK 8 APIs.

## Quickstart

```java
import net.worldtext.api.*;

WorldText wt = new WorldText("wt_live_xxxxxxxx");     // production; or (token, baseUrl, timeoutSeconds)
System.out.println(wt.getCredits().getCredits());    // "2950" - a String, as the API sends it
String msgid = wt.sendSms("447989000001", "Hello").getMsgid();   // "10001"
try { wt.getCredits(); } catch (PermissionDeniedException e) { e.getRequiredScope(); }  // e.g. "admin"
```

## Authentication

Bearer tokens only: every request carries `Authorization: Bearer <token>`. There
is no query-string `id`/`key` support — that form is not part of API 3.0.

A token names the interfaces it may be used on: `http`, `email` or `bulk`. It is
not a list of operations: a credential carrying `http` can use this whole API
and can send messages, because that is what a token is issued for. A call from
an interface the token does not carry raises `PermissionDeniedException`.

## Methods

Every method takes and returns strings where the API uses strings. Where a
value is genuinely arithmetic, the model offers a numeric accessor (`asDecimal()`)
returning `BigDecimal`; nothing is ever parsed to `float`/`double`.

| Method | Interface | Returns | Notes |
|---|---|---|---|
| `ping()` | `http` | `PingResult` | Liveness; `isOk()` |
| `getCredits()` | `http` | `Credits` | Balance; `getCredits()`, `asDecimal()` |
| `getHost()` | `http` | `HostName` | Serving host name |
| `getSmsCost(dstaddr)` / `getSmsCost(dstaddr, message)` | `http` | `Cost` | Price without sending |
| `sendSms(dstaddr, message)` / `sendSms(dstaddr, message, options)` | `http` | `SendResult` | Extra fields pass through |
| `querySmsStatus(msgid)` | `http` | `SmsStatus` | Delivery state; `isFinal()` |
| `lookupHlr(msisdn)` | `http` | `HlrResult` | Costs a credit |
| `lookupMnp(msisdn)` | `http` | `MnpResult` | Porting status |
| `getNumberInfo(msisdn)` | `http` | `NumberInfo` | Country / network |
| `createGroup(name)` | `http` | `CreateGroupResult` | Returns `grpid` |
| `deleteGroup(grpid)` | `http` | `DeleteResult` | |
| `addGroupEntry(grpid, number)` / `addGroupEntry(grpid, number, options)` | `http` | `AddEntriesResult` | |
| `deleteGroupEntry(grpid, number)` | `http` | `DeleteResult` | |
| `addGroupEntries(grpid, List<String>)` / `addGroupEntries(grpid, String...)` | `http` | `AddEntriesResult` | Sent one per line |
| `getGroupNumbers(grpid)` | `http` | `GroupNumbers` | `getNumbers()` |
| `getGroupDetails(grpid)` | `http` | `GroupDetails` | With per-number status |
| `listGroups()` | `http` | `GroupList` | `getGroups()` |
| `deleteGroupContents(grpid)` | `http` | `DeleteResult` | Keeps the group |
| `getGroupCost(grpid)` / `getGroupCost(grpid, message)` | `http` | `GroupCost` | Prices a send, not a group edit |
| `sendGroupSms(grpid, message)` / `sendGroupSms(grpid, message, options)` | `http` | `SendResult` | |


`options` maps (`Map<String, String>`) are passed through unchanged, so optional
fields (`sendid`, `schedule`, `sender`, `udh`) work without the client having to
list them as the API grows. Entries with a `null` or empty value are omitted
rather than sent as empty strings, which the gateway would treat as a value.

Write routes (`sendSms`, `createGroup`, ...) use `PUT`; read routes use `GET`.
The gateway accepts `PUT` and `POST` interchangeably for writes.

## Numbers are strings

The API returns numbers as JSON strings: `"credits":"2950"`, `"msgid":"10001"`,
`"status":"0"`. The models keep them as strings so nothing is lost in a float
round-trip, and expose a numeric accessor where a value is genuinely arithmetic:

```java
Credits credits = wt.getCredits();
String raw = credits.getCredits();              // "2950"
BigDecimal value = credits.asDecimal();         // 2950
```

Group ids arrive as `grpid` on some routes and `groupid` (or `id`) on others;
`Group.getGrpid()`, `CreateGroupResult.getGrpid()` and friends accept all three.

## Errors

```java
try {
    wt.getCredits();
} catch (AuthenticationException e) {          // 401 - token unknown, revoked or malformed
    ...
} catch (PermissionDeniedException e) {        // 403 - add this permission to the token
    System.out.println(e.getRequiredScope());  // e.g. "admin"
} catch (ApiException e) {                     // 400/1100/1200/1201/5xx
    System.out.println(e.getErrorCode());      // e.g. "1200" bad arguments
    if (e.isRetryable()) { ... }
} catch (TransportException e) {               // no HTTP response at all
    ...
}
```

All errors derive from `WorldTextException` and carry `getHttpStatus()`,
`getErrorCode()` and `getDesc()`. Branch on those (or on the exception type),
not on message text. `isRetryable()` is true when the envelope's `status` is
`"2"`/`"3"` or the HTTP status is 5xx; authentication and permission failures
are never retryable. `StatusValue` names the status flags.

`TransportException` means the request never produced a response (DNS failure,
connection refused, TLS error, timeout); `getHttpStatus()` is `null` and the
original `IOException` is the cause. The message names the base URL only, never
the full request URL.

## Token safety

The token never appears in `toString()`, exception messages, or log output; it is
shown as `<redacted:N chars>` (`<none>` for an absent token). Request logging
goes through the standard `java.util.logging` logger `net.worldtext.api` at
`FINE`; nothing is logged at a higher level, so the library is silent unless you
opt in.

## JSON parser

`Json` is package-private: an internal detail, not API. It parses objects,
arrays, strings, numbers, booleans and null; decodes every JSON escape
(`\" \\ \/ \b \f \n \r \t \uXXXX`, including surrogate pairs); keeps numbers as
`BigDecimal`; and rejects malformed input (trailing data, unescaped control
characters, bad escapes, truncated `\u`) with a checked `Json.ParseException`.
It is tested directly, including message text containing quotes, backslashes and
newlines.

## Tests

```bash
cd /home/nevis/MMRP/helper-libraries/java && ./run-tests.sh
```

26 tests: 7 for the JSON parser and 19 for the client (10 success paths, 6
error/transport paths, 3 token-safety). The client tests start
the shared mock gateway (`../fixtures/mock_gateway.py`) as a subprocess on port
0, read the `PORT <n>` line it prints, and talk to `127.0.0.1` only — no
network, no credentials. The script exits 0 on success and 1 on any failure. If
`python3` is not installed the client tests are reported as skipped and the run
still succeeds; the JSON tests always run.

Without the script:

```bash
mkdir -p build/classes build/test-classes
javac -encoding UTF-8 -Xlint:all -Werror -d build/classes $(find src/main/java -name '*.java')
javac -encoding UTF-8 -Xlint:all -Werror -cp build/classes -d build/test-classes $(find src/test/java -name '*.java')
java -cp build/classes:build/test-classes net.worldtext.api.TestRunner
```

Pass a substring to run a subset (`./run-tests.sh Json`); `TestRunner` exits
non-zero if any test fails or none ran.

There is no `pom.xml`: JUnit is not available in the local Maven repository
(`~/.m2` is empty) and Maven cannot resolve it offline, so the suite runs as a
plain `main()` harness instead of a build that would not work.