# World Text API helper libraries

Helper libraries for the World Text HTTP API, [spec version 3.0](../.tmp-rot/openapi/api/spec.yaml).
One directory per language, each with its own README, tests, and a consistent
design.

## Languages

| Directory | Language | Tests | Toolchain |
|---|---|---|---|
| [`python/`](python/README.md) | Python 3.8+ | 14 | stdlib only |
| [`php/`](php/README.md) | PHP 8+ | 29 | no Composer packages |
| [`node/`](node/README.md) | JavaScript / Node 18+ | 36 | zero dependencies |
| [`java/`](java/README.md) | Java 8+ | 26 | no external deps |
| [`go/`](go/README.md) | Go 1.22+ | 16 | stdlib only |
| [`csharp/`](csharp/README.md) | C# / .NET 8 | 38 | HttpClient + System.Text.Json |
| [`rest/`](rest/README.md) | any (cURL reference) | 123 | POSIX shell + curl |

**Tree-wide: 282 assertions, all passing.**

## Design, shared across every language

The libraries are deliberately consistent, so experience from one transfers:

1. **Bearer auth only.** A token and a base URL construct the client. No
   implementation accepts the old query-string `id`/`key` form — that is not
   part of API 3.0.
2. **The same 20 operations.** Named idiomatically (`get_credits`,
   `GetCredits`, `GetCreditsAsync`) but every language exposes all twenty.
3. **Numbers stay strings.** The API sends `"credits":"2950"`, not `2950`.
   Models keep the string and expose a numeric accessor, because parsing message
   ids through a float loses digits.
4. **Typed errors.** A base error type plus distinct authentication (401) and
   permission (403) types, the latter naming the scope that was missing. Callers
   branch on `httpStatus`/`errorCode`, never on message text.
5. **The token is never printed.** Not in logs, exceptions, `repr`/`toString`,
   or stack traces. Each suite asserts this with a distinct fake token.

## Testing

Every suite runs against one shared mock gateway, [`fixtures/mock_gateway.py`](fixtures/mock_gateway.py),
which implements the auth and scope rules of the real API. No network, no
credentials, no real account.

```bash
# Python
(cd python && python3 -m unittest discover -s tests)

# PHP
php php/tests/run.php

# Node
(cd node && node --test)

# Java
bash java/run-tests.sh

# REST / cURL reference
bash rest/test.sh

# Go (toolchain not installed locally)
docker run --rm --network=host -v "$PWD/go:/src" -v "$PWD:/hl:ro" -w /src \
  -e WORLDTEXT_MOCK=/hl/fixtures/mock_gateway.py golang:1.22 go test ./...

# C# (needs python3 for the fixture, so use the provided image)
(cd csharp && docker build --network=host -f Dockerfile.test -t worldtext-csharp-test .)
docker run --rm --network=host -v "$PWD:/src" -w /src/csharp \
  worldtext-csharp-test dotnet test
```

Note the C# mount: the repository root is mounted at `/src` and the working
directory is `src/csharp`, because the test project copies the shared fixture by
relative path. Mounting only `csharp/` makes that path escape the mount, the
fixture is silently not copied, and 18 tests fail for a reason that has nothing
to do with the code.

## Relationship to the older libraries

The published [helper libraries page](https://www.world-text.com/docs/libraries/)
still documents PHP, C# and Python classes that authenticate with
`account id + api key` in the query string. Those remain valid against the
gateway, which still accepts that form, but they are not part of API 3.0 and are
not what this repository contains. These libraries replace them.

## Licence

BSD 3-Clause; see [LICENSE](LICENSE). This matches the licence of the libraries
already published for the older authentication method.