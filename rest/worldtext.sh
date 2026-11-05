# worldtext.sh - POSIX shell helper for the World Text HTTP API (spec 3.0).
#
# Source this file; it defines functions and does not make requests by itself.
#
#   . ./worldtext.sh
#   WT_TOKEN='wt_live_...'                 # required; export it or set it first
#   WT_BASE_URL=https://sms.world-text.com/v2.0   # optional, this is the default
#
#   wt_ping
#   wt_get_credits                         # prints {"status":"0","credits":"2950"}
#   wt_send_sms 447989000001 'Hello'
#
# Environment
#   WT_TOKEN     required. Bearer token. Never printed, never put on a command
#                line, never written to any file but a private curl config
#                that is removed as soon as the request finishes.
#   WT_BASE_URL  optional. Default https://sms.world-text.com/v2.0
#   WT_TIMEOUT   optional. Connect and total timeout in seconds, default 30.
#   WT_INSECURE  optional. Set to 1 to accept any TLS certificate. Testing only.
#
# Failure model, mirroring the Python reference client:
#   success (2xx, envelope status "0") -> the JSON envelope on stdout, exit 0
#   401 authenticates nothing           -> stderr, exit 3, WT_ERROR_KIND=authentication
#   403 token lacks the permission      -> stderr, exit 4, WT_ERROR_KIND=permission,
#                                          WT_REQUIRED_SCOPE=<what the route needs>
#   any other error envelope            -> stderr, exit 5, WT_ERROR_KIND=api
#   5xx or envelope status "2"/"3"      -> as above, plus WT_ERROR_RETRYABLE=1
#   bad usage or missing environment    -> stderr, exit 2, WT_ERROR_KIND=usage
#   the gateway could not be reached    -> stderr, exit 5, WT_ERROR_KIND=transport
#
# After a plain call the WT_ERROR_* and WT_REQUIRED_SCOPE variables describe the
# failure. A call whose output is captured ("x=$(wt_get_credits)") runs in a
# subshell and cannot set variables in yours, so read the same facts with:
#
#   wt_last_error_kind   authentication | permission | api | transport | usage
#   wt_last_status       HTTP status,      e.g. 403
#   wt_last_code         gateway error,    e.g. 1000 (Auth), 1200 (arguments)
#   wt_last_scope        required permission on a 403, e.g. admin
#   wt_last_desc         the gateway's own description
#   wt_last_retryable    1 when status "2"/"3" or an HTTP 5xx
#
# The functions are deliberately written for POSIX sh - no arrays, no
# [[ ]], no ${var//} - so they run under dash, the BSD /bin/sh, busybox ash and
# bash. They never set or change shell options, so sourcing this file into an
# existing script cannot trip a caller's "set -u" or "set -e".

# Initialise every variable the functions read, with := so this is safe under
# "set -u" whether or not the caller defined them.
: "${WT_TOKEN:=}"
: "${WT_BASE_URL:=}"
: "${WT_TIMEOUT:=}"
: "${WT_INSECURE:=}"
: "${TMPDIR:=/tmp}"
: "${WT_ERROR_KIND:=}"
: "${WT_ERROR_CODE:=}"
: "${WT_ERROR_STATUS:=}"
: "${WT_ERROR_DESC:=}"
: "${WT_REQUIRED_SCOPE:=}"
: "${WT_ERROR_RETRYABLE:=0}"

WT_DEFAULT_BASE_URL='https://sms.world-text.com/v2.0'
WT_DEFAULT_TIMEOUT=30

WT_EXIT_USAGE=2
WT_EXIT_AUTH=3
WT_EXIT_PERMISSION=4
WT_EXIT_API=5

# Scratch files for the request in flight; the EXIT trap removes them.
WT_TMP_CURLRC=''
WT_TMP_HEADERS=''
WT_TMP_BODY=''
WT_TMP_ERR=''
WT_TMP_CODE=''

# ==========================================================================
# Internal helpers
# ==========================================================================

# Log-safe form of the token. Never the token itself: identical to the Python
# client's redact(), "<redacted:N chars>".
_wt_redact() {
    if [ -n "$WT_TOKEN" ]; then
        printf '<redacted:%d chars>' "${#WT_TOKEN}"
    else
        printf '<none>'
    fi
}

# All diagnostics go through here. Callers pass fixed strings, never anything
# assembled from the token.
_wt_err() {
    printf 'worldtext: %s\n' "$*" >&2
}

# Error state is kept in the caller's shell. Command substitutions run the
# functions in a subshell, so "x=$(wt_get_credits)" would otherwise lose it;
# every failure also writes it here, and the caller reads the file back. The
# file holds only codes and the gateway's description - never the token.
_wt_state_file() {
    printf '%s/worldtext.state.%d' "${TMPDIR:-/tmp}" "$$"
}

