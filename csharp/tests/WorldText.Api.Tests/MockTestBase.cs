namespace WorldText.Api.Tests;

/// <summary>
/// Base for tests that talk to the in-process mock gateway.
/// </summary>
/// <remarks>
/// The mock listens on 127.0.0.1 only, on an ephemeral port. Nothing here
/// reaches a real World Text host or a real account.
/// </remarks>
[Collection(MockGatewayCollection.Name)]
public abstract class MockTestBase
{
    private readonly MockGatewayFixture _gateway;

    protected MockTestBase(MockGatewayFixture gateway) => _gateway = gateway;

    /// <summary>A fresh client per test so no state is shared.</summary>
    protected WorldTextClient Client(string token = "test-all", ILogger? logger = null)
    {
        _gateway.Require();
        return new WorldTextClient(token, logger, new RecordingHandler(new HttpClientHandler()))
            .WithBaseUrl(_gateway.BaseUrl);
    }

    /// <summary>Parses a canned payload without any network at all.</summary>
    protected static T Model<T>(string json) where T : Model =>
        (T)Activator.CreateInstance(typeof(T), System.Text.Json.JsonDocument.Parse(json).RootElement)!;

    /// <summary>Wraps a stub so its requests are counted by the timing test too.</summary>
    protected static HttpMessageHandler Recorded(HttpMessageHandler handler) => new RecordingHandler(handler);
}