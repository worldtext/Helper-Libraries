# World Text HTTP API 3.0 — REST / cURL reference

A dependency-free reference so a caller in **any** language can copy working
calls. Nothing here is compiled or installed: it is a request specification, a
POSIX shell helper, and a test suite that proves both against a mock gateway.

| File | What it is |
|---|---|
| `README.md` | This file — every request and response, with permissions |
| `worldtext.sh` | POSIX shell helper: `wt_send_sms`, `wt_get_credits`, … |
| `worldtext_curl_examples.md` | Tested cURL cheat-sheet |
| `test.sh` | Self-contained test suite (starts the mock, asserts, exits non-zero on failure) |

Requirements: **curl** and **python3** (python3 only to run the mock gateway in
the tests). `worldtext.sh` needs nothing but a POSIX `sh` — dash, bash, BSD sh
and busybox ash all work.

---

## Install

```sh
git clone <your checkout>
cd helper-libraries/rest

# Use the shell helper by sourcing it, not by executing it:
. ./worldtext.sh
```

Optionally install it somewhere permanent and source it from your profile:

```sh
install -m 0644 worldtext.sh ~/.local/share/worldtext.sh
echo '. ~/.local/share/worldtext.sh' >> ~/.profile
```

## Quickstart

```sh
. ./worldtext.sh

export WT_TOKEN='wt_live_xxxxxxxxxxxxxxxxxxxx'    # required, bearer token
export WT_BASE_URL='https://sms.world-text.com/v2.0'   # optional; this is the default

wt_get_credits                       # {"status": "0", "credits": "2950"}
wt_send_sms 447989000001 'Hello'     # {"status": "0", "result": [{"msgid": "10001", ...}]}
```

## Authentication

Bearer tokens only:

```
Authorization: Bearer <token>
```

There is **no** query-string `id`/`key` authentication in API 3.0. A request
that carries them and no bearer header is a 401.

A token carries one or more of four independent permissions:

| Permission | Covers |
|---|---|
| `admin` | `ping`, `getCredits`, `getHost` |
| `sms` | `getSmsCost`, `sendSms`, `querySmsStatus`, **`getGroupCost`**, **`sendGroupSms`** |
| `groups` | `createGroup` … `deleteGroupContents` (group administration) |
| `hlr` | `lookupHlr`, `lookupMnp`, `getNumberInfo` |

`getGroupCost` and `sendGroupSms` need **`sms`, not `groups`**. That is the
gateway's behaviour, not a documentation slip: those routes price and perform a
send, so they belong to the send permission.

The helper never prints, logs or puts the token on a command line. It reaches
curl through a private config file that is removed as soon as the request
finishes, so it does not appear in `ps` output either.

---

## All 20 operations

Base URL: `https://sms.world-text.com/v2.0`. Every response is JSON and every
**number is a string**: `"credits":"2950"`, `"msgid":"10001"`, `"status":"0"`.
Keep them as strings in your own types; convert with exact arithmetic only
where a value is genuinely numeric (`"credits"`), never by parsing into a
float, and never for ids.

`PUT` and `POST` are interchangeable on every write route.

### Admin — permission `admin`

#### `ping` — liveness

| | |
|---|---|
| Method / path | `GET /admin/ping` |
| Parameters | none |
| Permission | `admin` |
| Helper | `wt_ping` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" "$WT_BASE_URL/admin/ping"
```

```json
{"status": "0"}
```

#### `getCredits` — credit balance

| | |
|---|---|
| Method / path | `GET /admin/credits` |
| Parameters | none |
| Permission | `admin` |
| Helper | `wt_get_credits` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" "$WT_BASE_URL/admin/credits"
```

```json
{"status": "0", "credits": "2950"}
```

`credits` is a **string**. Prepay accounts see credits remaining; account
customers see credits used this period as a negative balance.

#### `getHost` — name of the serving host

| | |
|---|---|
| Method / path | `GET /admin/host` |
| Parameters | none |
| Permission | `admin` |
| Helper | `wt_get_host` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" "$WT_BASE_URL/admin/host"
```

```json
{"status": "0", "hostname": "sms1.example"}
```

### SMS — permission `sms`

#### `getSmsCost` — price one message without sending it

| | |
|---|---|
| Method / path | `GET /sms/cost` |
| Required | `dstaddr` — E.164 without the leading `+` |
| Permission | `sms` |
| Helper | `wt_get_sms_cost <dstaddr> [message]` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'dstaddr=447989000001' "$WT_BASE_URL/sms/cost"
```

