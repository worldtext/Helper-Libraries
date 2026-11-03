namespace WorldText.Api;

/// <summary>
/// Minimal diagnostic sink used by <see cref="WorldTextClient"/>.
/// </summary>
/// <remarks>
/// Deliberately tiny so the library depends on nothing but the BCL. Messages
/// never contain the token: the client writes the redacted form. No-op by
/// default.
/// </remarks>
public interface ILogger
{
    /// <summary>Writes a debug-level message. The client redacts the token before calling.</summary>
    void Debug(string message);
}

/// <summary>An <see cref="ILogger"/> that discards every message.</summary>
public sealed class NullLogger : ILogger
{
    public static readonly NullLogger Instance = new();

    private NullLogger()
    {
    }

    public void Debug(string message)
    {
    }
}

/// <summary>An <see cref="ILogger"/> that forwards to a delegate, e.g. <c>Console.Error.WriteLine</c>.</summary>
public sealed class DelegateLogger : ILogger
{
    private readonly Action<string> _write;

    public DelegateLogger(Action<string> write) =>
        _write = write ?? throw new ArgumentNullException(nameof(write));

    public void Debug(string message) => _write(message);
}