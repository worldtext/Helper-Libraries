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

A token carries one or more of four independent permissions: `sms`, `groups`,
`admin`, `hlr`. Calling outside them throws `PermissionDeniedException`, whose
`RequiredScope` names what was missing.

## Methods

| Method | Permission | Notes |
|---|---|---|
| `PingAsync()` | `admin` | Liveness |
| `GetCreditsAsync()` | `admin` | Balance |
| `GetHostAsync()` | `admin` | Serving host |
| `GetSmsCostAsync(dstaddr, message)` | `sms` | Price without sending |
| `SendSmsAsync(dstaddr, message, …)` | `sms` | |
| `QuerySmsStatusAsync(msgid)` | `sms` | Delivery state |
| `LookupHlrAsync(msisdn)` | `hlr` | Costs a credit |
| `LookupMnpAsync(msisdn)` | `hlr` | Porting status |
| `GetNumberInfoAsync(msisdn)` | `hlr` | Country / network |
| `CreateGroupAsync(name)` | `groups` | Returns `Grpid` |
| `DeleteGroupAsync(grpid)` | `groups` | |
| `AddGroupEntryAsync(grpid, number)` | `groups` | |
| `DeleteGroupEntryAsync(grpid, number)` | `groups` | |
| `AddGroupEntriesAsync(grpid, numbers)` | `groups` | `IEnumerable<string>` |
| `GetGroupNumbersAsync(grpid)` | `groups` | |
| `GetGroupDetailsAsync(grpid)` | `groups` | With per-number status |
| `ListGroupsAsync()` | `groups` | |
| `DeleteGroupContentsAsync(grpid)` | `groups` | Keeps the group |
| `GetGroupCostAsync(grpid, message)` | **`sms`** | Prices a send, not a group edit |
| `SendGroupSmsAsync(grpid, message, …)` | **`sms`** | |

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