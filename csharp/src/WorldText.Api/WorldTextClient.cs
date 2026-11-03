using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace WorldText.Api;

/// <summary>
/// Client for the World Text HTTP API (spec version 3.0), bearer
/// authentication only.
/// </summary>
/// <remarks>
/// <para>
/// Every failure is a <see cref="WorldTextException"/>: a
/// <see cref="AuthenticationException"/> for 401, a
/// <see cref="PermissionException"/> for 403 (carrying
/// <see cref="PermissionException.RequiredScope"/>), and an
/// <see cref="ApiException"/> for everything else.
/// </para>
/// <para>
/// The token is held as a <see cref="WorldTextToken"/> and is only ever
/// materialised into the <c>Authorization</c> header - never into a log line,
/// an exception message or <see cref="ToString"/>.
/// </para>
/// <para>
/// The client is safe to share between threads and should be reused: it owns a
/// single <see cref="HttpClient"/>. Dispose it when the application shuts down.
/// </para>
/// </remarks>
public sealed class WorldTextClient : IDisposable
{
    /// <summary>Production base URL for the v2.0 API (spec version 3.0).</summary>
    public const string DefaultBaseUrl = "https://sms.world-text.com/v2.0";

    /// <summary>Per-request timeout used unless a different one is supplied.</summary>
    public static readonly TimeSpan DefaultTimeout = TimeSpan.FromSeconds(30);

    private static readonly MediaTypeHeaderValue FormContentType =
        new("application/x-www-form-urlencoded");

    private readonly WorldTextToken _token;
    private readonly HttpClient _http;
    private readonly ILogger _log;
    private bool _disposed;

    /// <summary>
    /// Creates a client.
    /// </summary>
    /// <param name="token">API token. Required; bearer auth only.</param>
    /// <param name="logger">Optional diagnostic sink. Receives the redacted token, never the token.</param>
    /// <param name="handler">Optional transport, e.g. for tests or a proxy.</param>
    public WorldTextClient(WorldTextToken token, ILogger? logger = null, HttpMessageHandler? handler = null)
    {
        ArgumentNullException.ThrowIfNull(token);

        _token = token;
        _log = logger ?? NullLogger.Instance;
        _http = handler is null ? new HttpClient() : new HttpClient(handler, disposeHandler: true);
    }

    /// <summary>
    /// Creates a client from a raw token string.
    /// </summary>
    /// <exception cref="ArgumentException">The token is null or blank.</exception>
    public WorldTextClient(string token, ILogger? logger = null, HttpMessageHandler? handler = null)
        : this(new WorldTextToken(token), logger, handler)
    {
    }

    /// <summary>Base URL without a trailing slash.</summary>
    public string BaseUrl { get; private set; } = DefaultBaseUrl;

    /// <summary>Per-request timeout. Applies from the next request.</summary>
    public TimeSpan Timeout
    {
        get => _http.Timeout;
        set => _http.Timeout = value;
    }