_wt_save_state() {
    # Written by whatever shell failed and read later by the caller's shell, so
    # it cannot be removed on the failing side: the caller has not read it yet.
    # Instead the test suite and callers that care can remove it with
    # _wt_clear_state; test.sh does, and checks that nothing is left behind.
    {
        printf 'WT_ERROR_KIND=%s\n' "$WT_ERROR_KIND"
        printf 'WT_ERROR_STATUS=%s\n' "$WT_ERROR_STATUS"
        printf 'WT_ERROR_CODE=%s\n' "$WT_ERROR_CODE"
        printf 'WT_ERROR_DESC=%s\n' "$WT_ERROR_DESC"
        printf 'WT_REQUIRED_SCOPE=%s\n' "$WT_REQUIRED_SCOPE"
        printf 'WT_ERROR_RETRYABLE=%s\n' "$WT_ERROR_RETRYABLE"
    } >"$(_wt_state_file)" 2>/dev/null
}

_wt_load_state() {
    wt_state=$(_wt_state_file)
    # Whatever this shell recorded earlier is stale until proven otherwise: a
    # success that happened in a subshell cleared the record without being able
    # to touch these variables.
    WT_ERROR_KIND=''
    WT_ERROR_STATUS=''
    WT_ERROR_CODE=''
    WT_ERROR_DESC=''
    WT_REQUIRED_SCOPE=''
    WT_ERROR_RETRYABLE=0
    [ -f "$wt_state" ] || return 1
    while IFS= read -r wt_line || [ -n "$wt_line" ]; do
        case $wt_line in
            WT_ERROR_KIND=*) WT_ERROR_KIND=${wt_line#*=} ;;
            WT_ERROR_STATUS=*) WT_ERROR_STATUS=${wt_line#*=} ;;
            WT_ERROR_CODE=*) WT_ERROR_CODE=${wt_line#*=} ;;
            WT_ERROR_DESC=*) WT_ERROR_DESC=${wt_line#*=} ;;
            WT_REQUIRED_SCOPE=*) WT_REQUIRED_SCOPE=${wt_line#*=} ;;
            WT_ERROR_RETRYABLE=*) WT_ERROR_RETRYABLE=${wt_line#*=} ;;
        esac
    done <"$wt_state"
}

# Drop the saved state. Called at the start of the next request, so a caller
# can read it as often as it likes in between - including from "$(...)", which
# is itself a subshell and would otherwise consume it before the caller
# could see it.
_wt_clear_state() {
    rm -f "$(_wt_state_file)" 2>/dev/null
}

_wt_usage_fail() {
    WT_ERROR_KIND=usage
    _wt_err "$1"
    _wt_save_state
}

# Clear the error state the caller inspects after a failure, and any state
# left behind by an earlier request in a subshell.
_wt_reset_error() {
    WT_ERROR_KIND=''
    WT_ERROR_CODE=''
    WT_ERROR_STATUS=''
    WT_ERROR_DESC=''
    WT_REQUIRED_SCOPE=''
    WT_ERROR_RETRYABLE=0
    _wt_clear_state
}

# After a call that succeeded, the previous failure's details are history. A
# successful call made in a subshell cannot clear the caller's variables, but
# it can drop the shared file the accessors read.
_wt_succeeded() {
    _wt_clear_state
}

# Percent-encode stdin as a query value, result on stdout.
#
# Done in awk because sed cannot do it portably: % in a sed replacement starts
# a capture-group reference, and & is the whole match, so neither can be
# escaped reliably on every implementation. awk also avoids forking per byte.
#
# Input is read line by line and joined with newlines at the end, so a value
# containing a newline is encoded in full rather than losing its tail.
_wt_urlencode() {
    LC_ALL=C awk '
    BEGIN {
        for (i = 0; i < 256; i++) {
            c = sprintf("%c", i)
            t[c] = sprintf("%%%02X", i)
            if (c ~ /[A-Za-z0-9._~-]/) t[c] = c
        }
        n = 0
    }
    {
        n++
        lines[n] = $0
    }
    END {
        s = ""
        for (k = 1; k <= n; k++) s = s (k > 1 ? "\n" : "") lines[k]
        m = split(s, ch, "")
        o = ""
        for (i = 1; i <= m; i++) o = o t[ch[i]]
        printf "%s", o
    }'
}

