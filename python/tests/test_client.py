"""Tests for the Python client, run against the shared mock gateway.

No network, no credentials. The mock is started on port 0 so parallel test runs
cannot collide.
"""

import json
import os
import subprocess
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
PKG = os.path.dirname(HERE)
sys.path.insert(0, PKG)

from worldtext import WorldText, WorldTextError  # noqa: E402
from worldtext.errors import (  # noqa: E402
    ApiError,
    AuthenticationError,
    PermissionError_,
)

MOCK = os.path.join(os.path.dirname(PKG), "fixtures", "mock_gateway.py")


def start_mock():
    """Start the mock gateway, return (process, base_url)."""
    proc = subprocess.Popen(
        [sys.executable, MOCK, "0"],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
    )
    line = proc.stdout.readline().decode().strip()
    if not line.startswith("PORT "):
        proc.kill()
        raise RuntimeError("mock gateway did not report a port: %r" % line)
    return proc, "http://127.0.0.1:%s" % line.split()[1]


class MockTestCase(unittest.TestCase):
    proc = None
    base = None

    @classmethod
    def setUpClass(cls):
        cls.proc, cls.base = start_mock()

    @classmethod
    def tearDownClass(cls):
        if cls.proc:
            cls.proc.kill()
            cls.proc.wait()

    def client(self, token="test-all"):
        return WorldText(token=token, base_url=self.base, timeout=5)


class TestSuccess(MockTestCase):
    def test_credits_is_string(self):
        credits = self.client().get_credits()
        self.assertEqual(credits.credits, "2950")
        # Numbers arrive as strings; the helper converts when asked.
        self.assertIsInstance(credits.credits, str)
        self.assertEqual(int(credits.as_decimal), 2950)

    def test_ping_and_host(self):
        wt = self.client("test-admin")
        self.assertTrue(wt.ping().ok)
        self.assertEqual(wt.get_host().hostname, "sms1.example")

    def test_sms_cost(self):
        cost = self.client("test-sms").get_sms_cost("447989000001", "hi")
        self.assertEqual(cost.credits, "1.0")

    def test_send_sms_parses_msgid(self):
        result = self.client("test-sms").send_sms("447989000001", "hi")
        self.assertEqual(len(result.messages), 1)
        self.assertEqual(result.msgid, "10001")
        self.assertEqual(result.messages[0].dstaddr, "447989000001")

    def test_group_lifecycle(self):
        wt = self.client("test-groups")
        self.assertEqual(wt.create_group("g").grpid, "4242")
        self.assertEqual(wt.add_group_entry("4242", "447989000001").added, "1")
        self.assertEqual(
            wt.add_group_entries("4242", ["447989000001", "447989000002"]).added, "2"
        )
        self.assertEqual(sorted(wt.get_group_numbers("4242").numbers),
                         ["447989000001", "447989000002"])
        self.assertEqual(len(wt.get_group_details("4242").entries), 2)
        groups = wt.list_groups().groups
        self.assertEqual(groups[0].grpid, "4242")
        self.assertEqual(groups[0].name, "test-group")
        wt.delete_group_contents("4242")
        wt.delete_group("4242")

    def test_group_cost_needs_sms_not_groups(self):
        # The spec says group/cost requires sms; a groups token must be refused.
        self.assertEqual(self.client("test-sms").get_group_cost("4242").credits, "2.0")
        with self.assertRaises(PermissionError_):
            self.client("test-groups").get_group_cost("4242")

    def test_hlr(self):
        wt = self.client("test-hlr")
        self.assertEqual(wt.lookup_hlr("447989000001").region, "UK")
        self.assertEqual(wt.lookup_mnp("447989000001").mnpportstatus, "ported")
        self.assertEqual(wt.get_number_info("447989000001").network, "O2-UK")


class TestErrors(MockTestCase):
    def test_bad_token_is_401(self):
        with self.assertRaises(AuthenticationError) as ctx:
            self.client("not-a-token").get_credits()
        self.assertEqual(ctx.exception.http_status, 401)
        self.assertEqual(ctx.exception.error_code, "1000")

    def test_wrong_scope_is_403_with_scope(self):
        with self.assertRaises(PermissionError_) as ctx:
            self.client("test-sms").get_credits()
        self.assertEqual(ctx.exception.http_status, 403)
        # The required scope is surfaced so the caller can say which one to add.
        self.assertEqual(ctx.exception.required_scope, "admin")

    def test_missing_argument_is_400(self):
        with self.assertRaises(ApiError) as ctx:
            self.client("test-sms").get_sms_cost("")
        self.assertEqual(ctx.exception.http_status, 400)
        self.assertEqual(ctx.exception.error_code, "1200")
        self.assertFalse(ctx.exception.retryable)

    def test_all_errors_share_a_base_class(self):
        for token, exc in [
            ("nope", AuthenticationError),
            ("test-sms", PermissionError_),
        ]:
            with self.assertRaises(WorldTextError) as ctx:
                self.client(token).get_credits()
            self.assertIsInstance(ctx.exception, exc)


class TestTokenSafety(MockTestCase):
    SECRET = "wt_live_supersecretvalue_that_must_never_leak"

    def test_repr_redacts_token(self):
        text = repr(self.client(self.SECRET))
        self.assertNotIn(self.SECRET, text)
        self.assertIn("redacted", text)

    def test_error_repr_redacts_token(self):
        try:
            self.client(self.SECRET).get_credits()
        except WorldTextError as exc:
            self.assertNotIn(self.SECRET, repr(exc))
            self.assertNotIn(self.SECRET, str(exc))
        else:
            self.fail("expected an authentication error")

    def test_logging_redacts_token(self):
        import logging

        records = []

        class Capture(logging.Handler):
            def emit(self, record):
                records.append(record.getMessage())

        handler = Capture()
        logger = logging.getLogger("worldtext")
        logger.addHandler(handler)
        logger.setLevel(logging.DEBUG)
        try:
            self.client(self.SECRET).get_credits()
        except WorldTextError:
            pass
        finally:
            logger.removeHandler(handler)

        self.assertTrue(records, "expected at least one log record")
        for line in records:
            self.assertNotIn(self.SECRET, line)


if __name__ == "__main__":
    unittest.main(verbosity=2)