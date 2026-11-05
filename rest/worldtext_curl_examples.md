# World Text API 3.0 — cURL cheat-sheet

Every call here has been run against the mock gateway
(`../fixtures/mock_gateway.py`) and produced the response shown. Copy, paste,
adapt the bucket and the number.

**Numbers are JSON strings.** `"credits":"2950"`, `"msgid":"10001"`,
`"status":"0"` — every numeric field is quoted. Do not `parseFloat` them on the
way in: long message ids lose precision in a float. Convert only where the
value is genuinely arithmetic, and convert exactly (`BigDecimal`, `Decimal`,
integer arithmetic) rather than through a binary float.

## 1. The wrapper

Every request needs `Authorization: Bearer <token>`. Putting the header in
every command means it lands in your shell history and in `ps` output. Define
one wrapper instead, reading the token from the environment:

```sh
cat > ~/bin/wtx <<'EOF'
#!/bin/sh
# wtx - World Text: bearer header from the environment, never from argv.
exec curl -sS --fail-with-body \
    -H "Authorization: Bearer ${WT_TOKEN:?set WT_TOKEN first}" \
    -H 'Accept: application/json' "$@"
EOF
chmod +x ~/bin/wtx
export PATH="$HOME/bin:$PATH"
```

Then for the rest of this document:

```sh
export WT_TOKEN='wt_live_xxxxxxxxxxxxxxxxxxxx'      # from the console
export WT_BASE_URL='https://sms.world-text.com/v2.0' # the production default
```

`--fail-with-body` makes curl exit non-zero on 4xx/5xx **while still printing
the JSON error envelope**, so one code path reads both success and failure.
Without it curl exits `0` even for a 401 and you would have to parse the body
to notice.

There is no query-string `id`/`key` authentication in API 3.0. If you are
porting a call from an older guide, delete those parameters; a request carrying
them and no bearer header is a 401.

## 2. Reading vs writing

| | Arguments go | curl flags |
|---|---|---|
| `GET` | query string | `-G --data-urlencode 'k=v'` |
| `PUT` / `POST` | form-encoded body | `-X PUT -d 'k=v' --data-urlencode 'k=v'` |
| `DELETE` | query string | `-X DELETE -G --data-urlencode 'k=v'` |

Always use `--data-urlencode` for anything that is not a bare identifier. `-d`
sends the value literally: a message containing `&`, `=` or `%` will be
truncated or corrupted. Bucket and message values both contain those
characters often enough that this is the single most common cURL mistake
against this API.

The write routes accept `PUT` and `POST` interchangeably, so `-X POST` works
wherever this document says `-X PUT`.

## 3. Permissions

A token carries the interfaces it may be used on: `http`, `email`, `bulk`.
Calling from an interface it does not carry is a **403** whose
`WWW-Authenticate` header names the one that was needed:

```
WWW-Authenticate: Bearer error="insufficient_scope", scope="http"
```

To surface it from the shell:

```sh
wtx -o /dev/null -D - "$WT_BASE_URL/admin/credits" | grep -i '^www-authenticate'
```

Two group routes need `sms`, not `groups`: `/group/cost` and `/group/send`
price and perform a send, so they belong to the send permission. See §5.

## 4. Every operation

### Admin — permission `admin`

#### ping — `GET /admin/ping`

No parameters. Liveness only.

```sh
wtx "$WT_BASE_URL/admin/ping"
```

```json
{"status": "0"}
```

#### getCredits — `GET /admin/credits`

No parameters. The balance is a string; for a prepay account it is credits
available, for an account customer it is credits used this period as a
negative balance.

```sh
wtx "$WT_BASE_URL/admin/credits"
```

```json
{"status": "0", "credits": "2950"}
```

#### getDetails — `GET /admin/details`

No parameters. Which gateway host answered — useful when a DNS name fronts
several.

```sh
wtx "$WT_BASE_URL/admin/details"
```

```json
{"status": "0", "accountid": "4"}
```

### SMS — permission `sms`

#### getSmsCost — `GET /sms/cost`

| Parameter | Required | Notes |
|---|---|---|
| `dstaddr` | yes | E.164 without the `+`: `447989000001` |

```sh
wtx -G --data-urlencode 'dstaddr=447989000001' "$WT_BASE_URL/sms/cost"
```

```json
{"status": "0", "credits": "1.0"}
```

#### sendSms — `PUT /sms/send`  (alias: `POST /sms/send`)

| Parameter | Required | Notes |
|---|---|---|
| `dstaddr` | yes | E.164 without the `+` |
| `txt` | yes | The message body |
| `srcaddr` | no | Sender: numeric (16 digits) or alphanumeric (11 chars) |
| `clientref` | no | ≤32 chars, echoed back on the delivery receipt |
| `sendid` | no | ≤32 chars, your own reference for reporting |
| `multipart` | no | 1–9 parts |
| `enc` | no | `UnicodeBigUnmarked` for non-GSM text |
| `expiry` | no | Seconds; ≤604800 |
| `delay` | no | Seconds to delay submission; ≤60 |
| `data` + `dcs` | no | Binary send: hex payload replaces `txt`; `dcs=2` |
| `sim` | no | Simulated send: processed, never sent, not charged |

