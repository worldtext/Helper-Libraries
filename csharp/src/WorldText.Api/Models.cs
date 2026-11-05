using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace WorldText.Api;

/// <summary>
/// Conversion helpers shared by the response models.
/// </summary>
/// <remarks>
/// The gateway returns numbers as JSON <em>strings</em>
/// (<c>"credits":"2950"</c>, <c>"msgid":"10001"</c>). Models keep them as
/// strings so long ids round-trip exactly, and offer numeric accessors where a
/// value is genuinely arithmetic. Nothing is ever parsed to <see cref="double"/>.
/// </remarks>
internal static class Wire
{
    /// <summary>Reads one JSON value as a string, tolerating a bare number or boolean.</summary>
    /// <remarks>
    /// Distinct from the lookup overload below rather than being one of its
    /// <see cref="JsonElement"/> parameters, so a single value can never be
    /// mistaken for a property-name array.
    /// </remarks>
    internal static string? ValueText(JsonElement? element)
    {
        if (element is not { } value)
            return null;

        return value.ValueKind switch
        {
            JsonValueKind.String => ValueOrNull(value.GetString()),
            JsonValueKind.Number or JsonValueKind.True or JsonValueKind.False => value.GetRawText(),
            _ => null,
        };
    }

    /// <summary>First present property among <paramref name="names"/>, read as a string.</summary>
    internal static string? Text(JsonElement obj, params string[] names)
    {
        if (obj.ValueKind != JsonValueKind.Object)
            return null;

        foreach (var name in names)
        {
            if (obj.TryGetProperty(name, out var value) && ValueText(value) is { } text)
                return text;
        }

        return null;
    }

    /// <summary>Invariant-culture decimal, or null when absent or not a number.</summary>
    internal static decimal? Decimal(string? text) =>
        decimal.TryParse(text, NumberStyles.Float, CultureInfo.InvariantCulture, out var value)
            ? value
            : null;

    /// <summary>Invariant-culture integer, or null when absent or not an integer.</summary>
    internal static long? Integer(string? text) =>
        long.TryParse(text, NumberStyles.Integer, CultureInfo.InvariantCulture, out var value)
            ? value
            : null;

    /// <summary>
    /// Reads a JSON collection that may be absent, a single object, or an
    /// array - the API is inconsistent about all three.
    /// </summary>
    internal static IReadOnlyList<JsonElement> Items(JsonElement? element)
    {
        if (element is not { } value)
            return Array.Empty<JsonElement>();

        return value.ValueKind switch
        {
            JsonValueKind.Array => value.EnumerateArray().Select(e => e.Clone()).ToList(),
            JsonValueKind.Object or JsonValueKind.String or JsonValueKind.Number => new[] { value.Clone() },
            _ => Array.Empty<JsonElement>(),
        };
    }

    private static string? ValueOrNull(string? value) => string.IsNullOrEmpty(value) ? null : value;
}

/// <summary>
/// Base class for a typed API response.
/// </summary>
/// <remarks>
/// <see cref="Raw"/> is the untouched JSON payload, handy for fields this
/// library does not model yet. <see cref="ToString"/> prints the typed fields
/// only; no response carries credentials, but a stable rendering is also what
/// makes failures readable in test output.
/// </remarks>
public abstract record Model
{
    internal Model(JsonElement raw) => Raw = raw.Clone();

    /// <summary>The raw JSON object as returned by the gateway.</summary>
    public JsonElement Raw { get; }

    public override string ToString() =>
        $"{GetType().Name}({string.Join(", ", Describe())})";

    /// <summary>Field name/value pairs used by <see cref="ToString"/>.</summary>
    protected virtual IEnumerable<string> Describe() => Array.Empty<string>();

    protected static string Show(string? value) => value is null ? "null" : $"\"{value}\"";
}

// ------------------------------------------------------------------ admin

/// <summary>Result of the liveness check.</summary>
public sealed record PingResult : Model
{
    internal PingResult(JsonElement raw) : base(raw) =>
        Ok = Wire.Text(raw, "status") == StatusFlag.Success;

    /// <summary>True when the gateway answered with status <c>"0"</c>.</summary>
    public bool Ok { get; }

