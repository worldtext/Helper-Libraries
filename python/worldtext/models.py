"""Typed responses.

The gateway returns numbers as JSON *strings* - ``"credits":"2950"``,
``"msgid":"10001"``. That is deliberate and documented, so the models keep them
as strings to round-trip faithfully, and expose numeric accessors where a value
is genuinely arithmetic. Parsing them into floats on the way in would quietly
lose precision on long message ids.

Each model builds from the raw dict, tolerating absent optional fields.
"""

from decimal import Decimal, InvalidOperation


def _get(d, *names, default=None):
    """Return the first present key. The API is inconsistent about naming:
    group ids arrive as either ``grpid`` or ``groupid`` depending on the route."""
    for n in names:
        if d.get(n) is not None:
            return d[n]
    return default


def _dec(value):
    if value in (None, ""):
        return None
    try:
        return Decimal(str(value))
    except (InvalidOperation, ValueError):
        return None


class Model(object):
    """Base: ``__repr__`` that never shows credentials (none are stored) and
    a stable equality so tests can compare without field-by-field asserts."""

    _fields = ()

    def __init__(self, raw=None):
        self.raw = dict(raw or {})

    def __eq__(self, other):
        return type(self) is type(other) and self.raw == other.raw

    def __repr__(self):
        shown = ", ".join("%s=%r" % (f, getattr(self, f, None)) for f in self._fields)
        return "%s(%s)" % (type(self).__name__, shown)


class PingResult(Model):
    _fields = ("ok",)

    def __init__(self, raw):
        super().__init__(raw)
        self.ok = raw.get("status") == "0"


class Credits(Model):
    _fields = ("credits",)

    def __init__(self, raw):
        super().__init__(raw)
        self.credits = _get(raw, "credits")

    @property
    def as_decimal(self):
        return _dec(self.credits)


class HostName(Model):
    _fields = ("hostname",)

    def __init__(self, raw):
        super().__init__(raw)
        self.hostname = _get(raw, "hostname")


class Cost(Model):
    _fields = ("credits",)

    def __init__(self, raw):
        super().__init__(raw)
        self.credits = _get(raw, "credits")

    @property
    def as_decimal(self):
        return _dec(self.credits)


class SentMessage(Model):
    _fields = ("msgid", "dstaddr", "credits", "status", "sendtime")

    def __init__(self, raw):
        super().__init__(raw)
        self.msgid = _get(raw, "msgid")
        self.dstaddr = _get(raw, "dstaddr")
        self.credits = _get(raw, "credits")
        self.status = _get(raw, "status")
        self.sendtime = _get(raw, "sendtime")


class SendResult(Model):
    """A send returns one ``SentMessage`` per destination, under ``result``."""

    _fields = ("messages",)

    def __init__(self, raw):
        super().__init__(raw)
        items = raw.get("result") or []
        if isinstance(items, dict):
            items = [items]
        self.messages = [SentMessage(i) for i in items]

    @property
    def msgid(self):
        """First message id, or None - the common single-recipient case."""
        return self.messages[0].msgid if self.messages else None


class SmsStatus(Model):
    _fields = ("state", "error", "finaldate")

    def __init__(self, raw):
        super().__init__(raw)
        self.state = _get(raw, "state")
        self.error = _get(raw, "error")
        self.finaldate = _get(raw, "finaldate")

    @property
    def final(self):
        """False while the message is still in flight."""
        return bool(self.finaldate)


class HlrResult(Model):
    _fields = ("docomo", "region", "info", "error")

    def __init__(self, raw):
        super().__init__(raw)
        self.docomo = _get(raw, "docomo")
        self.region = _get(raw, "region")
        self.info = _get(raw, "info")
        self.error = _get(raw, "error")


class MnpResult(Model):
    _fields = ("mnpportstatus", "mnpportdate")

    def __init__(self, raw):
        super().__init__(raw)
        self.mnpportstatus = _get(raw, "mnpportstatus")
        self.mnpportdate = _get(raw, "mnpportdate")


class NumberInfo(Model):
    _fields = ("cc", "mcc", "mnc", "country", "network", "credits")

    def __init__(self, raw):
        super().__init__(raw)
        self.cc = _get(raw, "cc")
        self.mcc = _get(raw, "mcc")
        self.mnc = _get(raw, "mnc")
        self.country = _get(raw, "country")
        self.network = _get(raw, "network")
        self.credits = _get(raw, "credits")


class Group(Model):
    _fields = ("grpid", "name", "size")

    def __init__(self, raw):
        super().__init__(raw)
        self.grpid = _get(raw, "grpid", "groupid", "id")
        self.name = _get(raw, "name")
        self.size = _get(raw, "size", "entries")


class CreateGroupResult(Model):
    _fields = ("grpid",)

    def __init__(self, raw):
        super().__init__(raw)
        self.grpid = _get(raw, "grpid", "groupid", "id")


class GroupCost(Model):
    _fields = ("credits", "entrycount")

    def __init__(self, raw):
        super().__init__(raw)
        self.credits = _get(raw, "credits")
        self.entrycount = _get(raw, "entrycount", "entries")

    @property
    def as_decimal(self):
        return _dec(self.credits)


class GroupNumbers(Model):
    _fields = ("numbers",)

    def __init__(self, raw):
        super().__init__(raw)
        items = raw.get("numbers") or raw.get("entry") or []
        self.numbers = [i if isinstance(i, str) else _get(i, "number") for i in items]


class GroupDetails(Model):
    _fields = ("entries",)

    def __init__(self, raw):
        super().__init__(raw)
        items = raw.get("details") or raw.get("entry") or []
        self.entries = [
            {"number": _get(i, "number"), "status": _get(i, "status")}
            if isinstance(i, dict)
            else {"number": i, "status": None}
            for i in items
        ]


class AddEntriesResult(Model):
    _fields = ("added", "failed")

    def __init__(self, raw):
        super().__init__(raw)
        self.added = _get(raw, "added")
        self.failed = _get(raw, "failed")


class DeleteResult(Model):
    _fields = ("deleted", "rows")

    def __init__(self, raw):
        super().__init__(raw)
        self.deleted = _get(raw, "deleted", "rows")


class StatusValue(object):
    """The API's ``status`` flag, named for readability.

    ``"0"`` success, ``"1"`` permanent failure, ``"2"``/``"3"`` temporary.
    """

    SUCCESS = "0"
    PERMANENT = "1"
    TEMPORARY = ("2", "3")