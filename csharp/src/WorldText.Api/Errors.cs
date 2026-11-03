namespace WorldText.Api;

/// <summary>
/// Values of the API's <c>status</c> flag.
/// </summary>
/// <remarks>
/// <c>"0"</c> success, <c>"1"</c> permanent failure, <c>"2"</c>/<c>"3"</c>
/// temporary (the same request may be retried). The flag is a string in the
/// wire protocol, so it is kept as a string here and interpreted by these
/// helpers.
/// </remarks>
public static class StatusFlag
{
    public const string Success = "0";
    public const string PermanentFailure = "1";
    public const string TemporaryFailure = "2";
    public const string TemporaryFailureRetryLater = "3";

    /// <summary>True for the temporary flags <c>"2"</c> and <c>"3"</c>.</summary>
    public static bool IsTemporary(string? flag) =>
        flag is TemporaryFailure or TemporaryFailureRetryLater;
}

/// <summary>
/// Base type for every failure reported by <see cref="WorldTextClient"/>.
/// </summary>
/// <remarks>
/// Callers branch on <see cref="HttpStatus"/> and <see cref="ErrorCode"/> (and
/// catch <see cref="AuthenticationException"/>, <see cref="PermissionException"/>
/// or <see cref="ApiException"/>) rather than matching on message text.
/// The token is never stored or interpolated here: errors are safe to log.
/// </remarks>
public abstract class WorldTextException : Exception
{
    protected WorldTextException(
        string message,
        int? httpStatus = null,
        string? errorCode = null,
        string? description = null,
        string? statusFlag = null,
        Exception? innerException = null)
        : base(message, innerException)
    {
        HttpStatus = httpStatus;
        ErrorCode = errorCode;
        Description = description;
        StatusFlagValue = statusFlag ?? StatusFlag.PermanentFailure;
    }

    /// <summary>HTTP status returned, or <c>null</c> for a transport failure.</summary>
    public int? HttpStatus { get; }

    /// <summary>Gateway error code as sent, e.g. <c>"1200"</c>, or <c>null</c>.</summary>
    public string? ErrorCode { get; }

    /// <summary>
    /// Gateway description, or the transport failure. Never contains the token.
    /// </summary>
    public string? Description { get; }

    /// <summary>The envelope's <c>status</c> flag; <c>"1"</c> (permanent) by default.</summary>
    public string StatusFlagValue { get; }

    /// <summary>
    /// True when the failure is worth retrying: the gateway sent status
    /// <c>"2"</c>/<c>"3"</c>, or an intermediary answered with a 5xx.
    /// </summary>
    public bool IsRetryable =>
        StatusFlag.IsTemporary(StatusFlagValue) || (HttpStatus is >= 500);

    public override string ToString() =>
        $"{GetType().Name}: {Message} {{HttpStatus={Format(HttpStatus)}, ErrorCode={Format(ErrorCode)}}}";

    private static string Format(int? value) => value?.ToString() ?? "null";
    private static string Format(string? value) => value is null ? "null" : $"\"{value}\"";
}

/// <summary>
/// 401: the token is missing, malformed, unknown or revoked.
/// </summary>
public sealed class AuthenticationException : WorldTextException
{
    public AuthenticationException(string message, int httpStatus = 401, string? errorCode = null,
        string? description = null, string? statusFlag = null)
        : base(message, httpStatus, errorCode, description, statusFlag)
    {
    }
}

/// <summary>
/// 403: the token is valid but lacks the permission the operation needs.
/// </summary>
/// <remarks>
/// <see cref="RequiredScope"/> is parsed from the response's
/// <c>WWW-Authenticate</c> header (e.g. <c>scope="admin"</c>) when present, so
/// the caller can say which permission to add to the token.
/// </remarks>
public sealed class PermissionException : WorldTextException
{
    public PermissionException(string message, int httpStatus = 403, string? errorCode = null,
        string? description = null, string? requiredScope = null, string? statusFlag = null)
        : base(message, httpStatus, errorCode, description, statusFlag)
    {
        RequiredScope = requiredScope;
    }

    /// <summary>The permission the gateway asked for, e.g. <c>"admin"</c>; may be null.</summary>
    public string? RequiredScope { get; }
}

/// <summary>
/// Any other error response: validation (400, code <c>1200</c>), unknown
/// command, insufficient funds, 5xx, or a transport failure.
/// </summary>
public sealed class ApiException : WorldTextException
{
    public ApiException(string message, int? httpStatus = null, string? errorCode = null,
        string? description = null, string? statusFlag = null, Exception? innerException = null)
        : base(message, httpStatus, errorCode, description, statusFlag, innerException)
    {
    }
}