namespace WorldText.Api.Tests;

/// <summary>
/// Starts one mock gateway for the whole run and shuts it down at the end.
/// Sharing it keeps the suite fast; the mock keeps no state between calls.
/// </summary>
public sealed class MockGatewayFixture : IAsyncLifetime
{
    private MockGateway? _gateway;

    /// <summary>Base URL of the mock. Only valid inside a test.</summary>
    public string BaseUrl => _gateway!.BaseUrl;

    /// <summary>Why the shared mock could not start, for the tests' failure message.</summary>
    public string? UnavailableReason { get; private set; }

    public async Task InitializeAsync()
    {
        try
        {
            _gateway = await MockGateway.StartAsync().ConfigureAwait(false);
        }
        catch (InvalidOperationException exc)
        {
            // python3 is absent, or the mock never reported a port. xunit v2
            // cannot skip at run time, so each gateway-backed test reports
            // this reason via Require() instead of failing for an unknown one.
            UnavailableReason = exc.Message;
        }
    }

    public async Task DisposeAsync()
    {
        if (_gateway is not null)
            await _gateway.DisposeAsync().ConfigureAwait(false);
    }

    /// <summary>Reports why the gateway-backed test cannot run, when it cannot.</summary>
    public void Require()
    {
        if (_gateway is null)
            throw new InvalidOperationException(UnavailableReason ?? NoPythonReason());
    }

    private static string NoPythonReason() =>
        MockGateway.FindPython() is null
            ? "SKIPPED: python3 is not installed on this host, so the shared mock gateway cannot run. " +
              "Run the suite in an image that has python3 (see README.md for the exact command)."
            : "the shared mock gateway did not start";
}

[CollectionDefinition(Name)]
public sealed class MockGatewayCollection : ICollectionFixture<MockGatewayFixture>
{
    public const string Name = "mock-gateway";
}