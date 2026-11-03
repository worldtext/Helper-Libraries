/**
 * Typed errors for the World Text API.
 *
 * Callers should be able to branch on *why* a call failed without matching on
 * message text, so every failure carries the HTTP status and, where the gateway
 * supplied one, the numeric API error code from the JSON envelope.
 *
 * The token is never held here. Errors are safe to log.
 */

/** `status` values in the error envelope that mean "may be retried". */
export const TEMPORARY_STATUSES = Object.freeze(['2', '3']);

/**
 * Everything an error carries besides its message. Exported for the client's
 * benefit and for anyone building an error by hand.
 *
 * @typedef {object} WorldTextErrorDetails
 * @property {number|null} [httpStatus]  HTTP status, or null for transport failures.
 * @property {string|null} [errorCode]   Gateway error code as a string, e.g. '1200'.
 * @property {string|null} [desc]        Gateway description, or the transport error.
 * @property {string} [statusFlag]       Envelope `status`: '0', '1', '2' or '3'.
 * @property {string|null} [method]      HTTP method of the failed request.
 * @property {string|null} [path]        Request path (no query string, no token).
 */

/**
 * Base class for every failure raised by this client.
 *
 * Branch with `instanceof AuthenticationError` / `PermissionError` /
 * `ApiError`, or with `TransportError` for failures before the gateway
 * answered at all.
 */
export class WorldTextError extends Error {
  /**
   * @param {string} message      Human-readable summary. Never contains the token.
   * @param {WorldTextErrorDetails} [details]
   */
  constructor(message, { httpStatus = null, errorCode = null, desc = null,
    statusFlag = '1', method = null, path = null } = {}) {
    super(message);
    this.name = new.target.name;
    /** @type {number|null} HTTP status returned, or null for a transport failure. */
    this.httpStatus = httpStatus;
    /** @type {string|null} Gateway error code, e.g. '1200'. */
    this.errorCode = errorCode;
    /** @type {string|null} Gateway description, or a short transport explanation. */
    this.desc = desc;
    /**
     * Envelope status flag behind this error; '1' (permanent) when unknown.
     * @type {string}
     */
    this.statusFlag = statusFlag;
    /** @type {string|null} */
    this.method = method;
    /** @type {string|null} */
    this.path = path;
  }

  /**
   * True when retrying may succeed: the envelope's `status` flag is '2' or '3'
   * ("temporary"), the gateway returned a 5xx, or the request never got a reply
   * (a transport failure, such as a refused connection or a timeout).
   *
   * A 4xx other than 408/429 is permanent - resend it unchanged and it will
   * fail the same way.
   * @returns {boolean}
   */
  get retryable() {
    if (TEMPORARY_STATUSES.includes(this.statusFlag)) return true;
    if (this.httpStatus === null) return true;
    return this.httpStatus >= 500 || this.httpStatus === 408 || this.httpStatus === 429;
  }
}

/** 401: the token is missing, malformed, unknown or revoked. */
export class AuthenticationError extends WorldTextError {}

/**
 * 403: the token is valid but lacks the permission this operation needs.
 */
export class PermissionError extends WorldTextError {
  /**
   * @param {string} message
   * @param {WorldTextErrorDetails & { requiredScope?: string|null }} [details]
   *   As for {@link WorldTextError}, plus `requiredScope`: the scope named by
   *   the gateway in `WWW-Authenticate` (e.g. 'admin'), when it sent one.
   */
  constructor(message, { requiredScope = null, ...details } = {}) {
    super(message, details);
    /** @type {string|null} Scope a token needs to perform the failed call. */
    this.requiredScope = requiredScope;
  }
}

/** Any other error response: validation (400), insufficient funds, 5xx. */
export class ApiError extends WorldTextError {}

/**
 * No HTTP response could be read: DNS, connection refused, TLS, timeout, or a
 * body cut short mid-stream. `httpStatus` is null.
 */
export class TransportError extends WorldTextError {}