# Inverse of _wt_urlencode, used only when scrubbing a response. A malformed
# escape (a lone % or a non-hex pair) is passed through unchanged.
_wt_urldecode() {
    LC_ALL=C awk '
    BEGIN {
        for (i = 0; i < 10; i++) d[sprintf("%d", i)] = i
        for (i = 0; i < 6; i++) d[sprintf("%c", 97 + i)] = 10 + i
        n = 0
    }
    {
        n++
        lines[n] = $0
    }
    END {
        s = ""
        for (k = 1; k <= n; k++) s = s (k > 1 ? "\n" : "") lines[k]
        m = split(s, ch, "")
        i = 1
        while (i <= m) {
            if (ch[i] == "%" && i + 2 <= m && (ch[i+1] in d) && (ch[i+2] in d)) {
                out = out sprintf("%c", d[ch[i+1]] * 16 + d[ch[i+2]])
                i += 3
            } else {
                out = out ch[i]
                i++
            }
        }
        printf "%s", out
    }'
}

# Read one field from a flat JSON object such as
# {"status":"0","error":"1200","desc":"Incorrect arguments specified"}.
#   _wt_json_field '<json>' key
# First match wins. Quoted and bare values are both accepted.
_wt_json_field() {
    printf '%s' "$1" | awk -v key="$2" '
    BEGIN {
        pat = "\"" key "\"[ \t]*:[ \t]*\"?([^,\"}]*)\"?[ \t]*[,}]"
        n = 0
    }
    {
        n++
        lines[n] = $0
    }
    END {
        s = ""
        for (k = 1; k <= n; k++) s = s (k > 1 ? "\n" : "") lines[k]
        if (match(s, pat)) {
            v = substr(s, RSTART, RLENGTH)
            sub("\"" key "\"[ \t]*:[ \t]*\"?", "", v)
            sub("[\" \t]*[,}]$", "", v)
            gsub(/[\r\n]/, "", v)
            print v
        }
    }'
}