    protected override IEnumerable<string> Describe() => [nameof(Ok)];
}

/// <summary>Credit balance: <c>"2950"</c> as a string.</summary>
public sealed record Credits : Model
{
    internal Credits(JsonElement raw) : base(raw) => Value = Wire.Text(raw, "credits");

    /// <summary>The balance exactly as the API sent it; use <see cref="AsDecimal"/> to do arithmetic.</summary>
    public string? Value { get; }

    /// <summary>The balance as a decimal, or null when absent or not numeric.</summary>
    public decimal? AsDecimal => Wire.Decimal(Value);

    protected override IEnumerable<string> Describe() => [$"Credits={Show(Value)}"];
}

/// <summary>The account the credential belongs to.</summary>
public sealed record AccountDetails : Model
{
    internal AccountDetails(JsonElement raw) : base(raw) => Hostname = Wire.Text(raw, "accountid");

    public string? Hostname { get; }

    protected override IEnumerable<string> Describe() => [Show(Hostname)];
}

// -------------------------------------------------------------------- sms

/// <summary>Price of one message, in credits, as a string such as <c>"1.0"</c>.</summary>
public sealed record Cost : Model
{
    internal Cost(JsonElement raw) : base(raw) => Value = Wire.Text(raw, "credits");

    /// <summary>Cost exactly as the API sent it; use <see cref="AsDecimal"/> to do arithmetic.</summary>
    public string? Value { get; }

    public decimal? AsDecimal => Wire.Decimal(Value);

    protected override IEnumerable<string> Describe() => [$"Credits={Show(Value)}"];
}

/// <summary>One destination's outcome within a send.</summary>
public sealed record SentMessage : Model
{
    internal SentMessage(JsonElement raw) : base(raw)
    {
        Id = Wire.Text(raw, "msgid");
        Destination = Wire.Text(raw, "dstaddr");
        Value = Wire.Text(raw, "credits");
        Flag = Wire.Text(raw, "status");
        SendTime = Wire.Text(raw, "sendtime");
    }

    /// <summary>Message id, e.g. <c>"10001"</c>.</summary>
    public string? Id { get; }

    /// <summary>Recipient (MSISDN).</summary>
    public string? Destination { get; }

    /// <summary>Credits charged for this destination, as sent.</summary>
    public string? Value { get; }

    /// <summary>The envelope status flag for this destination.</summary>
    public string? Flag { get; }

    /// <summary>Submission time as the gateway formatted it.</summary>
    public string? SendTime { get; }

    protected override IEnumerable<string> Describe() =>
        [Show(Id), Show(Destination), Show(Value)];
}

/// <summary>Result of a send: one <see cref="SentMessage"/> per destination.</summary>
public sealed record SendResult : Model
{
    internal SendResult(JsonElement raw) : base(raw)
    {
        Messages = Wire.Items(raw.ValueKind == JsonValueKind.Object && raw.TryGetProperty("result", out var r) ? r : null)
            .Select(item => new SentMessage(item))
            .ToList();
    }

    public IReadOnlyList<SentMessage> Messages { get; }

    /// <summary>First message id, or null - the common single-recipient case.</summary>
    public string? Id => Messages.Count > 0 ? Messages[0].Id : null;

    protected override IEnumerable<string> Describe() => [$"Messages={Messages.Count}"];
}

/// <summary>Delivery state of a sent message.</summary>
public sealed record SmsStatus : Model
{
    internal SmsStatus(JsonElement raw) : base(raw)
    {
        State = Wire.Text(raw, "state");
        Error = Wire.Text(raw, "error");
        FinalDate = Wire.Text(raw, "finaldate");
    }

    /// <summary>Delivery state code, e.g. <c>"5"</c>.</summary>
    public string? State { get; }

    /// <summary>Delivery error code, <c>"0"</c> when none.</summary>
    public string? Error { get; }

    /// <summary>Timestamp of the final state change; absent while in flight.</summary>
    public string? FinalDate { get; }

    /// <summary>False while the message is still in flight.</summary>
    public bool IsFinal => !string.IsNullOrEmpty(FinalDate);

    protected override IEnumerable<string> Describe() => [Show(State), Show(FinalDate)];
}

