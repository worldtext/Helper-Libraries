# world-text (Node)

Client for the World Text HTTP API (spec version 3.0), using bearer
authentication.

Zero dependencies — `node:http`/`node:https` for transport, `node:test` and
`node:assert` for tests. ES modules only. Requires Node 20+ (developed and
tested on Node 26); it needs no build step, and JSDoc annotations give editors
type hints without TypeScript.

## Install

The package is a single directory with no dependencies; vendor it or link it:

```bash
cp -r node /path/to/your/project/vendor/world-text
```

Then import it by path, or add a dependency on the folder:

```bash
npm install /path/to/helper-libraries/node
```

## Quickstart

```js
import { WorldText, AuthenticationError, PermissionError } from 'world-text';

const wt = new WorldText({ token: process.env.WORLD_TEXT_TOKEN });

console.log((await wt.getCredits()).credits);   // '2950' - a string, as the API sends it
const sent = await wt.sendSms('447989000001', 'Hello from Node');
console.log(sent.msgid);                         // '10001', also a string
try { await wt.ping(); } catch (e) { console.log(e.name, e.message); }
```

## Authentication

Bearer tokens only — every request sends `Authorization: Bearer <token>`. There
is no query-string `id`/`key` support; that form is not part of API 3.0.

A token names the interfaces it may be used on: `http`, `email` or `bulk`. It is
not a list of operations: a credential carrying `http` can use this whole API
and can send messages, because that is what a token is issued for. A call from
an interface the token does not carry raises `PermissionError_`.

```js
const wt = new WorldText({ token, permissions: ['sms', 'admin'] });
await wt.getCredits();                 // fine
await wt.listGroups();                 // PermissionError, requiredScope: 'groups', no request sent
```

## Methods

All methods are `async` and resolve with a typed model (see `lib/models.js`).
Extras after a `[options]` argument are passed through to the API untouched.

| Method | Interface | Resolves with | Notes |
|---|---|---|---|
| `ping()` | `http` | `PingResult` | Liveness (`.ok`) |
| `getCredits()` | `http` | `Credits` | Balance |
| `getHost()` | `http` | `HostName` | Serving host name |
| `getSmsCost(dstaddr, message?)` | `http` | `Credits` | Price without sending |
| `sendSms(dstaddr, message, [options])` | `http` | `SendResult` | Extra fields pass through |
| `querySmsStatus(msgid)` | `http` | `SmsStatus` | Delivery state (`.final`) |
| `lookupHlr(msisdn)` | `http` | `HlrResult` | Costs a credit |
| `lookupMnp(msisdn)` | `http` | `MnpResult` | Porting status |
| `getNumberInfo(msisdn)` | `http` | `NumberInfo` | Country / network |
| `createGroup(name)` | `http` | `CreateGroupResult` | Returns `.grpid` |
| `deleteGroup(grpid)` | `http` | `DeleteResult` | |
| `addGroupEntry(grpid, number, [options])` | `http` | `AddEntriesResult` | |
| `deleteGroupEntry(grpid, number)` | `http` | `DeleteResult` | |
| `addGroupEntries(grpid, numbers)` | `http` | `AddEntriesResult` | Any iterable of numbers |
| `getGroupNumbers(grpid)` | `http` | `GroupNumbers` | |
| `getGroupDetails(grpid)` | `http` | `GroupDetails` | With per-number status |
| `listGroups()` | `http` | `GroupList` | `.groups` |
| `deleteGroupContents(grpid)` | `http` | `DeleteResult` | Keeps the group |
| `getGroupCost(grpid, message?)` | `http` | `GroupCost` | Prices a send, not a group edit |
| `sendGroupSms(grpid, message, [options])` | `http` | `SendResult` | |


Write routes accept `PUT` and `POST` interchangeably; the client sends `PUT`
with a form-encoded body (and a `Content-Length`) for writes and query
parameters for `GET`/`DELETE`.

## Numbers are strings

The API returns numbers as JSON strings: `"credits":"2950"`, `"msgid":"10001"`,
`"status":"0"`. The models keep them as strings so nothing is lost in a float
round-trip, and offer `asDecimal` where a value is genuinely arithmetic:

```js
const { credits } = await wt.getCredits();
typeof credits;        // 'string'
credits;               // '2950'
(await wt.getSmsCost('447989000001')).asDecimal;   // '1'  (exact, from '1.0')
```

