namespace WorldText.Api.Tests;

/// <summary>
/// The token must never appear in <c>ToString()</c>, exception text or log
/// output. It is shown as <c>&lt;redacted:N chars&gt;</c> instead.
/// </summary>
public sealed class TokenSafetyTests : MockTestBase
{
    private const string Secret = "wt_live_supersecretvalue_that_must_never_leak";

    public TokenSafetyTests(MockGatewayFixture gateway) : base(gateway)
    {
    }

    [Fact]
    public void Client_ToString_and_token_redact_the_value()
    {
        using var client = Client(Secret);

        var text = client.ToString();

        Assert.DoesNotContain(Secret, text);
        Assert.Contains("redacted", text);
        Assert.Contains($"<redacted:{Secret.Length} chars>", text);
        // A token is equally safe when printed on its own or interpolated.
        Assert.DoesNotContain(Secret, new WorldTextToken(Secret).ToString());
        Assert.DoesNotContain(Secret, $"{client}");
    }

    [Fact]
    public async Task Error_text_and_ToString_redact_the_token()
    {
        using var client = Client(Secret);

        var error = await Assert.ThrowsAsync<AuthenticationException>(() => client.GetCreditsAsync());

        Assert.DoesNotContain(Secret, error.Message);
        Assert.DoesNotContain(Secret, error.ToString());
        Assert.DoesNotContain(Secret, error.Description ?? string.Empty);
        Assert.Contains("401", error.Message);
    }

    [Fact]
    public async Task Log_output_redacts_the_token()
    {
        var logger = new RecordingLogger();
        using var client = Client(Secret, logger);

        await Assert.ThrowsAsync<AuthenticationException>(() => client.GetCreditsAsync());

        Assert.NotEmpty(logger.Lines);
        Assert.All(logger.Lines, line => Assert.DoesNotContain(Secret, line));
        Assert.Contains(logger.Lines, line => line.Contains($"<redacted:{Secret.Length} chars>", StringComparison.Ordinal));
    }

    [Fact]
    public async Task Transport_failures_do_not_echo_the_token()
    {
        // Nothing listens on this port: the client reports a transport failure.
        using var client = new WorldTextClient(Secret).WithBaseUrl("http://127.0.0.1:1/v2.0");

        var error = await Assert.ThrowsAsync<ApiException>(() => client.GetCreditsAsync());

        Assert.Null(error.HttpStatus);
        Assert.DoesNotContain(Secret, error.Message);
        Assert.DoesNotContain(Secret, error.ToString());
    }
}