# Pull scope="sms" out of a WWW-Authenticate header. Every such header line is
# examined so a proxy adding its own cannot hide the gateway's.
_wt_scope_from_headers() {
    [ -f "$WT_TMP_HEADERS" ] || return 0
    awk '
    /^[Ww][Ww][Ww]-[Aa]uthenticate:/ {
        # Header lines end in CRLF; without this the CR rides into the value.
        line = $0
        gsub(/\r/, "", line)
        sub(/^[^:]*:[ \t]*/, "", line)
        n = split(line, parts, ",")
        for (i = 1; i <= n; i++) {
            one = parts[i]
            gsub(/^[ \t]+|[ \t]+$/, "", one)
            if (one ~ /^scope=/) {
                sub(/^scope=/, "", one)
                gsub(/"/, "", one)
                found = one
            }
        }
    }
    END { if (found != "") print found }' "$WT_TMP_HEADERS"
}

# Require a token, using the Python client's rule: an empty token is an error.
_wt_require_token() {
    if [ -z "$WT_TOKEN" ]; then
        _wt_usage_fail 'WT_TOKEN is not set. Export a bearer token from the World Text console first.'
        return 1
    fi
}

_wt_base_url() {
    b=${WT_BASE_URL:-$WT_DEFAULT_BASE_URL}
    # A trailing slash would turn "/admin/ping" into "//admin/ping".
    while [ "${b%/}" != "$b" ]; do
        b=${b%/}
    done
    printf '%s' "$b"
}

# Remove every scratch file, whatever route we leave by.
_wt_cleanup() {
    if [ -n "$WT_TMP_CURLRC" ]; then rm -f "$WT_TMP_CURLRC" 2>/dev/null; fi
    if [ -n "$WT_TMP_HEADERS" ]; then rm -f "$WT_TMP_HEADERS" 2>/dev/null; fi
    if [ -n "$WT_TMP_BODY" ]; then rm -f "$WT_TMP_BODY" 2>/dev/null; fi
    if [ -n "$WT_TMP_ERR" ]; then rm -f "$WT_TMP_ERR" 2>/dev/null; fi
    if [ -n "$WT_TMP_CODE" ]; then rm -f "$WT_TMP_CODE" 2>/dev/null; fi
    WT_TMP_CURLRC=''
    WT_TMP_HEADERS=''
    WT_TMP_BODY=''
    WT_TMP_ERR=''
    WT_TMP_CODE=''
}

# mktemp with an explicit template: BSD mktemp needs the Xs at the very end,
# and passing the directory through TMPDIR keeps callers' expectations intact.
_wt_mktemp() {
    wt_dir=${TMPDIR:-/tmp}
    (umask 077 && mktemp "$wt_dir/worldtext.XXXXXXXX") 2>/dev/null
}

_wt_make_temp_files() {
    WT_TMP_CURLRC=$(_wt_mktemp)
    WT_TMP_HEADERS=$(_wt_mktemp)
    WT_TMP_BODY=$(_wt_mktemp)
    WT_TMP_ERR=$(_wt_mktemp)
    WT_TMP_CODE=$(_wt_mktemp)
    for wt_f in "$WT_TMP_CURLRC" "$WT_TMP_HEADERS" "$WT_TMP_BODY" "$WT_TMP_ERR" "$WT_TMP_CODE"; do
        if [ -z "$wt_f" ] || [ ! -f "$wt_f" ]; then
            _wt_cleanup
            _wt_usage_fail 'could not create a temporary file; check that TMPDIR is writable.'
            return 1
        fi
    done
}

# Escape a string for curl's config-file parser: backslash, double quote and
# the control characters that would otherwise end the quoted string early.
_wt_curlrc_escape() {
    printf '%s' "$1" | awk '
    BEGIN {
        n = 0
    }
    {
        n++
        lines[n] = $0
    }
    END {
        s = ""
        for (k = 1; k <= n; k++) s = s (k > 1 ? "\n" : "") lines[k]
        gsub(/\\/, "\\\\", s)
        gsub(/"/, "\\\"", s)
        gsub(/\t/, "\\t", s)
        printf "%s", s
    }'
}

# Write curl's config file. The token lives here rather than in a -H argument,
# so it is absent from "ps" output and from shell history.
#
# Options on the command line win over the config file, so the response sink,
# the header dump and the status being reported with -w cannot be redirected
# by anything a token or a URL might smuggle in.
_wt_write_curlrc() {
    {
        printf 'header = "Authorization: Bearer %s"\n' "$(_wt_curlrc_escape "$WT_TOKEN")"
        printf 'header = "Accept: application/json"\n'
        printf 'header = "Expect:"\n'
        printf 'url = "%s"\n' "$(_wt_curlrc_escape "$1")"
        printf 'connect-timeout = %s\n' "$2"
        printf 'max-time = %s\n' "$2"
    } >"$WT_TMP_CURLRC" 2>/dev/null
}

# ==========================================================================
# The one place a request is made
# ==========================================================================

# _wt_request METHOD PATH [FORM]
#
# GET and DELETE send FORM as the query string. PUT and POST send it as an
# application/x-www-form-urlencoded body, which keeps long member lists out of
# URLs and server logs. The write routes accept PUT and POST alike.
#
# FORM is already encoded ("k=v&k2=v2").
_wt_request() {
    _wt_reset_error

    if ! _wt_require_token; then
        return $WT_EXIT_USAGE
    fi
    if ! command -v curl >/dev/null 2>&1; then
        _wt_usage_fail 'curl is required but was not found on PATH.'
        return $WT_EXIT_USAGE
    fi

    wt_method=$1
    wt_path=$2
    wt_form=${3:-}
    wt_url=$(_wt_base_url)"$wt_path"

    # GET and DELETE put their arguments in the query string. The gateway
    # routes DELETE sub-resources by path and does not reliably read a DELETE
    # body, so a form-encoded delete looks to it like a missing argument.
    # PUT and POST take an x-www-form-urlencoded body, as the gateway expects.
    wt_in_query=0
    case $wt_method in
        GET | DELETE) wt_in_query=1 ;;
    esac
    if [ -n "$wt_form" ] && [ "$wt_in_query" = 1 ]; then
        wt_url="$wt_url?$wt_form"
    fi
    wt_timeout=${WT_TIMEOUT:-$WT_DEFAULT_TIMEOUT}

    if ! _wt_make_temp_files; then
        return $WT_EXIT_USAGE
    fi
    # A SIGINT during a request would otherwise leave the config file, which
    # holds the token, behind.
    trap '_wt_cleanup' EXIT
    trap '_wt_cleanup; exit 129' HUP
    trap '_wt_cleanup; exit 130' INT
    trap '_wt_cleanup; exit 143' TERM

    if ! _wt_write_curlrc "$wt_url" "$wt_timeout"; then
        _wt_cleanup
        _wt_usage_fail 'could not write the curl configuration file.'
        return $WT_EXIT_USAGE
    fi

    # Status goes to stdout via -w; the body, headers and curl's diagnostics go
    # to separate files so nothing but a number is parsed.
    set -- -s -S -D "$WT_TMP_HEADERS" -o "$WT_TMP_BODY" -w '%{http_code}'
    if [ "$WT_INSECURE" = 1 ]; then
        set -- "$@" -k
    fi
    if [ "$wt_method" != GET ]; then
        set -- "$@" -X "$wt_method"
    fi

    if [ -n "$wt_form" ] && [ "$wt_in_query" = 0 ]; then
        printf '%s' "$wt_form" | curl "$@" -K "$WT_TMP_CURLRC" \
            -H 'Content-Type: application/x-www-form-urlencoded' \
            --data-binary @- >"$WT_TMP_CODE" 2>"$WT_TMP_ERR"
    else
        curl "$@" -K "$WT_TMP_CURLRC" >"$WT_TMP_CODE" 2>"$WT_TMP_ERR"
    fi
    wt_curl_rc=$?

    _wt_finish "$wt_curl_rc" "$wt_timeout"
}

