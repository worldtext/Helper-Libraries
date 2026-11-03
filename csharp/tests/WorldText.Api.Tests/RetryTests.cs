namespace WorldText.Api.Tests;

/// <summary>
/// Status-flag interpretation and envelope shapes that the mock gateway does
/// not produce: temporary failures (retryable) and HTTP 5xx from a proxy.
/// </summary>
public sealed class RetryTests : MockTestBase
{
    public RetryTests(MockGatewayFixture gateway) : base(gateway)
    {
    }

    private WorldTextClient Client(HttpResponseMessage response)
    {
        var handler = new StubHandler(_ => response);
        return new WorldTextClient("test-all", handler: Recorded(handler)).WithBaseUrl("http://gateway.invalid/v2.0");
    }

    [Theory]
    [InlineData("2", true)]
    [InlineData("3", true)]
    [InlineData("1", false)]
    [InlineData("0", false)]
    public async Task Temporary_status_flags_are_retryable(string flag, bool expected)
    {
        using var client = Client(StubHandler.Json(
            400, $$"""{"status":"{{flag}}","error":"1100","desc":"Insufficient credits"}"""));

        var error = await Assert.ThrowsAsync<ApiException>(() => client.GetCreditsAsync());

        Assert.Equal(expected, error.IsRetryable);
        Assert.Equal(flag, error.StatusFlagValue);
        Assert.Equal("1100", error.ErrorCode);
    }

    [Fact]
    public async Task Server_errors_are_retryable_even_with_a_permanent_flag()
    {
        using var client = Client(StubHandler.Json(503, """{"status":"1","error":"1000","desc":"unavailable"}"""));

        var error = await Assert.ThrowsAsync<ApiException>(() => client.GetCreditsAsync());

        Assert.Equal(503, error.HttpStatus);
        Assert.True(error.IsRetryable);
    }

    [Fact]
    public async Task A_non_json_body_is_still_a_typed_error()
    {
        using var client = Client(StubHandler.Json(502, "<html>bad gateway</html>"));

        var error = await Assert.ThrowsAsync<ApiException>(() => client.GetCreditsAsync());

        Assert.Equal(502, error.HttpStatus);
        Assert.True(error.IsRetryable);
        Assert.Contains("502", error.Message);
    }

    [Fact]
    public async Task Alternately_spelled_headers_and_extra_fields_do_not_break_parsing()
    {
        // Real gateways add fields the library does not model; they are kept in Raw.
        using var client = Client(StubHandler.Json(
            200, """{"status":"0","credits":"12.5","unexpected":{"a":1},"note":"x"}"""));

        var credits = await client.GetCreditsAsync();

        Assert.Equal("12.5", credits.Value);
        Assert.Equal(12.5m, credits.AsDecimal);
        Assert.Equal("x", credits.Raw.GetProperty("note").GetString());
    }
}