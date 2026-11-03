using System.Collections.Concurrent;

namespace WorldText.Api.Tests;

/// <summary>
/// Every HTTP request the library made during this test run, in order, with
/// how long it took.
/// </summary>
/// <remarks>
/// Tests attach <see cref="RecordingHandler"/> underneath the client's own
/// handler, so the whole suite also proves that every call - realistic or
/// stubbed - reached the wire promptly. A request that hangs shows up here
/// rather than as an unexplained slow test.
/// </remarks>
internal static class RequestLog
{
    private static readonly ConcurrentQueue<Entry> Entries = new();

    public static void Add(Entry entry) => Entries.Enqueue(entry);

    public static IReadOnlyList<Entry> Snapshot() => Entries.ToList();

    internal sealed record Entry(HttpMethod Method, string PathAndQuery, TimeSpan Elapsed);

    /// <summary>A call slower than this is treated as a hang, not a network hiccup.</summary>
    public static readonly TimeSpan SlowThreshold = TimeSpan.FromSeconds(5);
}