    /// <summary>Points the client at a different host (a regional host, or a test gateway).</summary>
    /// <exception cref="ArgumentException">The URL is null, blank or not absolute.</exception>
    public WorldTextClient WithBaseUrl(string baseUrl)
    {
        if (string.IsNullOrWhiteSpace(baseUrl))
            throw new ArgumentException("baseUrl is required", nameof(baseUrl));
        if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out _))
            throw new ArgumentException("baseUrl must be an absolute URL", nameof(baseUrl));

        BaseUrl = baseUrl.TrimEnd('/');
        return this;
    }

    /// <summary>The redacted description of this client. Never shows the token.</summary>
    public override string ToString() => $"WorldTextClient(baseUrl={BaseUrl}, token={_token})";

    public void Dispose()
    {
        if (_disposed)
            return;

        _disposed = true;
        _http.Dispose();
    }

    // ---------------------------------------------------------------- admin

    /// <summary>Liveness check. Requires the <c>admin</c> permission.</summary>
    public async Task<PingResult> PingAsync(CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/admin/ping", null, cancellationToken).ConfigureAwait(false));

    /// <summary>Credit balance. Requires the <c>admin</c> permission.</summary>
    public async Task<Credits> GetCreditsAsync(CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/admin/credits", null, cancellationToken).ConfigureAwait(false));

    /// <summary>Name of the host that served the request. Requires the <c>admin</c> permission.</summary>
    public async Task<HostName> GetHostAsync(CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/admin/host", null, cancellationToken).ConfigureAwait(false));

    // ------------------------------------------------------------------ sms

    /// <summary>Cost of one message without sending it. Requires the <c>sms</c> permission.</summary>
    public async Task<Cost> GetSmsCostAsync(
        string dstaddr,
        string message = "",
        CancellationToken cancellationToken = default)
    {
        var args = new Dictionary<string, string?>
        {
            ["dstaddr"] = dstaddr,
            ["message"] = message,
        };

        return new Cost(await SendAsync(HttpMethod.Get, "/sms/cost", args, cancellationToken).ConfigureAwait(false));
    }

    /// <summary>Send to one destination. Requires the <c>sms</c> permission.</summary>
    /// <param name="dstaddr">Recipient MSISDN.</param>
    /// <param name="message">Message text.</param>
    /// <param name="options">
    /// Optional fields passed through unchanged (<c>sendid</c>, <c>schedule</c>,
    /// <c>sender</c>, <c>udh</c>, ...), so new API fields work without a
    /// library release. Null values are omitted.
    /// </param>
    /// <param name="cancellationToken">Cancels the request.</param>
    public async Task<SendResult> SendSmsAsync(
        string dstaddr,
        string message,
        IReadOnlyDictionary<string, string?>? options = null,
        CancellationToken cancellationToken = default)
    {
        var args = new Dictionary<string, string?>
        {
            ["dstaddr"] = dstaddr,
            ["message"] = message,
        };
        Merge(args, options);

        return new SendResult(await SendAsync(HttpMethod.Put, "/sms/send", args, cancellationToken).ConfigureAwait(false));
    }

    /// <summary>Delivery state of a sent message. Requires the <c>sms</c> permission.</summary>
    public async Task<SmsStatus> QuerySmsStatusAsync(string msgid, CancellationToken cancellationToken = default)
    {
        var args = new Dictionary<string, string?> { ["msgid"] = msgid };

        return new SmsStatus(await SendAsync(HttpMethod.Get, "/sms/query", args, cancellationToken).ConfigureAwait(false));
    }

    // ------------------------------------------------------------------ hlr

    /// <summary>HLR lookup. Requires the <c>hlr</c> permission. Costs a credit.</summary>
    public async Task<HlrResult> LookupHlrAsync(string msisdn, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/hlr/lookup", Args("msisdn", msisdn), cancellationToken).ConfigureAwait(false));

    /// <summary>Mobile number portability. Requires the <c>hlr</c> permission.</summary>
    public async Task<MnpResult> LookupMnpAsync(string msisdn, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/hlr/mnp", Args("msisdn", msisdn), cancellationToken).ConfigureAwait(false));

    /// <summary>Country and network for a number. Requires the <c>hlr</c> permission.</summary>
    public async Task<NumberInfo> GetNumberInfoAsync(string msisdn, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/hlr/info", Args("msisdn", msisdn), cancellationToken).ConfigureAwait(false));

    // --------------------------------------------------------------- groups

    /// <summary>Create a group. Requires the <c>groups</c> permission.</summary>
    public async Task<CreateGroupResult> CreateGroupAsync(string name, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Put, "/group/create", Args("name", name), cancellationToken).ConfigureAwait(false));

    /// <summary>Delete a group and its contents. Requires the <c>groups</c> permission.</summary>
    public async Task<DeleteResult> DeleteGroupAsync(string grpid, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Delete, "/group/destroy", Args("grpid", grpid), cancellationToken).ConfigureAwait(false));

    /// <summary>Add one number to a group. Requires the <c>groups</c> permission.</summary>
    /// <param name="grpid">Group id.</param>
    /// <param name="number">Number to add.</param>
    /// <param name="options">Optional fields passed through unchanged.</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    public async Task<AddEntriesResult> AddGroupEntryAsync(
        string grpid,
        string number,
        IReadOnlyDictionary<string, string?>? options = null,
        CancellationToken cancellationToken = default)
    {
        var args = new Dictionary<string, string?>
        {
            ["grpid"] = grpid,
            ["number"] = number,
        };
        Merge(args, options);

        return new AddEntriesResult(
            await SendAsync(HttpMethod.Put, "/group/entry", args, cancellationToken).ConfigureAwait(false));
    }

    /// <summary>Remove one number from a group. Requires the <c>groups</c> permission.</summary>
    public async Task<DeleteResult> DeleteGroupEntryAsync(
        string grpid,
        string number,
        CancellationToken cancellationToken = default)
    {
        var args = new Dictionary<string, string?>
        {
            ["grpid"] = grpid,
            ["number"] = number,
        };

        return new DeleteResult(
            await SendAsync(HttpMethod.Delete, "/group/entry", args, cancellationToken).ConfigureAwait(false));
    }

    /// <summary>Add several numbers. Requires the <c>groups</c> permission.</summary>
    /// <param name="grpid">Group id.</param>
    /// <param name="numbers">Numbers to add; sent one per line, as the API expects.</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    public async Task<AddEntriesResult> AddGroupEntriesAsync(
        string grpid,
        IEnumerable<string> numbers,
        CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(numbers);

        var args = new Dictionary<string, string?>
        {
            ["grpid"] = grpid,
            ["numbers"] = string.Join("\n", numbers),
        };

        return new AddEntriesResult(
            await SendAsync(HttpMethod.Put, "/group/entries", args, cancellationToken).ConfigureAwait(false));
    }

    /// <summary>Numbers in a group. Requires the <c>groups</c> permission.</summary>
    public async Task<GroupNumbers> GetGroupNumbersAsync(string grpid, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/group/numbers", Args("grpid", grpid), cancellationToken).ConfigureAwait(false));

    /// <summary>Numbers in a group with their status. Requires the <c>groups</c> permission.</summary>
    public async Task<GroupDetails> GetGroupDetailsAsync(string grpid, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/group/details", Args("grpid", grpid), cancellationToken).ConfigureAwait(false));

    /// <summary>All groups on the account. Requires the <c>groups</c> permission.</summary>
    public async Task<GroupList> ListGroupsAsync(CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Get, "/group/list", null, cancellationToken).ConfigureAwait(false));

    /// <summary>Remove every number from a group, keeping the group. Requires the <c>groups</c> permission.</summary>
    public async Task<DeleteResult> DeleteGroupContentsAsync(string grpid, CancellationToken cancellationToken = default) =>
        new(await SendAsync(HttpMethod.Delete, "/group/contents", Args("grpid", grpid), cancellationToken).ConfigureAwait(false));

    /// <summary>Cost of sending to a whole group. Requires the <c>sms</c> permission, not <c>groups</c>.</summary>
    public async Task<GroupCost> GetGroupCostAsync(
        string grpid,
        string message = "",
        CancellationToken cancellationToken = default)
    {
        var args = new Dictionary<string, string?>
        {
            ["grpid"] = grpid,
            ["message"] = message,
        };

        return new GroupCost(await SendAsync(HttpMethod.Get, "/group/cost", args, cancellationToken).ConfigureAwait(false));
    }

    /// <summary>Send to every number in a group. Requires the <c>sms</c> permission.</summary>
    /// <param name="grpid">Group id.</param>
    /// <param name="message">Message text.</param>
    /// <param name="options">Optional fields passed through unchanged.</param>
    /// <param name="cancellationToken">Cancels the request.</param>
    public async Task<SendResult> SendGroupSmsAsync(
        string grpid,
        string message,
        IReadOnlyDictionary<string, string?>? options = null,
        CancellationToken cancellationToken = default)
    {
        var args = new Dictionary<string, string?>
        {
            ["grpid"] = grpid,
            ["message"] = message,
        };
        Merge(args, options);

        return new SendResult(await SendAsync(HttpMethod.Put, "/group/send", args, cancellationToken).ConfigureAwait(false));
    }

    // ------------------------------------------------------------- transport

    private async Task<JsonElement> SendAsync(
        HttpMethod method,
        string path,
        IReadOnlyDictionary<string, string?>? args,
        CancellationToken cancellationToken)
    {
        ObjectDisposedException.ThrowIf(_disposed, this);

        // Only non-null values are sent, so an omitted optional argument is
        // absent from the request rather than sent as an empty string, which
        // the gateway treats as a value.
        var clean = (args ?? new Dictionary<string, string?>())
            .Where(pair => pair.Value is not null)
            .ToDictionary(pair => pair.Key, pair => pair.Value!, StringComparer.Ordinal);

        var body = method == HttpMethod.Put || method == HttpMethod.Post;
        var url = BaseUrl + path;
        if (!body && clean.Count > 0)
            url += "?" + FormUrlEncode(clean);

        using var request = new HttpRequestMessage(method, url);
        request.Headers.TryAddWithoutValidation("Authorization", "Bearer " + _token.Reveal());
        request.Headers.TryAddWithoutValidation("Accept", "application/json");
        if (body)
            request.Content = new StringContent(FormUrlEncode(clean), Encoding.UTF8, FormContentType);

        _log.Debug($"{method} {path} (token {_token})");

        HttpResponseMessage response;
        try
        {
            response = await _http.SendAsync(request, cancellationToken).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exc) when (exc is HttpRequestException or OperationCanceledException)
        {
            // Never surface the URL: a caller may have put parameters in it.
            throw new ApiException($"transport failure contacting {BaseUrl}: {exc.Message}", innerException: exc);
        }

        using (response)
        {
            // The gateway always sends a JSON envelope, including on error.
            var raw = await response.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
            return Decode(raw, (int)response.StatusCode, ScopeFromHeader(response));
        }
    }

    private static JsonElement Decode(string raw, int httpStatus, string? requiredScope)
    {
        JsonElement payload;
        try
        {
            payload = string.IsNullOrWhiteSpace(raw)
                ? JsonDocument.Parse("{}").RootElement.Clone()
                : JsonDocument.Parse(raw).RootElement.Clone();
        }
        catch (JsonException)
        {
            throw new ApiException($"response was not JSON (HTTP {httpStatus})", httpStatus);
        }

        if (payload.ValueKind != JsonValueKind.Object)
            throw new ApiException($"response was not a JSON object (HTTP {httpStatus})", httpStatus);

        var flag = Wire.Text(payload, "status");
        if (httpStatus is >= 200 and < 300 && flag is null or StatusFlag.Success)
            return payload;

        var code = Wire.Text(payload, "error");
        var description = Wire.Text(payload, "desc") ?? $"HTTP {httpStatus}";
        var message = $"{description} (error {code ?? "none"}, HTTP {httpStatus})";

        return httpStatus switch
        {
            401 => throw new AuthenticationException(message, httpStatus, code, description, flag),
            403 => throw new PermissionException(message, httpStatus, code, description, requiredScope, flag),
            _ => throw new ApiException(message, httpStatus, code, description, flag),
        };
    }

    /// <summary>Pulls the required scope out of a <c>WWW-Authenticate</c> header, if present.</summary>
    /// <remarks>
    /// The header looks like
    /// <c>Bearer error="insufficient_scope", scope="admin"</c>.
    /// </remarks>
    internal static string? ScopeFromHeader(HttpResponseMessage response)
    {
        // Read the raw header value rather than response.Headers. That collection
        // parses WWW-Authenticate lazily into an AuthenticationHeaderValue, and
        // lazy construction inside a shared response's header store is not
        // thread-safe: concurrent callers on a reused connection can race there
        // and stall for a timeout. A v3.0 gateway may send the real v2.0 header,
        // which is equally malformed as a Bearer challenge, so it is parsed as
        // text either way.
        if (!response.Headers.NonValidated.TryGetValues("WWW-Authenticate", out var values))
            return null;

        foreach (var header in values)
        {
            foreach (var parameter in header.Split(','))
            {
                var part = parameter.Trim();
                if (!part.StartsWith("scope=", StringComparison.OrdinalIgnoreCase))
                    continue;

                return part["scope=".Length..].Trim().Trim('"');
            }
        }

        return null;
    }

    private static string FormUrlEncode(IReadOnlyDictionary<string, string> values) =>
        string.Join("&", values.Select(pair => Uri.EscapeDataString(pair.Key) + "=" + Uri.EscapeDataString(pair.Value)));

    private static Dictionary<string, string?> Args(string key, string? value) =>
        new(StringComparer.Ordinal) { [key] = value };

    private static void Merge(Dictionary<string, string?> target, IReadOnlyDictionary<string, string?>? options)
    {
        if (options is null)
            return;

        foreach (var (key, value) in options)
            target[key] = value;
    }
}