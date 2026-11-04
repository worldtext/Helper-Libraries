#!/bin/sh
# test.sh - self-contained test suite for worldtext.sh.
#
# Starts the mock gateway on port 0, reads the port it prints, runs every
# function against it and asserts the results, then shuts the mock down and
# reports. No network access beyond 127.0.0.1, no credentials.
#
#   ./test.sh              run everything
#   ./test.sh -v           also show each response body
#
# Exits 0 if every test passed, 1 if any failed, 77 if the mock gateway could
# not be started (no python3), which is the usual "skipped" convention.
#
# The suite itself runs under /bin/sh as well as under its own default of
# bash/dash: it uses only POSIX constructs. Set WT_TEST_SHELL to test the
# library under a specific shell, e.g. "WT_TEST_SHELL=dash ./test.sh".

set -u

# Directory of this script, without calling dirname: some minimal
# environments (busybox ash, a stripped PATH) do not have it.
case $0 in
    */*) HERE=${0%/*} ;;
    *) HERE=. ;;
esac
HERE=$(CDPATH='' cd -- "$HERE" && pwd) || {
    printf 'FAIL: could not resolve the script directory\n' >&2
    exit 1
}
LIB="$HERE/worldtext.sh"
MOCK="${WT_MOCK_GATEWAY:-$HERE/../fixtures/mock_gateway.py}"
VERBOSE=0
[ "${1:-}" = "-v" ] && VERBOSE=1

PASS=0
FAIL=0
FAILED_NAMES=''

MOCK_PID=''
MOCK_PORT=''
TMPDIR_TEST=''

# --------------------------------------------------------------------------
# Reporting
# --------------------------------------------------------------------------

pass() {
    PASS=$((PASS + 1))
    printf '  ok   %s\n' "$1"
}

fail() {
    FAIL=$((FAIL + 1))
    FAILED_NAMES="$FAILED_NAMES
      - $1${2:+: $2}"
    printf '  FAIL %s%s\n' "$1" "${2:+: $2}"
}

# eq EXPECTED ACTUAL NAME
eq() {
    if [ "$1" = "$2" ]; then
        pass "$3"
    else
        fail "$3" "expected [$1], got [$2]"
    fi
}

# ne UNEXPECTED ACTUAL NAME
ne() {
    if [ "$1" != "$2" ]; then
        pass "$3"
    else
        fail "$3" "expected anything but [$1]"
    fi
}

# contains HAYSTACK NEEDLE NAME
contains() {
    case $1 in
        *"$2"*) pass "$3" ;;
        *) fail "$3" "[$1] does not contain [$2]" ;;
    esac
}

# lacks HAYSTACK NEEDLE NAME
lacks() {
    case $1 in
        *"$2"*) fail "$3" "output leaked [$2]" ;;
        *) pass "$3" ;;
    esac
}

show() {
    [ "$VERBOSE" = 1 ] && printf '       %s\n' "$1"
    return 0
}

# --------------------------------------------------------------------------
# Mock gateway lifecycle
# --------------------------------------------------------------------------

cleanup() {
    if [ -n "$MOCK_PID" ]; then
        kill "$MOCK_PID" 2>/dev/null
        wait "$MOCK_PID" 2>/dev/null
        MOCK_PID=''
    fi
    if [ -n "$TMPDIR_TEST" ] && [ -d "$TMPDIR_TEST" ]; then
        rm -rf "$TMPDIR_TEST"
    fi
    # The last-failure file belongs to this shell's pid.
    rm -f "${TMPDIR:-/tmp}/worldtext.state.$$" 2>/dev/null
}

start_mock() {
    if [ ! -f "$MOCK" ]; then
        printf 'SKIP: mock gateway not found at %s\n' "$MOCK" >&2
        exit 77
    fi
    if ! command -v python3 >/dev/null 2>&1; then
        printf 'SKIP: python3 is not installed, so the mock gateway cannot run\n' >&2
        exit 77
    fi

    TMPDIR_TEST=$(mktemp -d "${TMPDIR:-/tmp}/worldtext-test.XXXXXXXX") || {
        printf 'FAIL: could not create a temporary directory\n' >&2
        exit 1
    }
    MOCK_OUT="$TMPDIR_TEST/mock.out"

    # Port 0: the kernel picks a free port, so parallel runs cannot collide.
    python3 "$MOCK" 0 >"$MOCK_OUT" 2>"$TMPDIR_TEST/mock.err" &
    MOCK_PID=$!

    # The mock prints "PORT <n>" once it is listening. Poll for it, because
    # reading the pipe directly would need bash-only facilities.
    i=0
    while [ "$i" -lt 100 ]; do
        if [ -s "$MOCK_OUT" ]; then
            line=$(head -n 1 "$MOCK_OUT")
            case $line in
                "PORT "*)
                    MOCK_PORT=${line#PORT }
                    break
                    ;;
            esac
        fi
        if ! kill -0 "$MOCK_PID" 2>/dev/null; then
            break
        fi
        i=$((i + 1))
        sleep 0.1 2>/dev/null || sleep 1
    done

    if [ -z "$MOCK_PORT" ]; then
        printf 'FAIL: the mock gateway did not report a port\n' >&2
        cat "$TMPDIR_TEST/mock.err" >&2 2>/dev/null
        cleanup
        exit 1
    fi
    printf 'mock gateway: pid %s, http://127.0.0.1:%s\n' "$MOCK_PID" "$MOCK_PORT"
}

# --------------------------------------------------------------------------
# Library under test
# --------------------------------------------------------------------------

if [ ! -f "$LIB" ]; then
    printf 'FAIL: %s not found\n' "$LIB" >&2
    exit 1
fi

# shellcheck disable=SC1090
. "$LIB"

# Run the library under a chosen shell when asked. Without this the functions
# run in this script's shell, which is what a caller does too.
if [ -n "${WT_TEST_SHELL:-}" ] && [ -z "${WT_TEST_REEXEC:-}" ]; then
    printf 'library shell: %s (%s)\n' "$WT_TEST_SHELL" "$(command -v "$WT_TEST_SHELL" || echo 'not found')"
    command -v "$WT_TEST_SHELL" >/dev/null 2>&1 || {
        printf 'SKIP: WT_TEST_SHELL=%s is not installed\n' "$WT_TEST_SHELL" >&2
        exit 77
    }
    printf 'suite shell:   %s\n' "$(command -v "$0" >/dev/null 2>&1 && echo "$0" || echo 'sh')"
    # Re-exec the whole suite under the requested shell so every assertion
    # exercises the library in that shell. WT_TEST_REEXEC stops the child from
    # doing the same thing again, which would loop forever.
    WT_TEST_REEXEC=1
    export WT_TEST_REEXEC
    exec "$WT_TEST_SHELL" "$0" "$@"
fi

# --------------------------------------------------------------------------
# Tests
# --------------------------------------------------------------------------

test_success_and_typed_values() {
    printf '\nSuccessful calls\n'

    out=$(wt_get_credits); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_get_credits exits 0'
    eq '{"status": "0", "credits": "2950"}' "$out" 'wt_get_credits returns the full envelope'
    contains "$out" '"credits": "2950"' 'credits is a JSON string, not a number'
    # A number would come back unquoted; prove the quote is really there.
    lacks "$out" '"credits": 2950' 'credits is not a bare JSON number'

    out=$(wt_ping); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_ping exits 0'
    eq '{"status": "0"}' "$out" 'wt_ping returns status 0'

    out=$(wt_get_host); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_get_host exits 0'
    contains "$out" '"hostname": "sms1.example"' 'wt_get_host returns the hostname'
}

test_sms() {
    printf '\nSMS operations\n'

    out=$(wt_get_sms_cost 447989000001 'Hello there'); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_get_sms_cost exits 0'
    contains "$out" '"credits": "1.0"' 'wt_get_sms_cost returns a quoted cost'

    out=$(wt_send_sms 447989000001 'Hello from sh'); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_send_sms exits 0'
    contains "$out" '"msgid": "10001"' 'wt_send_sms returns a quoted msgid'
    contains "$out" '"dstaddr": "447989000001"' 'wt_send_sms echoes the destination'

    # A message with characters that break naive query building or shell
    # quoting, and a parameter carrying "%" (which sed-based encoders mangle).
    out=$(wt_send_sms 447989000001 "it's 100% \"done\" & <ok> $(printf 'newline\nhere')" \
        clientref=ref+1 sendid='a&b=c'); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_send_sms survives %, &, =, quotes, spaces and a newline'
    contains "$out" '"dstaddr": "447989000001"' 'wt_send_sms still addressed correctly after encoding'

    out=$(wt_query_sms_status 10001); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_query_sms_status exits 0'
    contains "$out" '"state": "5"' 'wt_query_sms_status returns a quoted state'

    # Optional parameters reach the wire: the mock echoes dstaddr back.
    out=$(wt_send_sms 447989000002 'x' srcaddr=447989000099); rc=$?
    eq 0 "$rc" 'wt_send_sms accepts extra key=value options'
}

test_lookups() {
    printf '\nLookup operations\n'

    out=$(wt_lookup_hlr 447989000001); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_lookup_hlr exits 0'
    contains "$out" '"region": "UK"' 'wt_lookup_hlr returns the region'

    out=$(wt_lookup_mnp 447989000001); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_lookup_mnp exits 0'
    contains "$out" '"mnpportstatus": "ported"' 'wt_lookup_mnp returns the port status'

    out=$(wt_get_number_info 447989000001); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_get_number_info exits 0'
    contains "$out" '"network": "O2-UK"' 'wt_get_number_info returns the network'
}

test_groups() {
    printf '\nGroup operations\n'

    out=$(wt_create_group test-group); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_create_group exits 0'
    contains "$out" '"groupid": "4242"' 'wt_create_group returns groupid (this route uses groupid)'
    contains "$out" '"grpid": "4242"' 'the mock also reports grpid for the same group'

    out=$(wt_add_group_entry 4242 447989000001 name=Harry); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_add_group_entry exits 0'
    contains "$out" '"added": "1"' 'wt_add_group_entry reports one added'

    # Both spellings of the id are accepted, so a caller can pass what
    # wt_create_group returned without renaming it.
    out=$(wt_add_group_entry grpid=4242 447989000002); rc=$?
    eq 0 "$rc" 'wt_add_group_entry accepts grpid= prefix'
    out=$(wt_add_group_entry groupid=4242 447989000003); rc=$?
    eq 0 "$rc" 'wt_add_group_entry accepts groupid= prefix'

    out=$(wt_add_group_entries 4242 447989000004 447989000005); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_add_group_entries accepts several numbers'
    contains "$out" '"added": "2"' 'wt_add_group_entries reports two added'

    out=$(wt_add_group_entries 4242 '447989000006:Ann,447989000007:Bob'); rc=$?
    eq 0 "$rc" 'wt_add_group_entries accepts an explicit members string'

    out=$(wt_get_group_numbers 4242); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_get_group_numbers exits 0'
    contains "$out" '447989000001' 'wt_get_group_numbers lists a number'

    out=$(wt_get_group_details 4242); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_get_group_details exits 0'
    contains "$out" '"number": "447989000001"' 'wt_get_group_details lists a number'

    out=$(wt_list_groups); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_list_groups exits 0'
    contains "$out" '"name": "test-group"' 'wt_list_groups finds the group'

    out=$(wt_get_group_cost 4242); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_get_group_cost exits 0 with an sms-capable token'
    contains "$out" '"credits": "2.0"' 'wt_get_group_cost returns the cost'

    out=$(wt_send_group_sms 4242 'group hello'); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_send_group_sms exits 0'
    contains "$out" '"msgid": "10002"' 'wt_send_group_sms returns a msgid'

    out=$(wt_delete_group_entry 4242 447989000001); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_delete_group_entry exits 0'

    out=$(wt_delete_group_contents 4242); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_delete_group_contents exits 0'
    contains "$out" '"deleted": "2"' 'wt_delete_group_contents reports the count'

    out=$(wt_delete_group 4242); rc=$?
    show "$out"
    eq 0 "$rc" 'wt_delete_group exits 0'
    eq '{"status": "0"}' "$out" 'wt_delete_group returns status 0'
}

test_authentication() {
    printf '\n401 - unknown token\n'

    saved=$WT_TOKEN
    WT_TOKEN='not-a-real-token'
    out=$(wt_get_credits 2>/dev/null); rc=$?
    err=$(wt_get_credits 2>&1 >/dev/null)
    WT_TOKEN=$saved
    show "$err"
    eq 3 "$rc" 'a bad token exits 3'
    eq authentication "$(wt_last_error_kind)" 'the error kind is authentication'
    eq 401 "$(wt_last_status)" 'WT_ERROR_STATUS is 401'
    eq 1000 "$(wt_last_code)" 'WT_ERROR_CODE is 1000'
    contains "$err" 'authentication failed' 'the message names authentication'

    # The token must not be in the diagnostic even though it failed.
    lacks "$err" 'not-a-real-token' 'the rejected token is not echoed'

    # Same assertions on a plain (non-subshell) call, where the WT_ERROR_*
    # variables are set directly in the caller's shell.
    sed_saved=$WT_TOKEN
    WT_TOKEN='not-a-real-token'
    wt_get_credits >/dev/null 2>&1
    rc=$?
    eq 3 "$rc" 'a bad token exits 3 from a plain call'
    eq authentication "$WT_ERROR_KIND" 'WT_ERROR_KIND says authentication'
    eq 401 "$WT_ERROR_STATUS" 'WT_ERROR_STATUS is 401'
    eq 1000 "$WT_ERROR_CODE" 'WT_ERROR_CODE is 1000'
    WT_TOKEN=$sed_saved

    # Every operation authenticates, not just the one above.
    saved=$WT_TOKEN
    WT_TOKEN='nope'
    wt_ping >/dev/null 2>&1
    eq 3 "$?" 'wt_ping rejects a bad token the same way'
    wt_send_sms 447989000001 hi >/dev/null 2>&1
    eq 3 "$?" 'wt_send_sms rejects a bad token the same way'
    WT_TOKEN=$saved

    # Missing token is a usage error caught before any request is made.
    saved=$WT_TOKEN
    WT_TOKEN=''
    out=$(wt_ping 2>&1); rc=$?
    WT_TOKEN=$saved
    eq 2 "$rc" 'an empty token exits 2 (usage) without a request'
    contains "$out" 'WT_TOKEN is not set' 'the message says what is missing'
}

test_permissions() {
    printf '\n403 - wrong interface\n'

    saved=$WT_TOKEN
    WT_TOKEN='test-email'
    err=$(wt_get_credits 2>&1 >/dev/null); rc=$?
    WT_TOKEN=$saved
    show "$err"
    eq 4 "$rc" 'a wrong-scope call exits 4'
    eq permission "$(wt_last_error_kind)" 'the error kind is permission'
    eq 403 "$(wt_last_status)" 'WT_ERROR_STATUS is 403'
    eq http "$(wt_last_scope)" 'the required scope is exposed'
    contains "$err" "'http'" 'the message names the missing interface'

    # The scope is readable from a subshell too, which is how most callers
    # capture output.
    saved=$WT_TOKEN
    WT_TOKEN='test-email'
    captured=$(wt_get_credits 2>/dev/null)
    rc=$?
    eq 4 "$rc" 'a captured wrong-scope call exits 4'
    eq http "$(wt_last_scope)" 'wt_last_scope works after a $(...) capture'
    eq permission "$(wt_last_error_kind)" 'wt_last_error_kind works after a $(...) capture'
    WT_TOKEN=$saved

    # Scopes name interfaces, so an email-only credential is refused on every
    # operation, reads included - there is no per-operation boundary left.
    saved=$WT_TOKEN
    WT_TOKEN='test-email'
    wt_get_group_cost 4242 >/dev/null 2>&1
    eq 4 "$?" 'get_group_cost refuses an email-only credential'
    eq http "$WT_REQUIRED_SCOPE" 'get_group_cost reports that it needs http'
    wt_send_group_sms 4242 hi >/dev/null 2>&1
    eq 4 "$?" 'send_group_sms refuses an email-only credential'
    eq http "$WT_REQUIRED_SCOPE" 'send_group_sms reports that it needs http'
    wt_list_groups >/dev/null 2>&1
    eq 4 "$?" 'wt_list_groups refuses an email-only credential'
    eq http "$WT_REQUIRED_SCOPE" 'wt_list_groups reports that it needs http'
    wt_lookup_hlr 447989000001 >/dev/null 2>&1
    eq 4 "$?" 'an email-only credential cannot look up a number'
    eq http "$WT_REQUIRED_SCOPE" 'that refusal names the http interface'
    # A credential with no interfaces at all is refused likewise.
    WT_TOKEN='test-none'
    wt_get_credits >/dev/null 2>&1
    eq 4 "$?" 'a credential with no interfaces is refused'

    # The converse: an http credential may use every operation.
    WT_TOKEN='test-http'
    out=$(wt_get_group_cost 4242 2>/dev/null); rc=$?
    eq 0 "$rc" 'get_group_cost works with an http credential'
    contains "$out" '"credits"' 'get_group_cost returned a cost'
    wt_lookup_hlr 447989000001 >/dev/null 2>&1
    eq 0 "$?" 'hlr lookup works with an http credential'
    wt_get_credits >/dev/null 2>&1
    eq 0 "$?" 'credits work with the same credential'
    WT_TOKEN=$saved
}

test_bad_arguments() {
    printf '\n400 - missing or bad arguments\n'

    out=$(wt_get_sms_cost '' 2>/dev/null); rc=$?
    eq 5 "$rc" 'an empty dstaddr exits 5 (api)'
    eq api "$(wt_last_error_kind)" 'the error kind is api'
    eq 400 "$(wt_last_status)" 'WT_ERROR_STATUS is 400'
    eq 1200 "$(wt_last_code)" 'WT_ERROR_CODE is 1200'
    eq 0 "$(wt_last_retryable)" 'a 1200 failure is not retryable'
    eq 'Incorrect arguments specified' "$(wt_last_desc)" 'the description reaches the caller'

    # Missing locally is caught without a round trip, with a usage message.
    out=$(wt_get_sms_cost 2>&1); rc=$?
    eq 2 "$rc" 'a missing argument exits 2 (usage)'
    contains "$out" 'usage: wt_get_sms_cost' 'the usage message names the function'

    out=$(wt_add_group_entry 4242 2>&1); rc=$?
    eq 2 "$rc" 'wt_add_group_entry without a number exits 2'
    out=$(wt_send_sms 447989000001 2>&1); rc=$?
    eq 2 "$rc" 'wt_send_sms without a message exits 2'
    out=$(wt_send_sms 447989000001 hi 'not-a-pair' 2>&1); rc=$?
    eq 2 "$rc" 'a malformed optional parameter exits 2'
    contains "$out" 'key=value' 'the message says what was expected'

    out=$(wt_create_group '' 2>/dev/null); rc=$?
    eq 5 "$rc" 'wt_create_group with an empty name exits 5'
    eq 1200 "$(wt_last_code)" 'an empty name is a 1200 from the gateway'
}

test_token_redaction() {
    printf '\nToken redaction\n'
    secret='wt_live_supersecretvalue_that_must_never_leak'

    saved=$WT_TOKEN
    WT_TOKEN=$secret

    out=$(wt_describe)
    eq "worldtext(base_url=$WT_BASE_URL, token=<redacted:45 chars>, timeout=30)" "$out" \
        'wt_describe shows the redacted form'
    lacks "$out" "$secret" 'wt_describe does not contain the token'

    # A failing call against a bad token: message, stdout and the variables.
    err=$(wt_get_credits 2>&1 >/dev/null)
    lacks "$err" "$secret" 'the error message does not contain the token'
    contains "$err" 'authentication failed' 'the error message is still useful'

    out=$(wt_ping 2>&1)
    lacks "$out" "$secret" 'wt_ping diagnostics do not contain the token'

    out=$(wt_send_sms 447989000001 hi 2>&1)
    lacks "$out" "$secret" 'wt_send_sms diagnostics do not contain the token'

    out=$(wt_get_sms_cost 2>&1)
    lacks "$out" "$secret" 'usage diagnostics do not contain the token'

    # Transport failures print the base URL, never the headers.
    saved_base=$WT_BASE_URL
    WT_BASE_URL='http://127.0.0.1:1'
    out=$(wt_ping 2>&1)
    lacks "$out" "$secret" 'a transport failure does not contain the token'
    WT_BASE_URL=$saved_base

    # The token must not survive on disk once a call returns.
    leftovers=$(ls "$TMPDIR_TEST"/worldtext.* "$TMPDIR"/worldtext.* 2>/dev/null | grep -v '\.state\.' | wc -l)
    eq 0 "$(printf '%s' "$leftovers" | tr -d ' ')" 'no scratch files are left behind'

    # Nothing that we create may contain the token either.
    found=$(grep -rlF "$secret" "$TMPDIR_TEST" 2>/dev/null | wc -l)
    eq 0 "$(printf '%s' "$found" | tr -d ' ')" 'no file under the test directory contains the token'

    WT_TOKEN=$saved
}

test_redaction_in_echoed_response() {
    printf '\nToken redaction in a response the gateway echoes\n'

    # Belt and braces: the mock does not echo headers, so stand in a body that
    # does and make sure the scrubber strips the token from it.
    secret='wt_live_echoed_elsewhere_0123456789_extra'
    saved=$WT_TOKEN
    WT_TOKEN=$secret
    body="{\"status\":\"0\",\"echo\":\"Authorization: Bearer $secret\"}"
    out=$(_wt_scrub "$body" "$(_wt_redact)")
    WT_TOKEN=$saved
    show "$out"
    lacks "$out" "$secret" 'a token echoed in a response body is redacted'
    contains "$out" '<redacted:41 chars>' 'the redaction marker replaces it'

    # Same, but percent-encoded as a gateway might echo a header.
    saved=$WT_TOKEN
    WT_TOKEN=$secret
    body="{\"status\":\"0\",\"echo\":\"Bearer%20${secret}\"}"
    out=$(_wt_scrub "$body" "$(_wt_redact)")
    WT_TOKEN=$saved
    lacks "$out" "$secret" 'a percent-encoded token is redacted too'
}

test_transport() {
    printf '\nTransport and environment\n'

    saved_base=$WT_BASE_URL
    saved=$WT_TOKEN
    WT_TOKEN='test-all'
    WT_BASE_URL='http://127.0.0.1:1'
    out=$(wt_ping 2>&1); rc=$?
    eq 5 "$rc" 'an unreachable gateway exits 5'
    eq transport "$(wt_last_error_kind)" 'the error kind is transport'
    WT_BASE_URL=$saved_base
    show "$out"
    contains "$out" 'could not connect' 'the message says it could not connect'

    # A trailing slash on the base URL must not produce a doubled path.
    saved_base=$WT_BASE_URL
    WT_BASE_URL="$saved_base/"
    out=$(wt_get_credits); rc=$?
    WT_BASE_URL=$saved_base
    eq 0 "$rc" 'a trailing slash on WT_BASE_URL is harmless'
    contains "$out" '"credits": "2950"' 'the trailing-slash call still parses'

    out=$(wt_describe)
    contains "$out" "$WT_BASE_URL" 'wt_describe reports the base URL'
    WT_TOKEN=$saved
}

test_clean_exit() {
    printf '\nLifecycle\n'

    # Run a failing call in a separate shell. The only thing it may leave
    # behind is the last-error record the caller reads back; every curl
    # scratch file, and above all the one holding the token, must be gone.
    tdir=${TMPDIR:-/tmp}
    ( WT_BASE_URL="$WT_BASE_URL" WT_TOKEN='not-a-real-token'; wt_get_credits ) >/dev/null 2>&1
    rc=$?
    eq 3 "$rc" 'a failing call in a nested shell exits 3'

    # mktemp names are worldtext.XXXXXXXX; the state file is worldtext.state.PID.
    curl_files=$(ls "$tdir"/worldtext.???????? 2>/dev/null | wc -l)
    eq 0 "$(printf '%s' "$curl_files" | tr -d ' ')" 'no curl scratch files remain after a failure'

    # Nothing that is left may contain a token.
    leftovers=$(ls "$tdir"/worldtext.* 2>/dev/null)
    leaked=0
    for f in $leftovers; do
        if grep -qF 'not-a-real-token' "$f" 2>/dev/null; then
            leaked=$((leaked + 1))
        fi
    done
    eq 0 "$leaked" 'no leftover file holds the token'

    # A successful call clears the last-error record entirely.
    out=$(wt_get_credits); rc=$?
    eq 0 "$rc" 'a following successful call exits 0'
    eq '' "$(wt_last_error_kind)" 'success clears the last-error kind'
    eq '' "$(wt_last_scope)" 'success clears the last-error scope'
}

test_query_not_used() {
    printf '\nAuthentication is bearer-only\n'

    # API 3.0 has no query-string id/key auth. A request carrying them and no
    # bearer header must be refused, and the helper never sends them at all.
    out=$(curl -s -S -o /dev/null -w '%{http_code}' \
        "$WT_BASE_URL/admin/credits?id=12345&key=deadbeef" 2>/dev/null)
    eq 401 "$out" 'id/key in the query string is refused (no legacy auth)'

    # Confirm what the helper actually sends: no id= or key= parameter.
    out=$(wt_get_credits)
    lacks "$out" 'id=12345' 'the helper sends no id parameter'
}

# --------------------------------------------------------------------------
# Main
# --------------------------------------------------------------------------

command -v curl >/dev/null 2>&1 || {
    printf 'SKIP: curl is not installed\n' >&2
    exit 77
}

start_mock
trap cleanup EXIT HUP INT TERM

WT_BASE_URL="http://127.0.0.1:$MOCK_PORT"
WT_TOKEN='test-all'
export WT_BASE_URL WT_TOKEN

printf 'World Text REST helper tests\n'
printf 'library: %s\n' "$LIB"
printf 'shell:   %s\n' "${BASH_VERSION:+bash $BASH_VERSION}${BASH_VERSION:-sh}"

test_success_and_typed_values
test_sms
test_lookups
test_groups
test_authentication
test_permissions
test_bad_arguments
test_token_redaction
test_redaction_in_echoed_response
test_transport
test_clean_exit
test_query_not_used

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
if [ "$FAIL" -ne 0 ]; then
    printf 'failed tests:%s\n' "$FAILED_NAMES"
    exit 1
fi
exit 0