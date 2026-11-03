"""World Text API client - Python.

Standard library only. Python 3.8+.

    from worldtext import WorldText, WorldTextError

    wt = WorldText(token="wt_live_...")
    print(wt.get_credits().credits)        # '2950', as a string, like the API
    wt.send_sms(dstaddr="447989000001", message="Hello")

Numbers in the API are JSON strings; the models keep them as strings and offer
int()/Decimal() helpers where a value is genuinely numeric.
"""

from .client import WorldText
from .errors import WorldTextError, AuthenticationError, PermissionError_, ApiError
from .models import (
    Credits,
    Cost,
    HostName,
    HlrResult,
    MnpResult,
    NumberInfo,
    SendResult,
    SentMessage,
    SmsStatus,
    Group,
    GroupCost,
    GroupDetails,
    GroupNumbers,
    AddEntriesResult,
    CreateGroupResult,
    DeleteResult,
    PingResult,
)

__version__ = "1.0.0"

__all__ = [
    "WorldText",
    "WorldTextError",
    "AuthenticationError",
    "PermissionError_",
    "ApiError",
    "Credits",
    "Cost",
    "HostName",
    "HlrResult",
    "MnpResult",
    "NumberInfo",
    "SendResult",
    "SentMessage",
    "SmsStatus",
    "Group",
    "GroupCost",
    "GroupDetails",
    "GroupNumbers",
    "AddEntriesResult",
    "CreateGroupResult",
    "DeleteResult",
    "PingResult",
    "__version__",
]