```sh
wtx -X PUT --data-urlencode 'dstaddr=447989000001' \
          --data-urlencode 'txt=Hello from cURL' \
          --data-urlencode 'clientref=order-42' \
          "$WT_BASE_URL/sms/send"
```

```json
{"status": "0", "result": [{"msgid": "10001", "credits": "1.0", "sendtime": "2026-10-25 12:00:00", "dstaddr": "447989000001", "status": "5"}]}
```

Keep `msgid` — it is how `sms/query` and the delivery receipt identify this
message. It is a string and can contain hyphens.

#### querySmsStatus — `GET /sms/query`

| Parameter | Required | Notes |
|---|---|---|
| `msgid` | yes | The id returned by `sendSms` |

```sh
wtx -G --data-urlencode 'msgid=10001' "$WT_BASE_URL/sms/query"
```

```json
{"status": "0", "state": "5", "error": "0", "finaldate": "2026-10-25 12:00:05"}
```

`finaldate` is empty while the message is still in flight. Setting up a
delivery-receipt callback is better than polling for anything at volume.

### Lookups — permission `hlr`

All three take `msisdn`, E.164 without the `+`. Each billable lookup costs a
credit, so do not retry them blindly.

#### lookupHlr — `GET /hlr/lookup`

```sh
wtx -G --data-urlencode 'msisdn=447989000001' "$WT_BASE_URL/hlr/lookup"
```

```json
{"status": "0", "docomo": "74450", "region": "UK", "info": "Network Name", "error": "0"}
```

#### lookupMnp — `GET /hlr/mnp`

Number portability: whether the number has been ported and to whom.

```sh
wtx -G --data-urlencode 'msisdn=447989000001' "$WT_BASE_URL/hlr/mnp"
```

```json
{"status": "0", "mnpportstatus": "ported", "mnpportdate": "2020-01-01"}
```

#### getNumberInfo — `GET /hlr/info`

Country and network, plus the credit cost.

```sh
wtx -G --data-urlencode 'msisdn=447989000001' "$WT_BASE_URL/hlr/info"
```

```json
{"status": "0", "cc": "44", "mcc": "234", "mnc": "10", "country": "United Kingdom", "network": "O2-UK", "credits": "1.0"}
```

### Groups — permission `groups`, except `/group/cost` and `/group/send` (`sms`)

Group ids come back as **`grpid`** from most routes and **`groupid`** from
`/group/create`, and some routes accept or return either. Treat the two names
as the same value. The mock, for instance, returns both from
`/group/create` to make the point.

#### createGroup — `PUT /group/create`

| Parameter | Required | Notes |
|---|---|---|
| `name` | yes | Group name, no spaces |
| `srcaddr` | no | Default sender shown to recipients |
| `pin` | no | Remote dispatch PIN, usually `0000` |

```sh
wtx -X PUT --data-urlencode 'name=test-group' "$WT_BASE_URL/group/create"
```

```json
{"status": "0", "grpid": "4242", "groupid": "4242"}
```

#### deleteGroup — `DELETE /group/destroy`

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | Deletes the group **and every number in it** |

```sh
wtx -X DELETE -G --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/destroy"
```

```json
{"status": "0"}
```

#### addGroupEntry — `PUT /group/entry`

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | |
| `dstaddr` | yes | The number to add — note this route says `dstaddr`, not `number` |
| `name` | no | Entry name |

```sh
wtx -X PUT --data-urlencode 'grpid=4242' \
          --data-urlencode 'dstaddr=447989000001' \
          --data-urlencode 'name=Harry' \
          "$WT_BASE_URL/group/entry"
```

```json
{"status": "0", "added": "1", "failed": "0"}
```

#### deleteGroupEntry — `DELETE /group/entry`

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | |
| `dstaddr` | yes | The number to remove |

Arguments go in the **query string** here. A `DELETE` with a form body is not
read back reliably; send `-X DELETE -G --data-urlencode`, as above.

```sh
wtx -X DELETE -G --data-urlencode 'grpid=4242' \
                 --data-urlencode 'dstaddr=447989000001' \
                 "$WT_BASE_URL/group/entry"
```

```json
{"status": "0", "added": "1", "failed": "0"}
```

#### addGroupEntries — `PUT /group/entries`

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | |
| `members` | yes | `number:name,number:name` — one string, comma separated |

Every entry needs the colon. `members=447989000001` alone is a usage error;
`members=447989000001:` sends that number with an empty name.

```sh
wtx -X PUT --data-urlencode 'grpid=4242' \
          --data-urlencode 'members=447989000001:Harry,447989000002:Sam' \
          "$WT_BASE_URL/group/entries"
```