# Interpret what curl left behind. Returns the function's exit status and
# prints the envelope on success.
_wt_finish() {
    wt_rc=$1
    wt_timeout=$2
    wt_http=$(cat "$WT_TMP_CODE" 2>/dev/null)
    wt_body=$(cat "$WT_TMP_BODY" 2>/dev/null)
    wt_curl_msg=$(cat "$WT_TMP_ERR" 2>/dev/null)
    # The required scope lives in the response headers, so read it before the
    # scratch files go.
    wt_scope=$(_wt_scope_from_headers)
    _wt_cleanup
    trap - EXIT HUP INT TERM

    if [ "$wt_rc" -ne 0 ]; then
        # curl failed outright. Its message names the URL, never the token, but
        # the base URL is what the caller can act on, so only that is shown.
        WT_ERROR_KIND=transport
        case $wt_rc in
            6 | 7)
                _wt_err "could not connect to $(_wt_base_url) (curl exit $wt_rc). Check WT_BASE_URL, and that the host is reachable."
                ;;
            28)
                WT_ERROR_RETRYABLE=1
                _wt_err "request to $(_wt_base_url) timed out after ${wt_timeout}s (curl exit 28); it may be safe to retry."
                ;;
            35 | 51 | 58 | 59 | 60 | 77 | 90 | 91)
                _wt_err "TLS failure talking to $(_wt_base_url) (curl exit $wt_rc): $wt_curl_msg"
                ;;
            *)
                _wt_err "transport failure talking to $(_wt_base_url) (curl exit $wt_rc): $wt_curl_msg"
                ;;
        esac
        _wt_save_state
        return $WT_EXIT_API
    fi

    case $wt_http in
        '' | *[!0-9]*)
            WT_ERROR_KIND=transport
            _wt_err 'the gateway sent no usable HTTP status; treating it as a transport failure.'
            _wt_save_state
            return $WT_EXIT_API
            ;;
    esac

    wt_flag=$(_wt_json_field "$wt_body" status)
    wt_code=$(_wt_json_field "$wt_body" error)
    wt_desc=$(_wt_json_field "$wt_body" desc)

    wt_retryable=0
    case $wt_flag in
        2 | 3) wt_retryable=1 ;;
    esac
    if [ "$wt_http" -ge 500 ]; then
        wt_retryable=1
    fi

    if [ "$wt_http" -ge 200 ] && [ "$wt_http" -lt 300 ] &&
        { [ -z "$wt_flag" ] || [ "$wt_flag" = 0 ]; }; then
        # Success. Numeric fields stay quoted exactly as the gateway sent them.
        # The body is scrubbed anyway: if a caller's own parameter carried the
        # token and a gateway echoed it, it must not reach their terminal.
        _wt_scrub "$wt_body" "$(_wt_redact)"
        _wt_succeeded
        return 0
    fi

    # Strip control characters: a description reaches a terminal, and it comes
    # from the far end of a network connection.
    # (The required scope, if any, was taken from the headers before cleanup.)
    wt_desc=$(printf '%s' "$wt_desc" | tr -d '\000-\037')
    wt_desc=$(_wt_scrub "$wt_desc" "$(_wt_redact)")

    WT_ERROR_STATUS=$wt_http
    WT_ERROR_CODE=$wt_code
    WT_ERROR_DESC=$wt_desc
    WT_ERROR_RETRYABLE=$wt_retryable
    wt_detail="error ${wt_code:-?}, HTTP $wt_http: ${wt_desc:-no description}"

    case $wt_http in
        401)
            WT_ERROR_KIND=authentication
            _wt_err 'authentication failed (401): the token is missing, unknown or revoked. Check WT_TOKEN.'
            _wt_err "  $wt_detail"
            _wt_save_state
            return $WT_EXIT_AUTH
            ;;
        403)
            WT_ERROR_KIND=permission
            WT_REQUIRED_SCOPE=$wt_scope
            _wt_err "permission denied (403): the token is valid but lacks the '${wt_scope:-required}' permission this operation needs."
            _wt_err "  $wt_detail"
            _wt_save_state
            return $WT_EXIT_PERMISSION
            ;;
    esac

    WT_ERROR_KIND=api
    _wt_err "request failed: $wt_detail"
    if [ "$wt_retryable" = 1 ]; then
        _wt_err '  the gateway marked this failure as temporary; it may be safe to retry.'
    fi
    _wt_save_state
    return $WT_EXIT_API
}

