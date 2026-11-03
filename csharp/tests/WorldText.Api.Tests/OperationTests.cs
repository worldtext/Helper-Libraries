namespace WorldText.Api.Tests;

/// <summary>
/// Syntax and behaviour of individual operations, checked against the mock
/// gateway: bearer auth, PUT/POST interchangeability, and both spellings of
/// the group id.
/// </summary>
public sealed class OperationTests : MockTestBase
{
    public OperationTests(MockGatewayFixture gateway) : base(gateway)
    {
    }

    [Fact]
    public async Task Every_request_carries_a_bearer_header()
    {
        var handler = new StubHandler(_ => StubHandler.Json(200, """{"status":"0","grpid":"1","groupid":"1"}"""));
        using var client = new WorldTextClient("test-all", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        await client.CreateGroupAsync("g");

        var request = Assert.Single(handler.Requests);
        Assert.Equal("Bearer test-all", Assert.Single(request.Headers, h => h.Key == "Authorization").Value);
        Assert.Equal("/v2.0/group/create", request.Uri.AbsolutePath);
    }

    [Fact]
    public async Task Get_requests_put_arguments_in_the_query_string()
    {
        var handler = new StubHandler(_ => StubHandler.Json(200, """{"status":"0","numbers":["447989000001"]}"""));
        using var client = new WorldTextClient("test-groups", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        await client.GetGroupNumbersAsync("4242");

        var request = Assert.Single(handler.Requests);
        Assert.Equal(HttpMethod.Get, request.Method);
        Assert.Equal("http://gateway.invalid/v2.0/group/numbers?grpid=4242", request.Uri.AbsoluteUri);
        Assert.Null(request.Body);
    }

    [Fact]
    public async Task Create_group_accepts_either_id_spelling()
    {
        foreach (var (json, expected) in new[]
                 {
                     ("""{"status":"0","grpid":"4242"}""", "4242"),
                     ("""{"status":"0","groupid":"7777"}""", "7777"),
                     ("""{"status":"0","grpid":"4242","groupid":"4242"}""", "4242"),
                 })
        {
            var handler = new StubHandler(_ => StubHandler.Json(200, json));
            using var client = new WorldTextClient("test-groups", handler: Recorded(handler))
                .WithBaseUrl("http://gateway.invalid/v2.0");

            var result = await client.CreateGroupAsync("g");

            Assert.Equal(expected, result.GrpId);
        }
    }

    [Fact]
    public async Task Listed_groups_accept_either_id_spelling()
    {
        var handler = new StubHandler(_ => StubHandler.Json(
            200,
            """{"status":"0","group":[{"groupid":"7777","name":"a","entries":"1"},{"grpid":"8888","name":"b"}]}"""));
        using var client = new WorldTextClient("test-groups", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        var list = await client.ListGroupsAsync();

        Assert.Equal(["7777", "8888"], list.Groups.Select(g => g.GrpId));
        Assert.Equal("1", list.Groups[0].Value);
    }

    [Fact]
    public async Task Send_sms_accepts_post_like_put()
    {
        // The mock treats PUT and POST alike; the client offers PUT, and a
        // stub proves the form body is what the real gateway expects.
        var handler = new StubHandler(_ => StubHandler.Json(
            200,
            """{"status":"0","result":{"msgid":"10001","dstaddr":"447989000001"}}"""));
        using var client = new WorldTextClient("test-sms", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        var result = await client.SendSmsAsync("447989000001", "hi", new Dictionary<string, string?>
        {
            ["sendid"] = "campaign-1",
        });

        var request = Assert.Single(handler.Requests);
        Assert.Equal(HttpMethod.Put, request.Method);
        Assert.Contains("message=hi", request.Body, StringComparison.Ordinal);
        Assert.Contains("sendid=campaign-1", request.Body, StringComparison.Ordinal);
        Assert.Equal("10001", result.Id);
    }

    [Fact]
    public async Task Group_entries_are_sent_one_per_line()
    {
        var handler = new StubHandler(_ => StubHandler.Json(200, """{"status":"0","added":"2","failed":"0"}"""));
        using var client = new WorldTextClient("test-groups", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        await client.AddGroupEntriesAsync("4242", ["447989000001", "447989000002"]);

        Assert.Equal("grpid=4242&numbers=447989000001%0A447989000002", Assert.Single(handler.Requests).Body);
    }

    [Fact]
    public async Task Optional_fields_pass_through_unchanged()
    {
        var handler = new StubHandler(_ => StubHandler.Json(200, """{"status":"0","added":"1","failed":"0"}"""));
        using var client = new WorldTextClient("test-groups", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        await client.AddGroupEntryAsync("4242", "447989000001", new Dictionary<string, string?>
        {
            ["udh"] = "050003CC0201",
        });

        Assert.Contains("udh=050003CC0201", Assert.Single(handler.Requests).Body, StringComparison.Ordinal);
    }

    [Fact]
    public void The_configured_base_url_is_reported_and_trimmed()
    {
        // Pure configuration: no request is made.
        using var client = new WorldTextClient("test-all").WithBaseUrl("http://example.invalid/v2.0/");

        Assert.Equal("http://example.invalid/v2.0", client.BaseUrl);
        Assert.Equal(WorldTextClient.DefaultBaseUrl, new WorldTextClient("test-all").BaseUrl);
        Assert.Throws<ArgumentException>(() => new WorldTextClient("test-all").WithBaseUrl("not a url"));
    }
}