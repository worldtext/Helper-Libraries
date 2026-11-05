# world-text-php

PHP client for the World Text HTTP API (spec version 3.1), using bearer
authentication.

PHP 8.2+, **no dependencies and no Composer**: a small `spl_autoload_register`
bootstrap is all the wiring needed. HTTP goes through ext-curl when it is
compiled in, and through PHP's stream wrapper otherwise.

## Install

Drop `src/WorldText/` into your project and require the bootstrap, or point an
existing PSR-4 autoloader at it:

```php
require __DIR__ . '/src/WorldText/bootstrap.php';
```

With Composer (optional), map the namespace and require `src/WorldText/bootstrap.php`
or let Composer autoload it:

```json
{ "autoload": { "psr-4": { "WorldText\\": "src/WorldText/" } } }
```

## Quickstart

```php
require __DIR__ . '/src/WorldText/bootstrap.php';

use WorldText\WorldText;

$wt = new WorldText('wt_live_xxxxxxxx');       // base URL defaults to production
echo $wt->getCredits()->credits;                // '2950' - a string, as the API sends it
$wt->sendSms('447989000001', 'Hello from PHP');
```

## Authentication

Bearer tokens only: every request carries `Authorization: Bearer <token>`.
API 3.0 has no query-string `id`/`key` scheme and this client does not
implement one.

A token names the interfaces it may be used on: `http`, `email` or `bulk`. It is
not a list of operations: a credential carrying `http` can use this whole API
and can send messages, because that is what a token is issued for. A call from
an interface the token does not carry raises `PermissionError_`.

## Methods

| Method | Interface | Returns | Notes |
|---|---|---|---|
| `ping()` | `http` | `PingResult` | `->ok` |
| `getCredits()` | `http` | `Credits` | `->credits` is a string |
| `getDetails()` | `http` | `AccountDetails` | `->accountid` |
| `getSmsCost($dstaddr, $message = '')` | `http` | `Cost` | Price without sending |
| `sendSms($dstaddr, $message, $options = [])` | `http` | `SendResult` | Extra fields pass through |
| `querySmsStatus($msgid)` | `http` | `SmsStatus` | `->isFinal()` |
| `lookupHlr($msisdn)` | `http` | `HlrResult` | Costs a credit |
| `lookupMnp($msisdn)` | `http` | `MnpResult` | `->isPorted()` |
| `getNumberInfo($msisdn)` | `http` | `NumberInfo` | Country / network |
| `createGroup($name)` | `http` | `CreateGroupResult` | `->grpid` |
| `deleteGroup($grpid)` | `http` | `DeleteResult` | |
| `addGroupEntry($grpid, $number, $options = [])` | `http` | `AddEntriesResult` | |
| `deleteGroupEntry($grpid, $number)` | `http` | `DeleteResult` | |
| `addGroupEntries($grpid, array $numbers)` | `http` | `AddEntriesResult` | List of numbers |
| `getGroupNumbers($grpid)` | `http` | `GroupNumbers` | `->numbers` |
| `getGroupDetails($grpid)` | `http` | `GroupDetails` | With per-number status |
| `listGroups()` | `http` | `GroupList` | `->groups` |
| `deleteGroupContents($grpid)` | `http` | `DeleteResult` | Keeps the group |
| `getGroupCost($grpid, $message = '')` | `http` | `GroupCost` | Prices a send, not a group edit |
| `sendGroupSms($grpid, $message, $options = [])` | `http` | `SendResult` | |


Write operations use `PUT`, deletes use `DELETE`; the gateway accepts `PUT` and
`POST` interchangeably. `requestRaw($method, $path, $params)` reaches a route
this client does not wrap yet, with the same error handling.

### Options and null

Optional fields (`sendid`, `schedule`, `sender`, `udh`, ...) are passed straight
through the `$options` array. A `null` value is omitted from the request rather
than sent as an empty string, which the gateway would treat as a value.

```php
$wt->sendSms('447989000001', 'Hi', ['sendid' => 'order-42', 'sender' => 'ACME']);
```

## Numbers are strings

The API returns numbers as JSON strings: `"credits":"2950"`, `"msgid":"10001"`,
`"status":"0"`. Models keep them as strings so nothing is lost in a float
round-trip, and expose a numeric accessor where a value is genuinely
arithmetic:

```php
$wt->getCredits()->credits;      // '2950'
$wt->getCredits()->asDecimal();  // '2950' - exact, no float involved
$group->sizeValue();             // 2      - a count, so an int is fine
```

`Model::decimal()` normalises without touching a float, so `'1.0'` becomes
`'1'` and a 19-digit message id keeps every digit.

## Errors

```php
use WorldText\Exception\ApiException;
use WorldText\Exception\AuthenticationException;
use WorldText\Exception\PermissionException;
use WorldText\Exception\WorldTextException;

try {
    $wt->getCredits();
} catch (AuthenticationException) {
    // 401 - token unknown, revoked or malformed
} catch (PermissionException $e) {
    echo $e->getRequiredScope();   // 403 - e.g. 'admin': add this to the token
} catch (ApiException $e) {
    echo $e->getErrorCode();       // 400/1200/... - e.g. '1200' bad arguments
    if ($e->isRetryable()) {
        // status '2'/'3', a 5xx, or no HTTP response at all
    }
} catch (WorldTextException $e) {
    // anything else, including transport failures
}
```

| Type | When |
|---|---|
| `WorldTextException` | Base class of every failure |
| `AuthenticationException` | 401 |
| `PermissionException` | 403; `getRequiredScope()` from `WWW-Authenticate` |
| `ApiException` | 400 validation, 5xx, non-JSON body |
| `InvalidArgumentException` | Required argument empty/mistyped; nothing was sent |

Every error carries `getHttpStatus()`, `getErrorCode()`, `getDesc()` and
`getStatusFlag()`. Branch on the type or those values, never on message text.

The envelope `status` field means: `"0"` success, `"1"` permanent, `"2"`/`"3"`
temporary and retryable (`Model\StatusFlag` names these).

## Token safety

The token never appears in `(string)`, `print_r()`, `json_encode()`, exception
messages or log output; it is shown as `<redacted:N chars>`. The credential is
kept in a `Credentials` value whose display forms are redacted, so dumping the
client is safe. `serialize()`/`var_export()` of the client are refused rather
than writing the token into a cache or a queue.

`var_dump()` and `debug_print_backtrace()` can still expose private state;
avoid them in code paths that handle clients.

Introduce a logger once and every client created afterwards uses it. The
interface is the PSR-3 `debug()` slice, so a real PSR-3 logger can be passed
directly:

```php
use WorldText\Logger\RuntimeLogger;
use WorldText\WorldText;

RuntimeLogger::set(WorldText::LOGGER, $myPsrLogger);
```

`Logger\ErrorLogLogger` writes to the PHP error log if one is wanted, and
`Logger\NullLogger` (the default) is silent.

## Transports

`curl` when ext-curl is loaded, otherwise PHP's stream wrapper (requires
`allow_url_fopen=On`). `new WorldText(...)` picks automatically; pass a
`Transport` as the fourth constructor argument to force one. `transportName()`
reports which is in use.

## Timeouts

The third constructor argument is the per-request timeout in seconds
(default 30):

```php
$wt = new WorldText($token, WorldText::DEFAULT_BASE_URL, 10.0);
```

## Tests

```bash
php /home/nevis/MMRP/helper-libraries/php/tests/run.php
```

29 tests against the shared mock gateway, which is started as a subprocess on
port 0 and shut down at the end. No network and no credentials; the run is
skipped (exit 0) when `python3` is not available. The process exits non-zero on
any failure.