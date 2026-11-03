# world-text-python

Python client for the World Text HTTP API (spec version 3.0), using bearer
authentication.

Standard library only — no `pip install`, no dependencies.

## Install

The package is a single directory; vendor it or add it to your path:

```bash
cp -r python/worldtext /path/to/your/project/
```

## Quickstart

```python
from worldtext import WorldText, ApiError, AuthenticationError

wt = WorldText(token="wt_live_xxxxxxxx")      # base URL defaults to production

print(wt.get_credits().credits)                # '2950' — a string, as the API sends it
wt.send_sms(dstaddr="447989000001", message="Hello from Python")
```

## Authentication

Bearer tokens only. Create a token in the admin console or customer portal,
ticking the permissions it needs. There is no query-string `id`/`key` support —
that form is not part of API 3.0.

A token carries one or more of four independent permissions: `sms`, `groups`,
`admin`, `hlr`. Calling an operation outside its permissions raises
`PermissionError_`, with `required_scope` telling you what was missing.

## Methods

| Method | Permission | Notes |
|---|---|---|
| `ping()` | `admin` | Liveness |
| `get_credits()` | `admin` | Balance |
| `get_host()` | `admin` | Serving host name |
| `get_sms_cost(dstaddr, message="")` | `sms` | Price without sending |
| `send_sms(dstaddr, message, **options)` | `sms` | Extra fields pass through |
| `query_sms_status(msgid)` | `sms` | Delivery state |
| `lookup_hlr(msisdn)` | `hlr` | Costs a credit |
| `lookup_mnp(msisdn)` | `hlr` | Porting status |
| `get_number_info(msisdn)` | `hlr` | Country / network |
| `create_group(name)` | `groups` | Returns `grpid` |
| `delete_group(grpid)` | `groups` | |
| `add_group_entry(grpid, number)` | `groups` | |
| `delete_group_entry(grpid, number)` | `groups` | |
| `add_group_entries(grpid, numbers)` | `groups` | List of numbers |
| `get_group_numbers(grpid)` | `groups` | |
| `get_group_details(grpid)` | `groups` | With per-number status |
| `list_groups()` | `groups` | `.groups` |
| `delete_group_contents(grpid)` | `groups` | Keeps the group |
| `get_group_cost(grpid, message="")` | **`sms`** | Prices a send, not a group edit |
| `send_group_sms(grpid, message, **options)` | **`sms`** | |

`get_group_cost` and `send_group_sms` need `sms`, not `groups`. That is the
gateway's actual behaviour, not a documentation slip.

## Numbers are strings

The API returns numbers as JSON strings: `"credits":"2950"`, `"msgid":"10001"`.
The models keep them as strings so nothing is lost in a float round-trip, and
expose `.as_decimal` where a value is genuinely arithmetic.

## Errors

```python
from worldtext import AuthenticationError, PermissionError_, ApiError

try:
    wt.get_credits()
except AuthenticationError:
    ...                      # 401 - token unknown, revoked or malformed
except PermissionError_ as e:
    print(e.required_scope)  # 403 - add this permission to the token
except ApiError as e:
    print(e.error_code)      # 400/1100/... - e.g. '1200' bad arguments
    if e.retryable:          # status '2'/'3', or a 5xx
        ...
```

All errors derive from `WorldTextError` and carry `http_status`, `error_code`
and `desc`. Branch on those, not on message text.

## Token safety

The token never appears in `repr()`, exception messages, or log output — it is
shown as `<redacted:N chars>`. Debug logging goes through the standard
`worldtext` logger.

## Tests

```bash
cd python && python3 -m unittest discover -s tests -v
```

14 tests against a mock gateway (no network, no credentials).