Group ids are read from either `grpid` or `groupid`, whichever the route sends.

## Errors

All errors derive from `WorldTextError` and carry `httpStatus`, `errorCode`,
`desc` and `retryable`. Branch on the class, not on message text.

| Class | When | Extra |
|---|---|---|
| `AuthenticationError` | 401: token missing, unknown or revoked | |
| `PermissionError` | 403: token lacks the permission | `requiredScope` |
| `ApiError` | any other error response (400/`1200` bad arguments, 5xx, ...) | |
| `TransportError` | no usable HTTP response: DNS, connection refused, TLS, timeout, truncated body | `httpStatus` is `null` |

```js
import { AuthenticationError, PermissionError, ApiError } from 'world-text';

try {
  await wt.getCredits();
} catch (error) {
  if (error instanceof AuthenticationError) {
    // renew the token
  } else if (error instanceof PermissionError) {
    console.error('add this scope to the token:', error.requiredScope);
  } else if (error instanceof ApiError) {
    console.error(error.errorCode, error.desc);   // e.g. '1200'
    if (error.retryable) { /* status '2'/'3', a 5xx, or no reply at all */ }
  }
}
```

`retryable` is true when the envelope's `status` is `"2"` or `"3"`, when the
HTTP status is 5xx/408/429, or when the request got no reply at all. A 4xx
other than 408/429 will fail the same way if resent unchanged.

## Token safety

The token never appears in `toString()`, `String(client)`, `console.log(client)`,
exception messages or debug events. It is shown as `<redacted:N chars>`. The
token is not stored on the instance at all (it lives in a module-private
`WeakMap`), so `JSON.stringify`, `structuredClone` and object spreads cannot
carry it out.

```js
String(wt);   // WorldText(baseUrl="https://sms.world-text.com/v2.0", token=<redacted:32 chars>)
```

Log requests and failures without writing a logging dependency:

```js
wt.events.addEventListener('request', ({ detail }) => {
  console.log(detail.method, detail.path, detail.keys);  // names only, never values
});
wt.events.addEventListener('error', ({ detail }) => {
  console.log(detail.httpStatus, detail.errorCode, detail.retryable);
});
```

## Constructor options

| Option | Default | |
|---|---|---|
| `token` | — | Required. Bearer token. |
| `baseUrl` | `https://sms.world-text.com/v2.0` | Override for a regional host or the mock. |
| `timeout` | `30` | Per-request deadline, in **seconds**. |
| `signal` | `null` | An `AbortSignal` cancelling in-flight requests. |
| `permissions` | `null` | The token's permissions; enables the client-side 403 checks. |

## Tests

```bash
cd /home/nevis/MMRP/helper-libraries/node && node --test
```

36 tests, in ~4 s, against the shared mock gateway
(`../fixtures/mock_gateway.py`, started as a subprocess on port 0) plus a local
throwaway HTTP server for transport edge cases. No network and no credentials
are used, and the suite skips itself (rather than failing) when `python3` is
absent.

The JSDoc that editors read can be checked without adding a dependency, if you
have TypeScript available somewhere:

```bash
node scripts/typecheck.mjs /path/to/node_modules/typescript/lib/typescript.js
```

## Layout

```
index.js                 public entry point (package "exports")
lib/client.js            the WorldText client: the 20 operations
lib/errors.js            typed error hierarchy
lib/models.js            typed responses
lib/http.js              small node:http transport with a per-request deadline
test/client.test.js      the suite, against the mock gateway
test/skip-without-python.test.js   proves the suite skips without python3
test/http.test.js        transport: deadlines, aborts, truncated bodies
test/support/mock.js     mock gateway subprocess helper
scripts/typecheck.mjs    optional JSDoc type check (needs a local TypeScript)
example.js               runnable quickstart (`node example.js`)
```

## Known limitations

- No transparent retries. Callers retry themselves, guided by `retryable`.
- One TCP connection per request (`agent: false`); there is no connection pool.
- The transport is `node:http`/`node:https` rather than `fetch`. `fetch`'s
  `AbortSignal.timeout` keeps the event loop alive for the whole timeout, so a
  one-shot script (or test run) would linger; a per-request deadline exits at
  once. `fetch` also sends PUT/POST bodies chunked, which simple gateways and
  proxies mis-decode.
- A connection cut off mid-body is reported as a `TransportError`; it keeps the
  HTTP status it had received, so a 5xx body cut short stays retryable.