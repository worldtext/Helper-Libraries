# WorldText.Api (.NET)

C# client for the World Text HTTP API (spec version 3.0), using bearer
authentication. Targets .NET 8; `System.Text.Json` and `HttpClient` only.

## Install

Reference the project, or copy `src/WorldText.Api` into your solution.

```xml
<ProjectReference Include="path/to/WorldText.Api.csproj" />
```

## Quickstart

```csharp
using WorldText.Api;

using var client = new WorldTextClient("wt_live_xxxxxxxx");

var credits = await client.GetCreditsAsync();
Console.WriteLine(credits.Credits);          // "2950" - a string, as the API sends it

await client.SendSmsAsync("447989000001", "Hello from C#");
```

The client takes an optional `HttpClient`, so you can supply your own
`IHttpClientFactory` pipeline, and an optional `ILogger`.

## Authentication

Bearer tokens only. Create one in the admin console or customer portal, ticking
the permissions it needs. There is no query-string `id`/`key` support — that
form is not part of API 3.0.

A token names the interfaces it may be used on: `http`, `email` or `bulk`. It is
not a list of operations: a credential carrying `http` can use this whole API
and can send messages, because that is what a token is issued for. A call from
an interface the token does not carry raises `PermissionDeniedException`.

## Methods

| Method | Interface | Notes |
|---|---|---|
| `PingAsync()` | `http` | Liveness |
| `GetCreditsAsync()` | `http` | Balance |
| `GetHostAsync()` | `http` | Serving host |
| `GetSmsCostAsync(dstaddr, message)` | `http` | Price without sending |
| `SendSmsAsync(dstaddr, message, …)` | `http` | |
| `QuerySmsStatusAsync(msgid)` | `http` | Delivery state |
| `LookupHlrAsync(msisdn)` | `http` | Costs a credit |
| `LookupMnpAsync(msisdn)` | `http` | Porting status |
| `GetNumberInfoAsync(msisdn)` | `http` | Country / network |
| `CreateGroupAsync(name)` | `http` | Returns `Grpid` |
| `DeleteGroupAsync(grpid)` | `http` | |
| `AddGroupEntryAsync(grpid, number)` | `http` | |
| `DeleteGroupEntryAsync(grpid, number)` | `http` | |
| `AddGroupEntriesAsync(grpid, numbers)` | `http` | `IEnumerable<string>` |
| `GetGroupNumbersAsync(grpid)` | `http` | |
| `GetGroupDetailsAsync(grpid)` | `http` | With per-number status |
| `ListGroupsAsync()` | `http` | |
| `DeleteGroupContentsAsync(grpid)` | `http` | Keeps the group |
| `GetGroupCostAsync(grpid, message)` | `http` | Prices a send, not a group edit |
| `SendGroupSmsAsync(grpid, message, …)` | `http` | |

## Numbers are strings

The API returns numbers as JSON strings: `"credits":"2950"`, `"msgid":"10001"`.
The models keep them as strings so nothing is lost, and expose `…AsDecimal`
where a value is genuinely arithmetic.

## Errors

```csharp
try
{
    await client.GetCreditsAsync();
}
catch (AuthenticationException) { /* 401 — token unknown, revoked, malformed */ }
catch (PermissionDeniedException e) { /* 403 — e.RequiredScope tells you which */ }
catch (ApiException e) { /* other — e.ErrorCode is "1200" etc. */ }
```

All derive from `WorldTextException` and carry `HttpStatus`, `ErrorCode` and
`Desc`. `IsRetryable` is true for status `"2"`/`"3"` and HTTP 5xx.

## Token safety

The token is wrapped in a `WorldTextToken` type whose `ToString()` returns
`<redacted:N chars>`. It is never written to logs, exception messages, or
`ToString()` output.

## Tests

The .NET SDK image has no `python3`, and the test fixture needs it, so use the
provided image:

```bash
cd helper-libraries/csharp
docker build --network=host -f Dockerfile.test -t worldtext-csharp-test .
cd .. && docker run --rm --network=host -v "$PWD:/src" -w /src/csharp \
  worldtext-csharp-test dotnet test
```

**38 tests pass.** Note the mount: the repo root is mounted at `/src` and the
working directory is `src/csharp`, because the test project copies the shared
fixture by a relative path. Mounting only `csharp/` at `/src` makes that path
escape the mount and the fixture is silently not copied.

For a live smoke test against production, set `WORLDTEXT_TOKEN` and run the
example in `README` — never point the unit suite at a real account.