```json
{"status": "0", "credits": "1.0"}
```

#### `sendSms` — send to one destination

| | |
|---|---|
| Method / path | `PUT /sms/send` (alias: `POST /sms/send`) |
| Required | `dstaddr`, `txt` |
| Permission | `sms` |
| Helper | `wt_send_sms <dstaddr> <message> [param=value ...]` |

| Parameter | Required | Notes |
|---|---|---|
| `dstaddr` | yes | E.164 without the `+` |
| `txt` | yes | The message body |
| `srcaddr` | no | Numeric (≤16 digits) or alphanumeric (≤11 chars) sender |
| `clientref` | no | ≤32 chars; echoed on the delivery receipt |
| `sendid` | no | ≤32 chars; your own reference for reporting |
| `multipart` | no | 1–9 message parts |
| `enc` | no | `UnicodeBigUnmarked` for non-GSM text |
| `expiry` | no | Validity in seconds, ≤604800 |
| `delay` | no | Seconds to hold submission, ≤60 |
| `data` + `dcs` | no | Binary send: hex payload replaces `txt`; set `dcs=2` |
| `sim` | no | Simulated send: processed, not sent, not charged |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X PUT \
     --data-urlencode 'dstaddr=447989000001' \
     --data-urlencode 'txt=Hello from cURL' \
     "$WT_BASE_URL/sms/send"
```

```json
{"status": "0", "result": [{"msgid": "10001", "credits": "1.0", "sendtime": "2026-10-25 12:00:00", "dstaddr": "447989000001", "status": "5"}]}
```

`msgid` is what `querySmsStatus` and the delivery receipt key on. Keep it as a
string — it may contain hyphens and is not a number.

#### `querySmsStatus` — delivery state of a sent message

| | |
|---|---|
| Method / path | `GET /sms/query` |
| Required | `msgid` |
| Permission | `sms` |
| Helper | `wt_query_sms_status <msgid>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'msgid=10001' "$WT_BASE_URL/sms/query"
```

```json
{"status": "0", "state": "5", "error": "0", "finaldate": "2026-10-25 12:00:05"}
```

`finaldate` is absent until the message reaches a final state. At volume, use a
delivery-receipt callback rather than polling.

### Lookups — permission `hlr`

All take `msisdn` (E.164, no `+`) and each successful lookup costs a credit.

#### `lookupHlr`

| | |
|---|---|
| Method / path | `GET /hlr/lookup` |
| Required | `msisdn` |
| Permission | `hlr` |
| Helper | `wt_lookup_hlr <msisdn>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'msisdn=447989000001' "$WT_BASE_URL/hlr/lookup"
```

```json
{"status": "0", "docomo": "74450", "region": "UK", "info": "Network Name", "error": "0"}
```

#### `lookupMnp`

| | |
|---|---|
| Method / path | `GET /hlr/mnp` |
| Required | `msisdn` |
| Permission | `hlr` |
| Helper | `wt_lookup_mnp <msisdn>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'msisdn=447989000001' "$WT_BASE_URL/hlr/mnp"
```

```json
{"status": "0", "mnpportstatus": "ported", "mnpportdate": "2020-01-01"}
```

#### `getNumberInfo`

| | |
|---|---|
| Method / path | `GET /hlr/info` |
| Required | `msisdn` |
| Permission | `hlr` |
| Helper | `wt_get_number_info <msisdn>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'msisdn=447989000001' "$WT_BASE_URL/hlr/info"
```

```json
{"status": "0", "cc": "44", "mcc": "234", "mnc": "10", "country": "United Kingdom", "network": "O2-UK", "credits": "1.0"}
```

### Groups — permission `groups` (except the two marked `sms`)

**Group id naming.** The id comes back as **`grpid`** from most routes and
**`groupid`** from `/group/create`; some routes accept either. Treat them as
one value. The helper accepts `4242`, `grpid=4242` and `groupid=4242`
anywhere a group id is wanted.

#### `createGroup`

| | |
|---|---|
| Method / path | `PUT /group/create` |
| Required | `name` |
| Optional | `srcaddr`, `pin` |
| Permission | `groups` |
| Helper | `wt_create_group <name> [srcaddr=...] [pin=...]` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X PUT \
     --data-urlencode 'name=test-group' "$WT_BASE_URL/group/create"
```

