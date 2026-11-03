using System.Diagnostics;
using System.Globalization;

namespace WorldText.Api.Tests;

/// <summary>
/// Runs the shared mock gateway (<c>fixtures/mock_gateway.py</c>) as a child
/// process on an ephemeral port and exposes its base URL.
/// </summary>
/// <remarks>
/// The mock binds port 0 and prints <c>PORT &lt;n&gt;</c> once listening, so
/// parallel test runs cannot collide. It listens on 127.0.0.1 only: nothing
/// here talks to a real network or a real account.
/// </remarks>
public sealed class MockGateway : IAsyncDisposable
{
    private const string StartupTimeoutMessage =
        "mock gateway did not start (!!mock_skip::python3 is not available on this host)";

    private readonly Process _process;

    private MockGateway(Process process, string baseUrl)
    {
        _process = process;
        BaseUrl = baseUrl;
    }

    /// <summary>Base URL of the running mock, e.g. <c>http://127.0.0.1:41234</c>.</summary>
    public string BaseUrl { get; }

    /// <summary>Absolute path of the fixture script.</summary>
    public static string ScriptPath => Path.Combine(AppContext.BaseDirectory, "fixtures", "mock_gateway.py");

    /// <summary>
    /// Finds a python3 interpreter on PATH.
    /// </summary>
    /// <returns>The interpreter path, or null when python3 is not installed.</returns>
    public static string? FindPython()
    {
        foreach (var name in new[] { "python3", "python" })
        {
            var candidate = LookPath(name);
            if (candidate is not null)
                return candidate;
        }

        return null;
    }

    /// <summary>Starts the mock. Throws when python3 is missing or the mock does not report a port.</summary>
    public static async Task<MockGateway> StartAsync()
    {
        var python = FindPython()
            ?? throw new InvalidOperationException(StartupTimeoutMessage);

        var startInfo = new ProcessStartInfo(python)
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
        };
        startInfo.ArgumentList.Add(ScriptPath);
        startInfo.ArgumentList.Add("0");
        startInfo.Environment["PYTHONUNBUFFERED"] = "1";

        var process = Process.Start(startInfo)
            ?? throw new InvalidOperationException(StartupTimeoutMessage);

        var stdout = process.StandardOutput;
        // Drain stderr so the child never blocks on a full pipe.
        _ = process.StandardError.ReadToEndAsync();

        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
        var line = await ReadLineWithTimeoutAsync(stdout, timeout.Token).ConfigureAwait(false);

        if (line is null || !line.StartsWith("PORT ", StringComparison.Ordinal))
        {
            Kill(process);
            throw new InvalidOperationException(
                $"mock gateway did not report a port (got {line ?? "<nothing>"}): {StartupTimeoutMessage}");
        }

        var port = line["PORT ".Length..].Trim();
        return new MockGateway(process, "http://127.0.0.1:" + port);
    }

    public async ValueTask DisposeAsync()
    {
        Kill(_process);
        await Task.Yield();
    }

    private static async Task<string?> ReadLineWithTimeoutAsync(StreamReader reader, CancellationToken cancellationToken)
    {
        var read = reader.ReadLineAsync(cancellationToken).AsTask();
        try
        {
            return await read.ConfigureAwait(false);
        }
        catch (OperationCanceledException)
        {
            return null;
        }
    }

    private static void Kill(Process process)
    {
        try
        {
            if (!process.HasExited)
                process.Kill(entireProcessTree: true);
            process.WaitForExit(5000);
        }
        catch (InvalidOperationException)
        {
            // Already gone.
        }
        finally
        {
            process.Dispose();
        }
    }

    private static string? LookPath(string name)
    {
        var path = Environment.GetEnvironmentVariable("PATH") ?? string.Empty;
        foreach (var directory in path.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
        {
            var candidate = Path.Combine(directory, name);
            if (File.Exists(candidate))
                return candidate;
        }

        return null;
    }

    /// <summary>Formats a timeout the way xunit reports it readably.</summary>
    public override string ToString() => string.Format(CultureInfo.InvariantCulture, "MockGateway({0})", BaseUrl);
}