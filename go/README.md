# world-text-go

Go client for the World Text HTTP API (spec version 3.1), using bearer
authentication. Go 1.22+, standard library only — no third-party modules.

Module path: `nevis.io/worldtext`, package `worldtext`.

## Install

```bash
go get nevis.io/worldtext
```

Or vendor the directory into your own module and point the module path in
`go.mod` at your location.

## Quickstart

```go
package main

import (
	"context"
	"errors"
	"log"

	"nevis.io/worldtext"
)

func main() {
	ctx := context.Background()
	client, err := worldtext.NewClient(worldtext.DefaultBaseURL, "wt_live_xxxxxxxx")
	if err != nil {
		log.Fatal(err) // never contains the token
	}

	credits, err := client.GetCredits(ctx)
	if err != nil {
		log.Fatal(err)
	}
	log.Println("balance:", credits.Credits) // "2950" - a string, as the API sends it

	sent, err := client.SendSms(ctx, "447989000001", "Hello from Go", nil)
	if err != nil {
		var permErr *worldtext.PermissionError
		if errors.As(err, &permErr) {
			log.Fatalf("add the %q permission to the token", permErr.RequiredScope)
		}
		log.Fatal(err)
	}
	log.Println("msgid:", sent.MsgID())
}
```

## Authentication

Bearer tokens only: every request sends `Authorization: Bearer <token>`.
API 3.0 has no query-string `id`/`key` authentication and this client does not
implement one.

A token names the interfaces it may be used on: `http`, `email` or `bulk`. It is
not a list of operations: a credential carrying `http` can use this whole API
and can send messages, because that is what a token is issued for. A call from
an interface the token does not carry raises `PermissionError`.

## Methods

All methods take `ctx context.Context` first and return a typed result plus
`error`.

| Method | Interface | Notes |
|---|---|---|
| `Ping(ctx)` | `http` | Liveness; `.OK` |
| `GetCredits(ctx)` | `http` | Balance in `.Credits` |
| `GetDetails(ctx)` | `http` | Account the credential belongs to |
| `GetSmsCost(ctx, dstAddr, message)` | `http` | Price without sending |
| `SendSms(ctx, dstAddr, message, opts)` | `http` | Extra fields via `opts Params` |
| `QuerySmsStatus(ctx, msgID)` | `http` | `.State`, `.Final()` |
| `LookupHLR(ctx, msisdn)` | `http` | Costs a credit |
| `LookupMNP(ctx, msisdn)` | `http` | Porting status |
| `GetNumberInfo(ctx, msisdn)` | `http` | Country / network |
| `CreateGroup(ctx, name)` | `http` | `.GroupID` |
| `DeleteGroup(ctx, groupID)` | `http` | |
| `AddGroupEntry(ctx, groupID, number, opts)` | `http` | |
| `DeleteGroupEntry(ctx, groupID, number)` | `http` | |
| `AddGroupEntries(ctx, groupID, numbers)` | `http` | `[]string`, joined one per line |
| `GetGroupNumbers(ctx, groupID)` | `http` | `.Numbers` |
| `GetGroupDetails(ctx, groupID)` | `http` | With per-number status |
| `ListGroups(ctx)` | `http` | `.Groups` |
| `DeleteGroupContents(ctx, groupID)` | `http` | Keeps the group |
| `GetGroupCost(ctx, groupID, message)` | `http` | Prices a send, not a group edit |
| `SendGroupSms(ctx, groupID, message, opts)` | `http` | |


`opts` is a `worldtext.Params` (`map[string]string`) that is passed through
unchanged, so optional fields such as `sendid`, `schedule`, `sender` and `udh`
work without this client needing to enumerate them. Pass `nil` for none.

Group ids arrive as either `"grpid"` or `"groupid"` depending on the route; the
models accept both.

## Numbers are strings

The API returns numbers as JSON strings: `"credits":"2950"`, `"msgid":"10001"`,
`"status":"0"`. The models keep them as strings, so nothing is lost to a float
round-trip, and expose `int64` accessors where a value is genuinely arithmetic:

```go
credits, _ := client.GetCredits(ctx)
if n, ok := credits.AsInt64(); ok {
	fmt.Println(n + 100)
}
```

Accessors: `Credits.AsInt64`, `SentMessage.MsgIDInt`, `Group.SizeInt`,
`GroupCost.EntryCountInt`, `AddEntriesResult.AddedInt`,
`DeleteResult.DeletedInt`. Each returns `(int64, bool)`; the `bool` is false
when the field is absent or not an integer.

Every result also keeps the decoded envelope in `.Raw` (`map[string]any`), so a
field this library does not model yet is still reachable.

## Errors

Every failure is one of three types, all matching a sentinel with `errors.Is`
and all reachable with `errors.As` as `*worldtext.Error`, which carries
`HTTPStatus`, `Code` and `Desc`:

| Type | Sentinel | When |
|---|---|---|
| `*AuthenticationError` | `ErrAuthentication` | HTTP 401 — token missing, malformed, unknown or revoked |
| `*PermissionError` | `ErrPermission` | HTTP 403 — token lacks the permission; `RequiredScope` says which |
| `*APIError` | `ErrAPI` | Everything else — 400 validation (code `1200`), insufficient funds, 5xx, transport failures |

```go
_, err := client.GetCredits(ctx)
switch {
case errors.Is(err, worldtext.ErrAuthentication):
	// 401 - the token is unknown or revoked
case errors.Is(err, worldtext.ErrPermission):
	var pe *worldtext.PermissionError
	errors.As(err, &pe)
	log.Printf("token needs the %q permission", pe.RequiredScope)
case errors.Is(err, worldtext.ErrAPI):
	var ae *worldtext.APIError
	errors.As(err, &ae)
	if ae.Retryable() { // status "2"/"3", or HTTP 5xx
		// schedule a retry
	}
}
```

Branch on the sentinel, error type, `Code` and `HTTPStatus` — never on message
text.

`worldtext.NewClient` itself fails with `*worldtext.ConfigError` for a missing
token or an unusable base URL.

### Retryability

`Retryable()` is true when the envelope's `status` is `"2"` or `"3"` (temporary)
or the HTTP status is 5xx. `"0"` is success and `"1"` is a permanent failure;
the flag is exposed as `.Status` (`worldtext.StatusSuccess`,
`StatusPermanent`, `StatusTemporary`, `StatusRetry`).

## Configuration

```go
client, err := worldtext.NewClient(baseURL, token)
client.HTTPClient = myInstrumentedClient // default: 30s timeout
client.Logf = func(format string, args ...any) { log.Printf(format, args...) }
```

`Client` is safe for concurrent use; create one and share it. Inject a
`context` with a deadline for per-call timeouts:

```go
ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
defer cancel()
```

## Token safety

The token is held in an unexported field and never appears in `String()`,
`fmt` output (`%v`, `%+v`, `%#v`), error messages, or log lines. Where a token
could conceivably reach a string it is shown as `<redacted:N chars>`.

`Client.Logf` receives one line per request — method and path only. Gateway
descriptions are scrubbed of the token before an error is returned, so errors
are always safe to log.

## Tests

Tests start the shared mock gateway (`../fixtures/mock_gateway.py`) as a
subprocess on port 0, read the printed port, assert against it and shut it
down. They need `python3` and no network; if `python3` is missing they skip.

```bash
docker run --rm --network=host \
  -v "$PWD/go:/src" -v "$PWD:/hl:ro" -w /src \
  -e WORLDTEXT_MOCK=/hl/fixtures/mock_gateway.py \
  golang:1.22 go test ./...
```

With Go installed on the host, the binary does not need the container path
workaround — run from this directory:

```bash
cd go && go test ./...
```

`WORLDTEXT_MOCK` overrides the fixture's path when the tests run from a
different directory.

16 tests cover typed parsing, every operation group, the 401/403/400 taxonomy
(including the exposed required scope), retryability, token redaction in
`String()`/errors/logs.