# Remove the token from text destined for the caller. Belt and braces: the
# token travels only in the Authorization header, but a gateway that echoes
# request headers in a body or an error would otherwise print it.
#   _wt_scrub TEXT REDACTION
_wt_scrub() {
    if [ -z "$WT_TOKEN" ]; then
        printf '%s' "$1"
        return 0
    fi
    wt_scrub_redaction=$2
    printf '%s' "$(_wt_urldecode_text "$1")" | awk -v token="$WT_TOKEN" -v redact="$wt_scrub_redaction" '
    {
        line = $0
        out = ""
        while ((i = index(line, token)) > 0) {
            out = out substr(line, 1, i - 1) redact
            line = substr(line, i + length(token))
        }
        print out line
    }'
}

# Decode text given as an argument, not on stdin. Output may contain newlines;
# the caller composes it into a pipeline rather than iterating over lines.
_wt_urldecode_text() {
    printf '%s' "$1" | _wt_urldecode
}

# Build "k=v&". k is a fixed identifier chosen here; v is percent-encoded and
# sent even when empty, because the gateway tells "" apart from absent.
_wt_pair() {
    printf '%s=%s&' "$1" "$(printf '%s' "$2" | _wt_urlencode)"
}

# Drop the trailing '&' left by _wt_pair.
_wt_trim() {
    printf '%s' "${1%&}"
}

# Append key=value arguments to a form. Anything without an '=' is a usage
# error rather than being sent as a nameless parameter.
#   _wt_append_options FORM [key=value ...]   -> prints the new FORM
# Returns non-zero (having printed usage) on a malformed argument.
_wt_append_options() {
    wt_acc=$1
    shift
    for wt_extra in "$@"; do
        case $wt_extra in
            *=*)
                wt_acc="$wt_acc$(_wt_pair "${wt_extra%%=*}" "${wt_extra#*=}")"
                ;;
            *)
                _wt_usage_fail "optional parameters must be key=value, got: $wt_extra"
                return 1
                ;;
        esac
    done
    printf '%s' "$wt_acc"
}

# An entry in /group/entries is "number:name"; the route only uses the part
# before the colon, and rejects an entry without one. A bare number is given
# an empty name, which keeps "wt_add_group_entries <grpid> n1 n2" working.
_wt_members_entry() {
    case $1 in
        *:*) printf '%s' "$1" ;;
        *) printf '%s:' "$1" ;;
    esac
}

# The group id comes back as "groupid" from /group/create, every other route
# takes "grpid", and some answer with either. Accept "grpid=...", "groupid=..."
# or a bare id, so a caller can thread one value through every function.
_wt_group_id() {
    case $1 in
        grpid=* | groupid=*)
            printf '%s' "${1#*=}"
            ;;
        *)
            printf '%s' "$1"
            ;;
    esac
}

# ==========================================================================
# Admin - permission: admin
# ==========================================================================

# Liveness check. -> {"status":"0"}
wt_ping() {
    _wt_request GET /admin/ping
}

# Credit balance. -> {"status":"0","credits":"2950"} - credits stays a string.
wt_get_credits() {
    _wt_request GET /admin/credits
}

# The account the credential belongs to. -> {"status":"0","accountid":"..."}
wt_get_details() {
    _wt_request GET /admin/details
}

# ==========================================================================
# SMS - permission: sms
# ==========================================================================

# Cost of one message, without sending it. -> {"status":"0","credits":"1.0"}
#   wt_get_sms_cost <dstaddr> [message]
wt_get_sms_cost() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_get_sms_cost <dstaddr> [message]'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair dstaddr "$1")
    p="$p$(_wt_pair message "${2:-}")"
    _wt_request GET /sms/cost "$(_wt_trim "$p")"
}

# Send to one destination. -> {"status":"0","result":[{"msgid":"10001",...}]}
#   wt_send_sms <dstaddr> <message> [key=value ...]
# Extra key=value pairs go straight through as optional parameters, so
# clientref=, sendid=, srcaddr=, multipart= and sim= work without this file
# having to enumerate them.
wt_send_sms() {
    if [ "$#" -lt 2 ]; then
        _wt_usage_fail 'usage: wt_send_sms <dstaddr> <message> [param=value ...]'
        return $WT_EXIT_USAGE
    fi
    dstaddr=$1
    message=$2
    shift 2
    p=$(_wt_pair dstaddr "$dstaddr")
    p="$p$(_wt_pair txt "$message")"
    p=$(_wt_append_options "$p" "$@") || return $WT_EXIT_USAGE
    _wt_request PUT /sms/send "$(_wt_trim "$p")"
}

