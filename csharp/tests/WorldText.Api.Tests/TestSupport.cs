using System.Diagnostics;
using System.Net;
using System.Text;

namespace WorldText.Api.Tests;

/// <summary>
/// Collects everything the client writes to its logger, so a test can assert
/// the token never reaches it.
/// </summary>
internal sealed class RecordingLogger : ILogger
{
    private readonly List<string> _lines = new();
    private readonly object _lock = new();

    public IReadOnlyList<string> Lines
    {
        get
        {
            lock (_lock)
                return _lines.ToList();
        }
    }

    public void Debug(string message)
    {
        lock (_lock)
            _lines.Add(message);
    }
}

/// <summary>
/// An <see cref="HttpMessageHandler"/> that answers in-process, for cases the
/// mock gateway cannot produce (transient flags, 5xx, malformed bodies, both
/// spellings of the group id).
/// </summary>
internal sealed class StubHandler : HttpMessageHandler
{
    private readonly Func<HttpRequestMessage, HttpResponseMessage> _respond;

    public StubHandler(Func<HttpRequestMessage, HttpResponseMessage> respond) => _respond = respond;

    public List<(HttpMethod Method, Uri Uri, string? Body, List<KeyValuePair<string, string>> Headers)> Requests { get; } = new();

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        var body = request.Content is null
            ? null
            : await request.Content.ReadAsStringAsync(cancellationToken).ConfigureAwait(false);
        var headers = request.Headers
            .SelectMany(header => header.Value.Select(value => KeyValuePair.Create(header.Key, value)))
            .ToList();
        Requests.Add((request.Method, request.RequestUri!, body, headers));
        return _respond(request);
    }

    public static HttpResponseMessage Json(int status, string json, params (string Name, string Value)[] headers)
    {
        var response = new HttpResponseMessage((HttpStatusCode)status)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json"),
        };

        foreach (var (name, value) in headers)
            response.Headers.TryAddWithoutValidation(name, value);

        return response;
    }
}

/// <summary>
/// Sits underneath a client's own handler and records every real request.
/// </summary>
/// <remarks>
/// The client's handler is still the one that talks HTTP; this only passes the
/// request through and times it, so tests can assert that calls are prompt as
/// well as correct.
/// </remarks>
internal sealed class RecordingHandler : DelegatingHandler
{
    public RecordingHandler(HttpMessageHandler inner) : base(inner)
    {
    }

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        var stopwatch = Stopwatch.StartNew();
        try
        {
            return await base.SendAsync(request, cancellationToken).ConfigureAwait(false);
        }
        finally
        {
            RequestLog.Add(new RequestLog.Entry(
                request.Method,
                request.RequestUri?.PathAndQuery ?? string.Empty,
                stopwatch.Elapsed));
        }
    }
}