// -------------------------------------------------------------------- hlr

/// <summary>HLR lookup result.</summary>
public sealed record HlrResult : Model
{
    internal HlrResult(JsonElement raw) : base(raw)
    {
        Docomo = Wire.Text(raw, "docomo");
        Region = Wire.Text(raw, "region");
        Info = Wire.Text(raw, "info");
        Error = Wire.Text(raw, "error");
    }

    public string? Docomo { get; }

    /// <summary>Region / network grouping, e.g. <c>"UK"</c>.</summary>
    public string? Region { get; }

    public string? Info { get; }

    public string? Error { get; }

    protected override IEnumerable<string> Describe() => [Show(Region), Show(Info)];
}

/// <summary>Mobile number portability result.</summary>
public sealed record MnpResult : Model
{
    internal MnpResult(JsonElement raw) : base(raw)
    {
        PortStatus = Wire.Text(raw, "mnpportstatus");
        PortDate = Wire.Text(raw, "mnpportdate");
    }

    /// <summary>Porting status, e.g. <c>"ported"</c>.</summary>
    public string? PortStatus { get; }

    public string? PortDate { get; }

    protected override IEnumerable<string> Describe() => [Show(PortStatus)];
}

/// <summary>Country and network for a number.</summary>
public sealed record NumberInfo : Model
{
    internal NumberInfo(JsonElement raw) : base(raw)
    {
        CountryCode = Wire.Text(raw, "cc");
        Mcc = Wire.Text(raw, "mcc");
        Mnc = Wire.Text(raw, "mnc");
        Country = Wire.Text(raw, "country");
        Network = Wire.Text(raw, "network");
        Value = Wire.Text(raw, "credits");
    }

    /// <summary>Dialling country code, e.g. <c>"44"</c>.</summary>
    public string? CountryCode { get; }

    /// <summary>Mobile country code, e.g. <c>"234"</c>.</summary>
    public string? Mcc { get; }

    /// <summary>Mobile network code, e.g. <c>"10"</c>.</summary>
    public string? Mnc { get; }

    public string? Country { get; }

    public string? Network { get; }

    /// <summary>Credits the lookup cost, as sent.</summary>
    public string? Value { get; }

    protected override IEnumerable<string> Describe() => [Show(Country), Show(Network)];
}

// ----------------------------------------------------------------- groups

/// <summary>A group, as listed by <c>list_groups</c>.</summary>
/// <remarks>
/// The API is inconsistent about the id's field name: routes send
/// <c>grpid</c>, <c>groupid</c> or <c>id</c> depending on the operation, and
/// some send both. <see cref="GrpId"/> accepts any of them; the JSON names are
/// bound via <see cref="JsonPropertyNameAttribute"/> so a serialised model
/// keeps the gateway's naming.
/// </remarks>
public sealed record Group : Model
{
    internal Group(JsonElement raw) : base(raw)
    {
        GrpId = Wire.Text(raw, "grpid", "groupid", "id");
        Name = Wire.Text(raw, "name");
        Value = Wire.Text(raw, "size", "entries");
    }

    /// <summary>The group id, whichever of <c>grpid</c>/<c>groupid</c>/<c>id</c> carried it.</summary>
    [JsonPropertyName("grpid")]
    public string? GrpId { get; init; }

    public string? Name { get; }

    /// <summary>Number of entries, as sent.</summary>
    public string? Value { get; }

    protected override IEnumerable<string> Describe() =>
        [Show(GrpId), Show(Name), Show(Value)];
}

/// <summary>Result of creating a group: its new id.</summary>
public sealed record CreateGroupResult : Model
{
    internal CreateGroupResult(JsonElement raw) : base(raw) =>
        GrpId = Wire.Text(raw, "grpid", "groupid", "id");

    [JsonPropertyName("grpid")]
    public string? GrpId { get; init; }

    protected override IEnumerable<string> Describe() => [Show(GrpId)];
}

/// <summary>Price of sending to a whole group.</summary>
public sealed record GroupCost : Model
{
    internal GroupCost(JsonElement raw) : base(raw)
    {
        Value = Wire.Text(raw, "credits");
        EntryCount = Wire.Text(raw, "entrycount", "entries");
    }

