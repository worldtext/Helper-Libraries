namespace WorldText.Api;

/// <summary>
/// Holds an API token without ever exposing it in text.
/// </summary>
/// <remarks>
/// A plain <see cref="string"/> leaks through <c>ToString()</c>, string
/// interpolation, exception messages and log lines. This type overrides
/// <see cref="ToString"/> to return <c>&lt;redacted:N chars&gt;</c> so that an
/// accidental interpolation is harmless, and exposes the real value only
/// through <see cref="Reveal"/>, which the transport calls to build the
/// <c>Authorization</c> header.
/// </remarks>
public sealed class WorldTextToken
{
    private readonly string _value;

    /// <summary>Creates a token. Throws if <paramref name="value"/> is null or blank.</summary>
    public WorldTextToken(string value)
    {
        if (string.IsNullOrWhiteSpace(value))
            throw new ArgumentException("a token is required", nameof(value));

        _value = value.Trim();
    }

    /// <summary>Number of characters in the token. Useful for diagnostics.</summary>
    public int Length => _value.Length;

    /// <summary>
    /// Returns the secret value. Only the client internals should call this;
    /// never pass the result to a log, an exception message or <c>ToString</c>.
    /// </summary>
    public string Reveal() => _value;

    /// <summary>
    /// Log-safe description of any token value: never the token itself.
    /// Mirrors the Python helper's <c>redact()</c>.
    /// </summary>
    public static string Redact(string? token) =>
        string.IsNullOrEmpty(token) ? "<none>" : $"<redacted:{token.Length} chars>";

    /// <summary>Log-safe description of this token.</summary>
    public override string ToString() => Redact(_value);

    // Structural equality on the secret, so tokens can be compared in tests
    // without printing either value. Hashing is exposed deliberately: the hash
    // of a high-entropy token is not itself a credential.
    public override bool Equals(object? obj) =>
        obj is WorldTextToken other && string.Equals(_value, other._value, StringComparison.Ordinal);

    public override int GetHashCode() => _value.GetHashCode(StringComparison.Ordinal);

    public static bool operator ==(WorldTextToken? left, WorldTextToken? right) =>
        left is null ? right is null : left.Equals(right);

    public static bool operator !=(WorldTextToken? left, WorldTextToken? right) => !(left == right);
}