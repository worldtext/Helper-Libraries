namespace WorldText.Api.Tests;

/// <summary>Successful calls, typed parsing and the string-typed numbers.</summary>
public sealed class SuccessTests : MockTestBase
{
    public SuccessTests(MockGatewayFixture gateway) : base(gateway)
    {
    }

    [Fact]
    public async Task Credits_are_parsed_as_strings()
    {
        using var client = Client();

        var credits = await client.GetCreditsAsync();

        Assert.Equal("2950", credits.Value);
        // Numbers arrive as JSON strings; the helper converts only on request.
        Assert.IsType<string>(credits.Value);
        Assert.Equal(2950m, credits.AsDecimal);
    }

    [Fact]
    public async Task Ping_and_host_reach_the_admin_routes()
    {
        using var client = Client("test-admin");

        Assert.True((await client.PingAsync()).Ok);
        Assert.Equal("sms1.example", (await client.GetHostAsync()).Hostname);
    }

    [Fact]
    public async Task Sms_cost_is_a_decimal_string()
    {
        using var client = Client("test-sms");

        var cost = await client.GetSmsCostAsync("447989000001", "hi");

        Assert.Equal("1.0", cost.Value);
        Assert.Equal(1.0m, cost.AsDecimal);
    }

    [Fact]
    public async Task Send_sms_parses_the_message_id_as_a_string()
    {
        using var client = Client("test-sms");

        var result = await client.SendSmsAsync("447989000001", "hi");

        Assert.Single(result.Messages);
        Assert.Equal("10001", result.Id);
        Assert.Equal("447989000001", result.Messages[0].Destination);
        Assert.Equal("1.0", result.Messages[0].Value);
    }

    [Fact]
    public async Task Sms_status_keeps_ids_and_flags_as_strings()
    {
        using var client = Client("test-sms");

        var status = await client.QuerySmsStatusAsync("10001");

        Assert.Equal("5", status.State);
        Assert.Equal("0", status.Error);
        Assert.True(status.IsFinal);
    }

    [Fact]
    public async Task Hlr_operations_parse_their_fields()
    {
        using var client = Client("test-hlr");

        Assert.Equal("UK", (await client.LookupHlrAsync("447989000001")).Region);
        Assert.Equal("ported", (await client.LookupMnpAsync("447989000001")).PortStatus);

        var info = await client.GetNumberInfoAsync("447989000001");
        Assert.Equal("O2-UK", info.Network);
        Assert.Equal("44", info.CountryCode);
        Assert.Equal("1.0", info.Value);
    }

    [Fact]
    public async Task Group_lifecycle_parses_ids_lists_and_counts()
    {
        using var client = Client("test-groups");

        var created = await client.CreateGroupAsync("g");
        Assert.Equal("4242", created.GrpId);

        var added = await client.AddGroupEntryAsync("4242", "447989000001");
        Assert.Equal("1", added.Added);
        Assert.Equal(1, added.AddedValue);

        var addedMany = await client.AddGroupEntriesAsync("4242", ["447989000001", "447989000002"]);
        Assert.Equal("2", addedMany.Added);

        var numbers = await client.GetGroupNumbersAsync("4242");
        Assert.Equal(
            ["447989000001", "447989000002"],
            numbers.Numbers.OrderBy(n => n, StringComparer.Ordinal));

        var details = await client.GetGroupDetailsAsync("4242");
        Assert.Equal(2, details.Entries.Count);
        Assert.All(details.Entries, entry => Assert.Equal("5", entry.Flag));

        var groups = await client.ListGroupsAsync();
        var group = Assert.Single(groups.Groups);
        Assert.Equal("4242", group.GrpId);
        Assert.Equal("test-group", group.Name);
        Assert.Equal("2", group.Value);

        Assert.Equal("2", (await client.DeleteGroupContentsAsync("4242")).Deleted);
        await client.DeleteGroupEntryAsync("4242", "447989000001");
        await client.DeleteGroupAsync("4242");
    }

    [Fact]
    public async Task Group_cost_and_group_send_need_the_http_interface()
    {
        // Scopes name interfaces: an email-only credential is refused for both
        // of these, even though pricing a send costs nothing.
        using var httpClient = Client("test-http");
        using var emailClient = Client("test-email");

        var cost = await httpClient.GetGroupCostAsync("4242");
        Assert.Equal("2.0", cost.Value);
        Assert.Equal(2, cost.EntryCountValue);

        var sent = await httpClient.SendGroupSmsAsync("4242", "hi");
        Assert.Equal("10002", sent.Id);

        await Assert.ThrowsAsync<PermissionException>(() => emailClient.GetGroupCostAsync("4242"));
        await Assert.ThrowsAsync<PermissionException>(() => emailClient.SendGroupSmsAsync("4242", "hi"));
    }

    [Fact]
    public async Task Optional_arguments_are_omitted_rather_than_sent_empty()
    {
        var handler = new StubHandler(_ => StubHandler.Json(200, """{"status":"0","credits":"1.0"}"""));
        using var client = new WorldTextClient("test-sms", handler: Recorded(handler))
            .WithBaseUrl("http://gateway.invalid/v2.0");

        var arguments = new Dictionary<string, string?> { ["dstaddr"] = "447989000001", ["sender"] = null };
        await client.SendSmsAsync("447989000001", "hi", arguments);

        var request = Assert.Single(handler.Requests);
        Assert.Equal(HttpMethod.Put, request.Method);
        Assert.Equal("dstaddr=447989000001&message=hi", request.Body);
    }
}