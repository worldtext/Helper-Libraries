namespace WorldText.Api.Tests;

/// <summary>
/// One test that only needs the library (no gateway) fails with a clear
/// message when python3 is absent; every other test then reports the same
/// reason instead of repeating it. A failure is used because xunit v2 cannot
/// skip a test at run time.
/// </summary>
public sealed class MockAvailabilityTests
{
    [Fact]
    public void Python3_is_available_to_run_the_mock_gateway()
    {
        if (MockGateway.FindPython() is null)
        {
            throw new InvalidOperationException(
                "SKIPPED: python3 was not found on PATH, so the shared mock gateway cannot run. " +
                "Run the suite in an image with python3 (see README.md for the exact command).");
        }

        Assert.True(File.Exists(MockGateway.ScriptPath), $"fixture missing: {MockGateway.ScriptPath}");
    }
}