# Delivery state of a sent message.
# -> {"status":"0","state":"5","error":"0","finaldate":"2026-10-25 12:00:05"}
wt_query_sms_status() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_query_sms_status <msgid>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair msgid "$1")
    _wt_request GET /sms/query "$(_wt_trim "$p")"
}

# ==========================================================================
# Lookups - permission: hlr
# ==========================================================================

# HLR lookup; costs a credit. -> {"status":"0","msisdn":"...","state":"OK",...}
wt_lookup_hlr() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_lookup_hlr <msisdn>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair msisdn "$1")
    _wt_request GET /hlr/lookup "$(_wt_trim "$p")"
}

# Mobile number portability. -> {"status":"0","state":"OK",...}
wt_lookup_mnp() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_lookup_mnp <msisdn>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair msisdn "$1")
    _wt_request GET /hlr/mnp "$(_wt_trim "$p")"
}

# Country, network and cost for a number.
# -> {"status":"0","cc":"44","mcc":"234","mnc":"10","country":"...","network":"...","credits":"1.0"}
wt_get_number_info() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_get_number_info <msisdn>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair msisdn "$1")
    _wt_request GET /hlr/info "$(_wt_trim "$p")"
}

# ==========================================================================
# Groups - permission: groups, except wt_get_group_cost and wt_send_group_sms
# ==========================================================================

# Create a group. -> {"status":"0","groupid":"4242"}
#   wt_create_group <name> [srcaddr=<source address>] [pin=<pin>]
wt_create_group() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_create_group <name> [srcaddr=<source address>] [pin=<pin>]'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair name "$1")
    shift
    p=$(_wt_append_options "$p" "$@") || return $WT_EXIT_USAGE
    _wt_request PUT /group/create "$(_wt_trim "$p")"
}

# Delete a group and everything in it. -> {"status":"0"}
wt_delete_group() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_delete_group <grpid>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair grpid "$(_wt_group_id "$1")")
    _wt_request DELETE /group/destroy "$(_wt_trim "$p")"
}

# Add one number. -> {"status":"0","added":"1","failed":"0"}
#   wt_add_group_entry <grpid> <number> [name=<entry name>]
# /group/entry names the number "dstaddr", unlike the other group routes.
wt_add_group_entry() {
    if [ "$#" -lt 2 ]; then
        _wt_usage_fail 'usage: wt_add_group_entry <grpid> <number> [name=<entry name>]'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair grpid "$(_wt_group_id "$1")")
    p="$p$(_wt_pair dstaddr "$2")"
    shift 2
    p=$(_wt_append_options "$p" "$@") || return $WT_EXIT_USAGE
    _wt_request PUT /group/entry "$(_wt_trim "$p")"
}

# Remove one number. -> {"status":"0"}
wt_delete_group_entry() {
    if [ "$#" -lt 2 ]; then
        _wt_usage_fail 'usage: wt_delete_group_entry <grpid> <number>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair grpid "$(_wt_group_id "$1")")
    p="$p$(_wt_pair dstaddr "$2")"
    _wt_request DELETE /group/entry "$(_wt_trim "$p")"
}

# Add several numbers in one call. -> {"status":"0","added":"2","failed":"0"}
#   wt_add_group_entries <grpid> 'number:name,number:name'
#   wt_add_group_entries <grpid> number [number ...]
# The route wants "number:name,number:name" in "members"; both spellings are
# accepted here so a caller never assembles that string for the common case.
wt_add_group_entries() {
    if [ "$#" -lt 2 ]; then
        _wt_usage_fail "usage: wt_add_group_entries <grpid> 'number:name,number:name' | number [number ...]"
        return $WT_EXIT_USAGE
    fi
    grpid=$(_wt_group_id "$1")
    shift
    members=''
    if [ "$#" -eq 1 ]; then
        # One argument: either an already-formatted members string, or a single
        # bare number to be submitted on its own.
        case $1 in
            *,*) members=$1 ;;
            *:*) members=$1 ;;
            *) members=$(_wt_members_entry "$1") ;;
        esac
    else
        # Several arguments: number/name(:name) pairs joined with commas.
        for n in "$@"; do
            entry=$(_wt_members_entry "$n")
            if [ -z "$members" ]; then
                members=$entry
            else
                members="$members,$entry"
            fi
        done
    fi
    p=$(_wt_pair grpid "$grpid")
    p="$p$(_wt_pair members "$members")"
    _wt_request PUT /group/entries "$(_wt_trim "$p")"
}

# Every number in a group. -> {"status":"0","numbers":["447989000001",...]}
wt_get_group_numbers() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_get_group_numbers <grpid>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair grpid "$(_wt_group_id "$1")")
    _wt_request GET /group/numbers "$(_wt_trim "$p")"
}

