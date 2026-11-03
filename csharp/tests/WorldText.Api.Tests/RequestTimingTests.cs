namespace WorldText.Api.Tests;

/// <summary>
/// Every HTTP request the suite made completed promptly.
/// </summary>
/// <remarks>
/// The suite exercises the real transport against the mock gateway; this test
/// is the safety net that says so. A request that took seconds did not get a
/// normal answer, so it is reported with the method and path that stalled -
/// far more useful than an unexplained slow individual test.
/// </remarks>
public sealed class RequestTimingTests
{
    [Fact]
    public void All_requests_completed_promptly()
    {
        var slow = RequestLog.Snapshot()
            .Where(entry => entry.Elapsed > RequestLog.SlowThreshold)
            .Select(entry => $"{entry.Method} {entry.PathAndQuery} took {entry.Elapsed.TotalSeconds:F1}s")
            .ToList();

        Assert.True(
            slow.Count == 0,
            "these requests stalled instead of returning promptly:" + Environment.NewLine +
            string.Join(Environment.NewLine, slow));
    }
}