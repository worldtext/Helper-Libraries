namespace WorldText.Api.Tests;

/// <summary>The typed error hierarchy: 401, 403, 400 and branching without string matching.</summary>
public sealed class ErrorTests : MockTestBase
{
    public ErrorTests(MockGatewayFixture gateway) : base(gateway)
    {
    }

    [Fact]
    public async Task Unknown_token_is_401()
    {
        using var client = Client("not-a-token");

        var error = await Assert.ThrowsAsync<AuthenticationException>(() => client.GetCreditsAsync());

        Assert.Equal(401, error.HttpStatus);
        Assert.Equal("1000", error.ErrorCode);
        Assert.Equal("Authorisation Failure", error.Description);
        Assert.False(error.IsRetryable);
    }

    [Fact]
    public void Missing_token_is_refused_by_the_constructor()
    {
        // The client never sends a request without credentials.
        Assert.Throws<ArgumentException>(() => new WorldTextClient("  "));
    }

    [Fact]
    public async Task Wrong_scope_is_403_with_the_required_scope_exposed()
    {
        using var client = Client("test-sms");

        var error = await Assert.ThrowsAsync<PermissionException>(() => client.GetCreditsAsync());

        Assert.Equal(403, error.HttpStatus);
        // The required scope is surfaced so the caller can say which one to add.
        Assert.Equal("admin", error.RequiredScope);
        Assert.Equal("1000", error.ErrorCode);
    }

    [Fact]
    public async Task Group_cost_with_a_groups_token_reports_the_sms_scope()
    {
        using var client = Client("test-groups");

        var error = await Assert.ThrowsAsync<PermissionException>(() => client.GetGroupCostAsync("4242"));

        Assert.Equal("sms", error.RequiredScope);
    }

    [Fact]
    public async Task Missing_required_argument_is_400_and_permanent()
    {
        using var client = Client("test-sms");

        var error = await Assert.ThrowsAsync<ApiException>(() => client.GetSmsCostAsync(string.Empty));

        Assert.Equal(400, error.HttpStatus);
        Assert.Equal("1200", error.ErrorCode);
        Assert.False(error.IsRetryable);
    }

    [Fact]
    public async Task Create_group_without_a_name_is_400()
    {
        using var client = Client("test-groups");

        var error = await Assert.ThrowsAsync<ApiException>(() => client.CreateGroupAsync(string.Empty));

        Assert.Equal(400, error.HttpStatus);
        Assert.Equal("1200", error.ErrorCode);
    }

    [Fact]
    public async Task All_errors_share_a_base_class_and_are_distinguishable_by_type()
    {
        using var unknown = Client("nope");
        using var wrongScope = Client("test-sms");

        var unauthorized = await Assert.ThrowsAnyAsync<WorldTextException>(() => unknown.GetCreditsAsync());
        var forbidden = await Assert.ThrowsAnyAsync<WorldTextException>(() => wrongScope.GetCreditsAsync());

        Assert.IsType<AuthenticationException>(unauthorized);
        Assert.IsType<PermissionException>(forbidden);
        Assert.IsAssignableFrom<PermissionException>(forbidden);
        Assert.NotSame(unauthorized.GetType(), forbidden.GetType());
    }

    [Fact]
    public async Task Permission_errors_without_a_scope_header_have_no_required_scope()
    {
        var handler = new StubHandler(_ =>
            StubHandler.Json(403, """{"status":"1","error":"1000","desc":"forbidden"}"""));
        using var client = new WorldTextClient("test-sms", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        var error = await Assert.ThrowsAsync<PermissionException>(() => client.GetCreditsAsync());

        Assert.Null(error.RequiredScope);
        Assert.Equal("forbidden", error.Description);
    }
}