# Numbers in a group with their name and status.
# -> {"status":"0","details":[{"number":"...","status":"5"},...]}
wt_get_group_details() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_get_group_details <grpid>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair grpid "$(_wt_group_id "$1")")
    _wt_request GET /group/details "$(_wt_trim "$p")"
}

# All groups on the account.
# -> {"status":"0","group":[{"id":"4242","name":"test-group"}]}
wt_list_groups() {
    _wt_request GET /group/list
}

# Remove every number from a group, keeping the group. -> {"status":"0","deleted":"2"}
wt_delete_group_contents() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_delete_group_contents <grpid>'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair grpid "$(_wt_group_id "$1")")
    _wt_request DELETE /group/contents "$(_wt_trim "$p")"
}

# Cost of sending to a whole group.
# -> {"status":"0","credits":"2.0","entries":"2"}
# Needs the sms permission, not groups: it prices a send.
#   wt_get_group_cost <grpid> [multipart] [key=value ...]
wt_get_group_cost() {
    if [ "$#" -lt 1 ]; then
        _wt_usage_fail 'usage: wt_get_group_cost <grpid> [multipart] [key=value ...]'
        return $WT_EXIT_USAGE
    fi
    p=$(_wt_pair grpid "$(_wt_group_id "$1")")
    shift
    if [ "$#" -ge 1 ]; then
        case $1 in
            *=*) ;;
            *)
                p="$p$(_wt_pair multipart "$1")"
                shift
                ;;
        esac
    fi
    p=$(_wt_append_options "$p" "$@") || return $WT_EXIT_USAGE
    _wt_request GET /group/cost "$(_wt_trim "$p")"
}

# Send to every number in a group. Needs the sms permission.
#   wt_send_group_sms <grpid> <message> [key=value ...]
wt_send_group_sms() {
    if [ "$#" -lt 2 ]; then
        _wt_usage_fail 'usage: wt_send_group_sms <grpid> <message> [param=value ...]'
        return $WT_EXIT_USAGE
    fi
    grpid=$(_wt_group_id "$1")
    message=$2
    shift 2
    p=$(_wt_pair grpid "$grpid")
    p="$p$(_wt_pair txt "$message")"
    p=$(_wt_append_options "$p" "$@") || return $WT_EXIT_USAGE
    _wt_request PUT /group/send "$(_wt_trim "$p")"
}

# ==========================================================================
# Conveniences
# ==========================================================================

# Echo WT_REQUIRED_SCOPE for the most recent failure, in this shell or in a
# subshell. After a plain call:
#
#   wt_get_credits || case $(wt_last_scope) in
#       admin) echo 'add the admin permission to the token' ;;
#   esac
#
# Returns non-zero when the last failure carried no scope.
wt_last_scope() {
    _wt_load_state
    [ -n "$WT_REQUIRED_SCOPE" ] || return 1
    printf '%s' "$WT_REQUIRED_SCOPE"
}

# Echo WT_ERROR_KIND for the most recent failure, in this shell or a subshell:
# usage, authentication, permission, api or transport.
wt_last_error_kind() {
    _wt_load_state
    [ -n "$WT_ERROR_KIND" ] || return 1
    printf '%s' "$WT_ERROR_KIND"
}

# Echo WT_ERROR_STATUS for the most recent failure, in this shell or a
# subshell. Returns non-zero when there was no failure.
wt_last_status() {
    _wt_load_state
    [ -n "$WT_ERROR_STATUS" ] || return 1
    printf '%s' "$WT_ERROR_STATUS"
}

# Echo WT_ERROR_CODE, the gateway's numeric error code as a string, e.g. 1200.
wt_last_code() {
    _wt_load_state
    [ -n "$WT_ERROR_CODE" ] || return 1
    printf '%s' "$WT_ERROR_CODE"
}

# Echo WT_ERROR_DESC, the gateway's own description of the failure.
wt_last_desc() {
    _wt_load_state
    [ -n "$WT_ERROR_DESC" ] || return 1
    printf '%s' "$WT_ERROR_DESC"
}

# Echo 1 when the last failure was marked retryable (envelope status "2"/"3" or
# an HTTP 5xx), otherwise 0.
wt_last_retryable() {
    _wt_load_state
    printf '%s' "$WT_ERROR_RETRYABLE"
}

# What this helper is pointed at, with the token redacted. Safe to log.
wt_describe() {
    printf 'worldtext(base_url=%s, token=%s, timeout=%s)\n' \
        "$(_wt_base_url)" "$(_wt_redact)" "${WT_TIMEOUT:-$WT_DEFAULT_TIMEOUT}"
}