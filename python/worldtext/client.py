"""HTTP transport and the WorldText client.

Standard library only: urllib for transport, json for payloads.

The token is held in a private attribute and never interpolated into any
message, exception, repr, or log line. ``redact`` is used everywhere a token
could conceivably reach a string.
"""

import json
import logging
import urllib.error
import urllib.parse
import urllib.request

from . import models
from .errors import ApiError, AuthenticationError, PermissionError_, WorldTextError

log = logging.getLogger("worldtext")

DEFAULT_BASE_URL = "https://sms.world-text.com/v2.0"
DEFAULT_TIMEOUT = 30


def redact(token):
    """Return a log-safe form of a token: never the token itself."""
    if not token:
        return "<none>"
    return "<redacted:%d chars>" % len(token)


class WorldText(object):
    """Client for the World Text v2.0 HTTP API.

    Args:
        token:    API token. Bearer auth only - the API has no other scheme.
        base_url: override for testing or a regional host.
        timeout:  per-request timeout in seconds.

    All methods raise a subclass of :class:`WorldTextError` on failure.
    """

    def __init__(self, token, base_url=DEFAULT_BASE_URL, timeout=DEFAULT_TIMEOUT):
        if not token:
            raise ValueError("a token is required")
        self._token = token
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout

    def __repr__(self):
        # Deliberately shows no token.
        return "WorldText(base_url=%r, token=%s)" % (self.base_url, redact(self._token))

    # ------------------------------------------------------------------ core

    def _request(self, method, path, params=None):
        params = params or {}
        url = self.base_url + path

        # Only non-None values are sent, so an omitted optional argument is
        # absent from the request rather than sent as an empty string, which
        # the gateway treats as a value.
        clean = {k: str(v) for k, v in params.items() if v is not None}

        body = None
        headers = {
            "Authorization": "Bearer " + self._token,
            "Accept": "application/json",
        }
        if method in ("PUT", "POST"):
            body = urllib.parse.urlencode(clean).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        elif clean:
            url += "?" + urllib.parse.urlencode(clean)

        log.debug("%s %s (token %s)", method, path, redact(self._token))

        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return self._decode(resp.read(), resp.status)
        except urllib.error.HTTPError as exc:
            # The gateway always sends a JSON envelope, including on error.
            return self._decode(exc.read(), exc.code, www_authenticate=exc.headers.get("WWW-Authenticate"))
        except urllib.error.URLError as exc:
            # Never surface the URL - a caller may have put parameters in it.
            raise WorldTextError(
                "transport failure contacting %s: %s" % (self.base_url, exc.reason)
            )

    def _decode(self, raw, status, www_authenticate=None):
        try:
            payload = json.loads(raw.decode("utf-8")) if raw else {}
        except ValueError:
            raise ApiError(
                "response was not JSON (HTTP %d)" % status, http_status=status
            )

        if 200 <= status < 300 and payload.get("status") in ("0", None):
            return payload

        code = payload.get("error")
        desc = payload.get("desc") or "HTTP %d" % status
        msg = "%s (error %s, HTTP %d)" % (desc, code, status)

        if status == 401:
            raise AuthenticationError(msg, status, code, desc)
        if status == 403:
            raise PermissionError_(
                msg, status, code, desc, required_scope=_scope_from_header(www_authenticate)
            )

        err = ApiError(msg, status, code, desc)
        # '2'/'3' mean "may be retried"; propagate so callers can decide.
        err.status_flag = payload.get("status", "1")
        raise err

    # ----------------------------------------------------------------- admin

    def ping(self):
        """Liveness check. Requires the ``admin`` permission."""
        return models.PingResult(self._request("GET", "/admin/ping"))

    def get_credits(self):
        """Credit balance. Requires the ``admin`` permission."""
        return models.Credits(self._request("GET", "/admin/credits"))

    def get_host(self):
        """Name of the host that served the request. Requires ``http``."""
        return models.HostName(self._request("GET", "/admin/host"))

    # ------------------------------------------------------------------- sms

    def get_sms_cost(self, dstaddr, message=""):
        """Cost of a single message without sending it. Requires ``http``."""
        return models.Cost(
            self._request("GET", "/sms/cost", {"dstaddr": dstaddr, "message": message})
        )

    def send_sms(self, dstaddr, message, **options):
        """Send to one destination. Requires ``http``.

        ``options`` are passed through, so optional fields (``sendid``,
        ``schedule``, ``sender``, ``udh``) work without this client needing to
        enumerate them as the API grows.
        """
        params = {"dstaddr": dstaddr, "message": message}
        params.update(options)
        return models.SendResult(self._request("PUT", "/sms/send", params))

    def query_sms_status(self, msgid):
        """Delivery state of a sent message. Requires ``http``."""
        return models.SmsStatus(self._request("GET", "/sms/query", {"msgid": msgid}))

    # ------------------------------------------------------------------- hlr

    def lookup_hlr(self, msisdn):
        """HLR lookup. Requires ``http``. Costs a credit."""
        return models.HlrResult(self._request("GET", "/hlr/lookup", {"msisdn": msisdn}))

    def lookup_mnp(self, msisdn):
        """Mobile number portability. Requires ``http``."""
        return models.MnpResult(self._request("GET", "/hlr/mnp", {"msisdn": msisdn}))

    def get_number_info(self, msisdn):
        """Country and network for a number. Requires ``http``."""
        return models.NumberInfo(self._request("GET", "/hlr/info", {"msisdn": msisdn}))

    # ---------------------------------------------------------------- groups

    def create_group(self, name):
        """Create a group. Requires ``http``."""
        return models.CreateGroupResult(self._request("PUT", "/group/create", {"name": name}))

    def delete_group(self, grpid):
        """Delete a group and its contents. Requires ``http``."""
        return models.DeleteResult(self._request("DELETE", "/group/destroy", {"grpid": grpid}))

    def add_group_entry(self, grpid, number, **options):
        """Add one number to a group. Requires ``http``."""
        params = {"grpid": grpid, "number": number}
        params.update(options)
        return models.AddEntriesResult(self._request("PUT", "/group/entry", params))

    def delete_group_entry(self, grpid, number):
        """Remove one number. Requires ``http``."""
        return models.DeleteResult(
            self._request("DELETE", "/group/entry", {"grpid": grpid, "number": number})
        )

    def add_group_entries(self, grpid, numbers):
        """Add several numbers, one per line, as the API expects. Requires ``http``."""
        if not isinstance(numbers, (list, tuple)):
            raise TypeError("numbers must be a list or tuple")
        return models.AddEntriesResult(
            self._request("PUT", "/group/entries", {"grpid": grpid, "numbers": "\n".join(numbers)})
        )

    def get_group_numbers(self, grpid):
        """Numbers in a group. Requires ``http``."""
        return models.GroupNumbers(self._request("GET", "/group/numbers", {"grpid": grpid}))

    def get_group_details(self, grpid):
        """Numbers in a group with their status. Requires ``http``."""
        return models.GroupDetails(self._request("GET", "/group/details", {"grpid": grpid}))

    def list_groups(self):
        """All groups on the account. Requires ``http``."""
        raw = self._request("GET", "/group/list")
        items = raw.get("group") or []
        if isinstance(items, dict):
            items = [items]
        result = models.GroupNumbers(raw)
        result.groups = [models.Group(i) for i in items]
        return result

    def delete_group_contents(self, grpid):
        """Remove every number from a group, keeping the group. Requires ``http``."""
        return models.DeleteResult(
            self._request("DELETE", "/group/contents", {"grpid": grpid})
        )

    def get_group_cost(self, grpid, message=""):
        """Cost of sending to a whole group. Requires ``http``, not ``groups``."""
        return models.GroupCost(
            self._request("GET", "/group/cost", {"grpid": grpid, "message": message})
        )

    def send_group_sms(self, grpid, message, **options):
        """Send to every number in a group. Requires ``http``."""
        params = {"grpid": grpid, "message": message}
        params.update(options)
        return models.SendResult(self._request("PUT", "/group/send", params))


def _scope_from_header(header):
    """Pull ``scope="http"`` out of a WWW-Authenticate header, if present."""
    if not header:
        return None
    for part in header.split(","):
        part = part.strip()
        if part.startswith("scope="):
            return part.split("=", 1)[1].strip('"')
    return None