```json
{"status": "0", "grpid": "4242", "groupid": "4242"}
```

#### `deleteGroup` — delete a group and everything in it

| | |
|---|---|
| Method / path | `DELETE /group/destroy` |
| Required | `grpid` |
| Permission | `groups` |
| Helper | `wt_delete_group <grpid>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X DELETE -G \
     --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/destroy"
```

```json
{"status": "0"}
```

#### `addGroupEntry` — add one number

| | |
|---|---|
| Method / path | `PUT /group/entry` |
| Required | `grpid`, `dstaddr` |
| Optional | `name` |
| Permission | `groups` |
| Helper | `wt_add_group_entry <grpid> <number> [name=...]` |

This route names the number **`dstaddr`**, unlike the other group routes.

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X PUT \
     --data-urlencode 'grpid=4242' \
     --data-urlencode 'dstaddr=447989000001' \
     --data-urlencode 'name=Harry' \
     "$WT_BASE_URL/group/entry"
```

```json
{"status": "0", "added": "1", "failed": "0"}
```

#### `deleteGroupEntry` — remove one number

| | |
|---|---|
| Method / path | `DELETE /group/entry` |
| Required | `grpid`, `dstaddr` |
| Permission | `groups` |
| Helper | `wt_delete_group_entry <grpid> <number>` |

Arguments ride in the **query string**: a form-encoded `DELETE` body is not
read back reliably.

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X DELETE -G \
     --data-urlencode 'grpid=4242' \
     --data-urlencode 'dstaddr=447989000001' \
     "$WT_BASE_URL/group/entry"
```

```json
{"status": "0", "added": "1", "failed": "0"}
```

#### `addGroupEntries` — add several numbers

| | |
|---|---|
| Method / path | `PUT /group/entries` |
| Required | `grpid`, `members` |
| Permission | `groups` |
| Helper | `wt_add_group_entries <grpid> 'number:name,…'` or `… <number> [number …]` |

`members` is one comma-separated string of `number:name` pairs. Every entry
needs its colon: `447989000001` alone is rejected, `447989000001:` means "this
number with no name".

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X PUT \
     --data-urlencode 'grpid=4242' \
     --data-urlencode 'members=447989000001:Harry,447989000002:Sam' \
     "$WT_BASE_URL/group/entries"
```

```json
{"status": "0", "added": "2", "failed": "0"}
```

#### `getGroupNumbers` — numbers only

| | |
|---|---|
| Method / path | `GET /group/numbers` |
| Required | `grpid` |
| Permission | `groups` |
| Helper | `wt_get_group_numbers <grpid>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/numbers"
```

```json
{"status": "0", "numbers": ["447989000001", "447989000002"]}
```

#### `getGroupDetails` — numbers with status

| | |
|---|---|
| Method / path | `GET /group/details` |
| Required | `grpid` |
| Permission | `groups` |
| Helper | `wt_get_group_details <grpid>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/details"
```

```json
{"status": "0", "details": [{"number": "447989000001", "status": "5"}, {"number": "447989000002", "status": "5"}]}
```

#### `listGroups` — every group on the account

| | |
|---|---|
| Method / path | `GET /group/list` |
| Parameters | none |
| Permission | `groups` |
| Helper | `wt_list_groups` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" "$WT_BASE_URL/group/list"
```

```json
{"status": "0", "group": [{"grpid": "4242", "name": "test-group", "size": "2"}]}
```

#### `deleteGroupContents` — empty the group, keep it

| | |
|---|---|
| Method / path | `DELETE /group/contents` |
| Required | `grpid` |
| Permission | `groups` |
| Helper | `wt_delete_group_contents <grpid>` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X DELETE -G \
     --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/contents"