```json
{"status": "0", "added": "2", "failed": "0"}
```

#### getGroupNumbers — `GET /group/numbers`

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | |

```sh
wtx -G --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/numbers"
```

```json
{"status": "0", "numbers": ["447989000001", "447989000002"]}
```

#### getGroupDetails — `GET /group/details`

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | |

```sh
wtx -G --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/details"
```

```json
{"status": "0", "details": [{"number": "447989000001", "status": "5"}, {"number": "447989000002", "status": "5"}]}
```

#### listGroups — `GET /group/list`

No parameters.

```sh
wtx "$WT_BASE_URL/group/list"
```

```json
{"status": "0", "group": [{"grpid": "4242", "name": "test-group", "size": "2"}]}
```

#### deleteGroupContents — `DELETE /group/contents`

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | Empties the group, keeps it |

```sh
wtx -X DELETE -G --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/contents"
```

```json
{"status": "0", "deleted": "2"}
```

#### getGroupCost — `GET /group/cost`  — needs **`sms`**

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | |
| `multipart` | no | Message parts to price |

```sh
wtx -G --data-urlencode 'grpid=4242' "$WT_BASE_URL/group/cost"
```

```json
{"status": "0", "credits": "2.0", "entrycount": "2"}
```

A token carrying only `groups` gets a 403 here with `scope="sms"`. That is
deliberate: this route prices a send, so it belongs to the send permission.

#### sendGroupSms — `PUT /group/send`  — needs **`sms`**

| Parameter | Required | Notes |
|---|---|---|
| `grpid` | yes | |
| `txt` | yes | The message body |
| `srcaddr` | no | Default sender |
| `clientref` | no | ≤32 chars |
| `sendid` | no | Your reference |
| `multipart` | no | 1–9 parts |
| `enc` | no | `UnicodeBigUnmarked` for non-GSM text |
| `sim` | no | Simulated send, not charged |

```sh
wtx -X PUT --data-urlencode 'grpid=4242' \
          --data-urlencode 'txt=Hello group' \
          "$WT_BASE_URL/group/send"
```

```json
{"status": "0", "credits": "2.0", "entrycount": "2", "result": [{"msgid": "10002", "credits": "1.0", "status": "5"}]}
```

Same permission rule as the cost: `sms`, not `groups`.

## 5. Errors

Non-2xx responses carry a JSON envelope, even on 401 and 403:

```json
{"status": "1", "error": "1000", "desc": "Authorisation Failure"}
```

| HTTP | Meaning | Typical `error` |
|---|---|---|
| 400 | bad or missing argument | `1200` Incorrect arguments specified, `1201` Unknown Command |
| 401 | token missing, unknown or revoked | `1000` Authorisation Failure |
| 403 | token valid, permission wrong | `1000`, with `WWW-Authenticate: ... scope="..."` |

Other `error` values you will meet on a live account: `1100` insufficient
funds, `1203` service not provisioned, `1025` account disabled, `1302` group
does not exist.

`status` in the envelope is `"0"` success, `"1"` permanent failure, `"2"`/`"3"`
temporary, meaning retry with backoff. A `5xx` from a proxy is also worth
retrying.

```sh
# See the status code and the envelope separately.
wtx -o /tmp/body.json -w '%{http_code}\n' -G --data-urlencode 'dstaddr=' \
    "$WT_BASE_URL/sms/cost"; cat /tmp/body.json
```

```
400
{"status": "1", "error": "1200", "desc": "Incorrect arguments specified"}
```

Only retry `400`-class failures after fixing the request. Retrying a `1200` in
a loop will simply be refused repeatedly, and retrying a lookup bills a credit
each time.

## 6. One-shot recipes

```sh
# Send, then capture the message id.
msgid=$(wtx -X PUT --data-urlencode 'dstaddr=447989000001' \
              --data-urlencode 'txt=Hello' \
              "$WT_BASE_URL/sms/send" |
        sed -n 's/.*"msgid": *"\([^"]*\)".*/\1/p')
echo "sent as $msgid"

# Check the balance before a bulk send.
wtx "$WT_BASE_URL/admin/credits" | sed -n 's/.*"credits": *"\([^"]*\)".*/\1/p'
```

`jq` does the same more readably if you have it:

```sh
wtx "$WT_BASE_URL/admin/credits" | jq -r '.credits'
```

## 7. Testing against the mock

`test.sh` starts the mock for you. To drive cURL by hand:

```sh
python3 ../fixtures/mock_gateway.py 0 &      # prints "PORT <n>"
export WT_BASE_URL="http://127.0.0.1:<n>"
export WT_TOKEN=test-all                     # http+email+bulk

wtx "$WT_BASE_URL/admin/credits"
# {"status": "0", "credits": "2950"}
```

Mock tokens: `test-http`, `test-all` (http+email+bulk), and `test-email`,
`test-bulk`, `test-none` for the refusal paths. Anything else is a 401.