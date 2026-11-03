#!/usr/bin/env python3
"""
Mock World Text API gateway for testing helper libraries.

Speaks the v2.0 HTTP API described by api/spec.yaml (spec version 3.0). No
network, no database, no credentials: it exists so every language's tests can
assert behaviour without touching a real account.

Behaviour is deliberately narrow and faithful to the spec:

  * bearer auth only; a missing, malformed or unknown token gets 401
  * scope enforcement per operation, 403 when the token lacks the permission
  * the JSON envelope with stringified numbers the real gateway returns
  * argument validation returning 1200 for required-parameter omissions

Usage:
    python3 mock_gateway.py <port> [--port-file PATH]

The port is printed to stdout as "PORT <n>" once listening, so a harness can
bind port 0 and discover the real port without racing for a fixed one.
"""

import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs

# Tokens the mock accepts, and the permissions each carries.
TOKENS = {
    "test-sms": {"sms"},
    "test-groups": {"groups"},
    "test-admin": {"admin"},
    "test-hlr": {"hlr"},
    "test-all": {"sms", "groups", "admin", "hlr"},
}

# path -> permission. Mirrors ScopePolicy in wthttpgw.
SCOPES = {
    "/admin/credits": "admin",
    "/admin/ping": "admin",
    "/admin/host": "admin",
    "/hlr/lookup": "hlr",
    "/hlr/mnp": "hlr",
    "/hlr/info": "hlr",
    "/sms/send": "sms",
    "/sms/cost": "sms",
    "/sms/status": "sms",
    "/sms/query": "sms",
    "/group/cost": "sms",
    "/group/send": "sms",
    "/group/create": "groups",
    "/group/destroy": "groups",
    "/group/entry": "groups",
    "/group/entries": "groups",
    "/group/numbers": "groups",
    "/group/details": "groups",
    "/group/list": "groups",
    "/group/contents": "groups",
}

OK = "0"


def envelope_error(code, desc):
    return {"status": "1", "error": str(code), "desc": desc}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _reply(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _args(self):
        u = urlparse(self.path)
        qs = parse_qs(u.query)
        args = {k: v[0] for k, v in qs.items() if v}
        # Form-encoded bodies are accepted for PUT/POST like the real gateway.
        if self.command in ("PUT", "POST"):
            n = int(self.headers.get("Content-Length") or 0)
            if n:
                raw = self.rfile.read(n).decode("utf-8", "replace")
                for k, v in parse_qs(raw).items():
                    if v:
                        args.setdefault(k, v[0])
        return u.path, args

    def _do(self):
        path, args = self._args()

        auth = self.headers.get("Authorization") or ""
        if not auth.startswith("Bearer "):
            return self._reply(401, envelope_error(1000, "Authorisation Failure"))
        token = auth[len("Bearer "):].strip()
        perms = TOKENS.get(token)
        if perms is None:
            return self._reply(401, envelope_error(1000, "Authorisation Failure"))

        needed = SCOPES.get(path)
        if needed is None:
            return self._reply(400, envelope_error(1201, "Unknown Command"))
        if needed not in perms:
            self.send_response(403)
            self.send_header("WWW-Authenticate",
                             'Bearer error="insufficient_scope", scope="%s"' % needed)
            self.send_header("Content-Type", "application/json")
            body = json.dumps(envelope_error(
                1000, "[RFC6750] The access token does not cover this operation")).encode()
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        return self._route(path, args)

    def _route(self, path, args):
        if path == "/admin/ping":
            return self._reply(200, {"status": OK})

        if path == "/admin/credits":
            return self._reply(200, {"status": OK, "credits": "2950"})

        if path == "/admin/host":
            return self._reply(200, {"status": OK, "hostname": "sms1.example"})

        if path == "/sms/cost":
            if not args.get("dstaddr"):
                return self._reply(400, envelope_error(1200, "Incorrect arguments specified"))
            return self._reply(200, {"status": OK, "credits": "1.0"})

        if path == "/sms/send":
            if not args.get("dstaddr"):
                return self._reply(400, envelope_error(1200, "Incorrect arguments specified"))
            return self._reply(200, {
                "status": OK,
                "result": [{
                    "msgid": "10001",
                    "credits": "1.0",
                    "sendtime": "2026-10-25 12:00:00",
                    "dstaddr": args["dstaddr"],
                    "status": "5",
                }],
            })

        if path == "/sms/query":
            return self._reply(200, {
                "status": OK, "state": "5", "error": "0",
                "finaldate": "2026-10-25 12:00:05",
            })

        if path == "/hlr/lookup":
            return self._reply(200, {
                "status": OK, "docomo": "74450", "region": "UK",
                "info": "Network Name", "error": "0",
            })

        if path == "/hlr/mnp":
            return self._reply(200, {
                "status": OK, "mnpportstatus": "ported", "mnpportdate": "2020-01-01",
            })

        if path == "/hlr/info":
            return self._reply(200, {
                "status": OK, "cc": "44", "mcc": "234", "mnc": "10",
                "country": "United Kingdom", "network": "O2-UK", "credits": "1.0",
            })

        if path == "/group/create":
            if not args.get("name"):
                return self._reply(400, envelope_error(1200, "Incorrect arguments specified"))
            return self._reply(200, {"status": OK, "grpid": "4242", "groupid": "4242"})

        if path == "/group/list":
            return self._reply(200, {"status": OK, "group": [
                {"grpid": "4242", "name": "test-group", "size": "2"},
            ]})

        if path == "/group/details":
            return self._reply(200, {"status": OK, "details": [
                {"number": "447989000001", "status": "5"},
                {"number": "447989000002", "status": "5"},
            ]})

        if path == "/group/numbers":
            return self._reply(200, {"status": OK, "numbers": [
                "447989000001", "447989000002",
            ]})

        if path == "/group/entry":
            if not args.get("grpid"):
                return self._reply(400, envelope_error(1200, "Incorrect arguments specified"))
            return self._reply(200, {"status": OK, "added": "1", "failed": "0"})

        if path == "/group/entries":
            return self._reply(200, {"status": OK, "added": "2", "failed": "0"})

        if path == "/group/cost":
            return self._reply(200, {"status": OK, "credits": "2.0", "entrycount": "2"})

        if path == "/group/send":
            return self._reply(200, {
                "status": OK, "credits": "2.0", "entrycount": "2",
                "result": [{"msgid": "10002", "credits": "1.0", "status": "5"}],
            })

        if path == "/group/destroy":
            return self._reply(200, {"status": OK})

        if path == "/group/contents":
            return self._reply(200, {"status": OK, "deleted": "2"})

        return self._reply(400, envelope_error(1201, "Unknown Command"))

    do_GET = _do
    do_PUT = _do
    do_POST = _do
    do_DELETE = _do

    def log_message(self, *a):
        pass


def main():
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 0
    port_file = None
    if "--port-file" in sys.argv:
        port_file = sys.argv[sys.argv.index("--port-file") + 1]

    srv = HTTPServer(("127.0.0.1", port), Handler)
    real = srv.server_address[1]
    print("PORT %d" % real, flush=True)
    if port_file:
        with open(port_file, "w") as f:
            f.write(str(real))
    srv.serve_forever()


if __name__ == "__main__":
    main()