```

```json
{"status": "0", "deleted": "2"}
```

#### `getGroupCost` — price a send to a whole group — **permission `sms`**

| | |
|---|---|
| Method / path | `GET /group/cost` |
| Required | `grpid` |
| Optional | `multipart` |
| Permission | **`sms`** |
| Helper | `wt_get_group_cost <grpid> [multipart]` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -G \
     --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/cost"
```

```json
{"status": "0", "credits": "2.0", "entrycount": "2"}
```

A `groups`-only token is refused here with `scope="sms"`.

#### `sendGroupSms` — send to every number in a group — **permission `sms`**

| | |
|---|---|
| Method / path | `PUT /group/send` (alias: `POST /group/send`) |
| Required | `grpid`, `txt` |
| Optional | `srcaddr`, `clientref`, `sendid`, `multipart`, `enc`, `sim` |
| Permission | **`sms`** |
| Helper | `wt_send_group_sms <grpid> <message> [param=value ...]` |

```sh
curl -sS -H "Authorization: Bearer $WT_TOKEN" -X PUT \
     --data-urlencode 'grpid=4242' \
     --data-urlencode 'txt=Hello group' \
     "$WT_BASE_URL/group/send"
```

```json
{"status": "0", "credits": "2.0", "entrycount": "2", "result": [{"msgid": "10002", "credits": "1.0", "status": "5"}]}
```

---

## The shell helper

All 20 operations, plus four accessors. Every function prints the gateway's
JSON envelope on stdout and returns 0 on success.

| Operation | Function | Permission |
|---|---|---|
| `ping` | `wt_ping` | `admin` |
| `getCredits` | `wt_get_credits` | `admin` |
| `getHost` | `wt_get_host` | `admin` |
| `getSmsCost` | `wt_get_sms_cost <dstaddr> [message]` | `sms` |
| `sendSms` | `wt_send_sms <dstaddr> <message> [param=value …]` | `sms` |
| `querySmsStatus` | `wt_query_sms_status <msgid>` | `sms` |
| `lookupHlr` | `wt_lookup_hlr <msisdn>` | `hlr` |
| `lookupMnp` | `wt_lookup_mnp <msisdn>` | `hlr` |
| `getNumberInfo` | `wt_get_number_info <msisdn>` | `hlr` |
| `createGroup` | `wt_create_group <name> [srcaddr=…] [pin=…]` | `groups` |
| `deleteGroup` | `wt_delete_group <grpid>` | `groups` |
| `addGroupEntry` | `wt_add_group_entry <grpid> <number> [name=…]` | `groups` |
| `deleteGroupEntry` | `wt_delete_group_entry <grpid> <number>` | `groups` |
| `addGroupEntries` | `wt_add_group_entries <grpid> <number> [number …]` | `groups` |
| `getGroupNumbers` | `wt_get_group_numbers <grpid>` | `groups` |
| `getGroupDetails` | `wt_get_group_details <grpid>` | `groups` |
| `listGroups` | `wt_list_groups` | `groups` |
| `deleteGroupContents` | `wt_delete_group_contents <grpid>` | `groups` |
| `getGroupCost` | `wt_get_group_cost <grpid> [multipart]` | **`sms`** |
| `sendGroupSms` | `wt_send_group_sms <grpid> <message> [param=value …]` | **`sms`** |

Optional `param=value` arguments are sent verbatim, so `clientref=`,
`srcaddr=`, `sim=` and the rest work without the helper enumerating them.

### Exit codes

Branch on the exit status, not on message text:

| Exit | `WT_ERROR_KIND` | Meaning |
|---|---|---|
| 0 | — | success; the JSON envelope is on stdout |
| 2 | `usage` | bad arguments or `WT_TOKEN` unset — no request was made |
| 3 | `authentication` | **401** — token missing, unknown or revoked |
| 4 | `permission` | **403** — token valid, wrong permission |
| 5 | `api` | any other error envelope (400, 1100, 5xx, …) |
| 5 | `transport` | could not reach the gateway |

```sh
if ! wt_get_credits > credits.json; then
    case $WT_ERROR_KIND in
        authentication) echo 'fix WT_TOKEN' >&2 ;;
        permission)     echo "token needs the '$WT_REQUIRED_SCOPE' permission" >&2 ;;
        api|transport)  echo "failed: $WT_ERROR_STATUS / $WT_ERROR_CODE" >&2 ;;
        usage)          echo 'bug in this script' >&2 ;;
    esac
    exit 1
fi
```