    /// <summary>Total cost in credits, as sent (e.g. <c>"2.0"</c>).</summary>
    public string? Value { get; }

    public decimal? AsDecimal => Wire.Decimal(Value);

    /// <summary>Number of group entries priced, as sent.</summary>
    public string? EntryCount { get; }

    public long? EntryCountValue => Wire.Integer(EntryCount);

    protected override IEnumerable<string> Describe() =>
        [$"Credits={Show(Value)}", $"EntryCount={Show(EntryCount)}"];
}

/// <summary>The numbers in a group.</summary>
public sealed record GroupNumbers : Model
{
    internal GroupNumbers(JsonElement raw) : base(raw)
    {
        IReadOnlyList<JsonElement> items =
            Wire.Items(Property(raw, "numbers")).Concat(Wire.Items(Property(raw, "entry"))).ToList();

        Numbers = items
            .Select(item => Wire.ValueText(item) ?? Wire.Text(item, "number"))
            .Where(number => number is not null)
            .Select(number => number!)
            .ToList();
    }

    private static JsonElement? Property(JsonElement raw, string name) =>
        raw.ValueKind == JsonValueKind.Object && raw.TryGetProperty(name, out var value) ? value : null;

    public IReadOnlyList<string> Numbers { get; }

    protected override IEnumerable<string> Describe() => [$"Numbers={Numbers.Count}"];
}

/// <summary>One row of <see cref="GroupDetails"/>.</summary>
public sealed record GroupEntry(string? Number, string? Flag);

/// <summary>The numbers in a group with their per-number status.</summary>
public sealed record GroupDetails : Model
{
    internal GroupDetails(JsonElement raw) : base(raw)
    {
        IReadOnlyList<JsonElement> items =
            Wire.Items(Property(raw, "details")).Concat(Wire.Items(Property(raw, "entry"))).ToList();

        Entries = items
            .Select(item => new GroupEntry(Wire.ValueText(item) ?? Wire.Text(item, "number"), Wire.Text(item, "status")))
            .ToList();
    }

    private static JsonElement? Property(JsonElement raw, string name) =>
        raw.ValueKind == JsonValueKind.Object && raw.TryGetProperty(name, out var value) ? value : null;

    public IReadOnlyList<GroupEntry> Entries { get; }

    protected override IEnumerable<string> Describe() => [$"Entries={Entries.Count}"];
}

/// <summary>Outcome of adding entries: how many were accepted and rejected.</summary>
public sealed record AddEntriesResult : Model
{
    internal AddEntriesResult(JsonElement raw) : base(raw)
    {
        Added = Wire.Text(raw, "added");
        Failed = Wire.Text(raw, "failed");
    }

    /// <summary>Entries added, as sent (e.g. <c>"2"</c>).</summary>
    public string? Added { get; }

    /// <summary>Entries rejected, as sent.</summary>
    public string? Failed { get; }

    public int? AddedValue => (int?)Wire.Integer(Added);

    public int? FailedValue => (int?)Wire.Integer(Failed);

    protected override IEnumerable<string> Describe() => [$"Added={Show(Added)}", $"Failed={Show(Failed)}"];
}

/// <summary>Outcome of a delete: rows removed where the gateway reports a count.</summary>
public sealed record DeleteResult : Model
{
    internal DeleteResult(JsonElement raw) : base(raw) => Deleted = Wire.Text(raw, "deleted", "rows");

    /// <summary>Rows deleted, as sent, or null when the gateway reports none.</summary>
    public string? Deleted { get; }

    public long? DeletedValue => Wire.Integer(Deleted);

    protected override IEnumerable<string> Describe() => [$"Deleted={Show(Deleted)}"];
}

/// <summary>The account's groups, as returned by <c>list_groups</c>.</summary>
public sealed record GroupList : Model
{
    internal GroupList(JsonElement raw) : base(raw) =>
        Groups = Wire.Items(raw.ValueKind == JsonValueKind.Object && raw.TryGetProperty("group", out var g) ? g : null)
            .Select(item => new Group(item))
            .ToList();

    public IReadOnlyList<Group> Groups { get; }

    protected override IEnumerable<string> Describe() => [$"Groups={Groups.Count}"];
}