After a successful call, the `WT_ERROR_*` variables are cleared.

### Error details, including inside `$(...)`

Assigning the result of a function runs it in a subshell, so its variables
would not survive — `out=$(wt_get_credits)` cannot set `WT_REQUIRED_SCOPE` in
your shell. The helper therefore also records the last failure and offers
readers for it:

```sh
out=$(wt_get_credits) || {
    echo "kind:  $(wt_last_error_kind)"      # authentication | permission | api | transport
    echo "http:  $(wt_last_status)"          # 401, 403, 400, 500, ...
    echo "code:  $(wt_last_code)"            # 1000, 1200, 1100, ...
    echo "scope: $(wt_last_scope)"           # admin, sms, groups, hlr (403 only)
    echo "desc:  $(wt_last_desc)"            # the gateway's own message
    retry=$(wt_last_retryable)               # 1 when status "2"/"3" or HTTP 5xx
}
```

A plain, non-subshell call sets both the variables and the readers.

### `status` and retries

The envelope's `status` is `"0"` success, `"1"` permanent failure, and
`"2"`/`"3"` temporary. `wt_last_retryable` returns `1` for `"2"`/`"3"` and for
any HTTP 5xx. Retry those with backoff; never retry a `400`.

### Token safety

* **Redacted in messages**: the token never appears in an error, a diagnostic
  or `wt_describe`; it is shown as `<redacted:N chars>`.
* **Never on a command line**: it reaches curl through a `umask 077`
  temporary config file, so it is absent from `ps`, and the file is removed on
  success, failure and interruption alike.
* **Never logged**: responses are scrubbed for the token before printing, in
  case a gateway echoes a header back.
* **Never in a URL**: authentication is the `Authorization` header only.

### Environment

| Variable | Default | Meaning |
|---|---|---|
| `WT_TOKEN` | — | **Required.** Bearer token. |
| `WT_BASE_URL` | `https://sms.world-text.com/v2.0` | Gateway base URL |
| `WT_TIMEOUT` | `30` | Connect and total timeout, seconds |
| `WT_INSECURE` | unset | `1` accepts any TLS certificate. Testing only. |

The functions never change shell options, so sourcing this file is safe under
`set -eu`.

---

## Tests

The suite starts the mock gateway on port 0, reads the port it prints, runs
every operation and every error path against it, asserts, and shuts it down.
It touches nothing but `127.0.0.1`.

```sh
cd helper-libraries/rest && ./test.sh
```

```sh
# Verbose: show each response body as well.
./test.sh -v

# Run the library under a specific shell.
WT_TEST_SHELL=dash ./test.sh
WT_TEST_SHELL=bash ./test.sh
```

It prints `N passed, M failed` and exits **0** only when every assertion
passed. If `python3` or `curl` is missing it prints `SKIP:` and exits **77**,
the usual "skipped" convention.

The suite covers all 20 operations, the 401/403/400 paths, exposed scopes,
argument validation, token redaction in messages and in echoed responses,
transport failures, bearer-only authentication, and that no token-bearing file
is left behind.

## Notes on the API contract

* **Numbers are strings** — `"credits":"2950"`, `"msgid":"10001"`,
  `"status":"0"`. Keep them as strings; parse exactly where arithmetic is
  needed.
* **Error envelope on non-2xx** — `{"status":"1","error":"1000","desc":"…"}`,
  including on 401 and 403.
* **401** unknown or missing token; **403** valid token, wrong permission, with
  `WWW-Authenticate: Bearer error="insufficient_scope", scope="admin"`;
  **400** argument validation (`1200`).
* **Group id** arrives as `grpid` or `groupid` depending on the route.
* **`PUT` and `POST`** are interchangeable on write routes.
* **`DELETE` arguments** go in the query string.

Common `error` codes: `1000` authorisation failure, `1025` account disabled,
`1100` insufficient funds, `1200` incorrect arguments, `1201` unknown command,
`1203` service not provisioned, `1301` group empty, `1302` group does not
exist.