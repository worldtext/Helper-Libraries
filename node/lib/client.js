/**
 * HTTP transport and the World Text client.
 *
 * Zero dependencies: `node:http`/`node:https` for transport and `EventTarget`
 * for debug logging.
 *
 * The token is kept out of the instance entirely, in a module-private
 * WeakMap, and never interpolated into any message, exception, inspection
 * output, debug event, or log line. {@link redact} is used everywhere a token
 * could conceivably reach a string.
 */

import { send } from './http.js';
import {
  ApiError, AuthenticationError, PermissionError, TransportError,
} from './errors.js';
/** @typedef {import('./errors.js').WorldTextError} WorldTextError */
import * as models from './models.js';

export const DEFAULT_BASE_URL = 'https://sms.world-text.com/v2.0';
export const DEFAULT_TIMEOUT = 30;

const inspectSymbol = Symbol.for('nodejs.util.inspect.custom');

/**
 * Credentials, kept out of the *client* debug event so a logging sink that
 * does `JSON.stringify(event)` or `console.log(event)` cannot leak them.
 */
const credentials = new WeakMap();

/**
 * Return a log-safe form of a token: never the token itself.
 * @param {string|null|undefined} token
 * @returns {string} `<redacted:N chars>`, or `<none>` for an empty token.
 */
export function redact(token) {
  if (!token) return '<none>';
  return `<redacted:${token.length} chars>`;
}

/**
 * Debug event target: every call emits `'request'` before it is sent and, on
 * failure, `'error'`. It is an ordinary `EventTarget`, so
 * `addEventListener('request', ...)` / `addEventListener('error', ...)` work.
 *
 * The payloads never contain the token, headers, request bodies or response
 * bodies, so they are safe to send to any log sink. `key` shows which route was
 * called (never the values): a number or id in a URL is still data.
 *
 * @typedef {object} RequestEvent
 * @property {'GET'|'PUT'|'POST'|'DELETE'} method
 * @property {string} path
 * @property {number} callId
 * @property {string[]} keys  Names of the arguments sent, never their values.
 *
 * @typedef {object} ErrorEvent
 * @property {number|null} callId
 * @property {number|null} httpStatus
 * @property {string|null} errorCode
 * @property {string|null} method
 * @property {string|null} path
 * @property {boolean} retryable
 * @property {WorldTextError} error
 *
 * @typedef {EventTarget} ClientEvent
 */

/**
 * Client for the World Text HTTP API (spec version 3.0).
 *
 * @example
 * import { WorldText } from 'world-text';
 *
 * const wt = new WorldText({ token: process.env.WORLD_TEXT_TOKEN });
 * const { credits } = await wt.getCredits();  // '2950' - a string, as the API sends it
 *
 * @example <caption>Fail fast when the token lacks a permission you need</caption>
 * import { WorldText, PermissionError } from 'world-text';
 *
 * const wt = new WorldText({ token, permissions: ['http'] });
 * try {
 *   await wt.getCredits();          // fine: admin
 *   await wt.sendSms('447989000001', 'hi');
 * } catch (error) {
 *   if (error instanceof PermissionError) console.error('add interface:', error.requiredScope);
 *   else throw error;               // PermissionError from sendSms says requiredScope: 'http'
 * }
 *
 * Every method resolves with a typed model or rejects with a subclass of
 * {@link import('./errors.js').WorldTextError}.
 */
export class WorldText {
  /** @type {string} Base URL without a trailing slash. */
  #baseUrl;
  /** @type {number} Per-request timeout in milliseconds. */
  #timeoutMs;
  /** @type {ClientEvent} */
  #events;
  /** @type {Set<string>} */
  #permissions;
  /** @type {number} Monotonic request counter, correlating events. */
  #callCount = 0;

  /**
   * @param {object} options
   * @param {string} options.token  API token. Bearer auth only - the API has no other scheme.
   * @param {string} [options.baseUrl]  Override for testing or a regional host.
   * @param {number} [options.timeout]  Per-request timeout in **seconds** (like the Python
   *   reference). Defaults to 30.
   * @param {AbortSignal|null} [options.signal]  Cancels this client's in-flight requests
   *   (e.g. on shutdown); they reject with a {@link TransportError}.
   * @param {string[]|null} [options.permissions]  The interfaces (`'http'`, `'email'`,
   *   `'bulk'`) this token was issued with. When given, the client
   *   refuses - client side, without a request - any operation outside them,
   *   throwing a {@link PermissionError} whose `requiredScope` names the missing one.
   */
  constructor({ token, baseUrl = DEFAULT_BASE_URL, timeout = DEFAULT_TIMEOUT,
    signal = null, permissions = null } = /** @type {any} */ ({})) {
    if (typeof token !== 'string' || token === '') {
      throw new TypeError('a token is required');
    }
    if (typeof baseUrl !== 'string' || baseUrl === '') {
      throw new TypeError('baseUrl must be a non-empty string');
    }
    if (typeof timeout !== 'number' || !Number.isFinite(timeout) || timeout <= 0) {
      throw new RangeError('timeout must be a positive number of seconds');
    }
    this.#permissions = new Set(permissions ?? []);
    this.#baseUrl = baseUrl.replace(/\/+$/, '');
    this.#timeoutMs = Math.max(1, Math.round(timeout * 1000));
    this.#events = Object.assign(new EventTarget(), { detail: Object.freeze({}) });
    credentials.set(this, { token, signal });
  }

  /** @returns {string} Base URL for this client. */
  get baseUrl() {
    return this.#baseUrl;
  }

  /** @returns {number} Per-request timeout in seconds. */
  get timeout() {
    return this.#timeoutMs / 1000;
  }

  /**
   * Debug event target. Listen for `'request'` and `'error'`; both carry
   * metadata only, never credentials.
   * @returns {ClientEvent}
   */
  get events() {
    return this.#events;
  }

  /**
   * A string safe to log: shows the base URL and the *redacted* token.
   * @returns {string}
   */
  toString() {
    return `WorldText(baseUrl=${JSON.stringify(this.#baseUrl)}, token=${redact(credentials.get(this).token)})`;
  }

  /** `util.inspect` / `console.log` output. Also redacted. */
  [inspectSymbol]() {
    return this.toString();
  }

  // ---------------------------------------------------------------- requests

  /**
   * Perform one API call and decode the envelope.
   * @param {'GET'|'PUT'|'POST'|'DELETE'} method
   * @param {string} path
   * @param {Record<string, unknown>} [params]
   * @param {string|null} [requiredScope]
   * @returns {Promise<Record<string, unknown>>}
   */
  async #request(method, path, params = {}, requiredScope = null) {
    if (requiredScope !== null) this.#requireScope(method, path, requiredScope);

    // Only non-null values are sent, so an omitted optional argument is absent
    // from the request rather than sent as an empty string, which the gateway
    // treats as a value.
    const fields = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value === undefined || value === null) continue;
      fields.append(key, typeof value === 'string' ? value : String(value));
    }

    let url = this.#baseUrl + path;
    /** @type {Record<string, string>} */
    const headers = {
      Authorization: `Bearer ${credentials.get(this).token}`,
      Accept: 'application/json',
    };
    let body;
    if (method === 'PUT' || method === 'POST') {
      body = fields.toString();
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
    } else if (fields.size > 0) {
      url += `?${fields.toString()}`;
    }

    const callId = ++this.#callCount;
    // Argument *names* only: values can be message bodies or phone numbers.
    this.#emit('request', { method, path, callId, keys: [...fields.keys()] });

    let response;
    try {
      response = await send({
        url,
        method,
        headers,
        body: body ?? null,
        timeoutMs: this.#timeoutMs,
        signal: credentials.get(this).signal,
      });
    } catch (cause) {
      // No usable response: say why, without echoing the URL (a caller may have
      // put parameters in it) or anything that could carry credentials.
      const outcome = describeFailure(cause);
      // A body cut off after the status line still knows its status (a 5xx
      // stays retryable); a refused connection or timeout has none.
      const received = /** @type {{ httpStatus?: number }} */ (cause).httpStatus;
      const httpStatus = typeof received === 'number' ? received : null;
      const error = new TransportError(`transport failure contacting ${this.#baseUrl}: ${outcome}`,
        { desc: outcome, httpStatus, method, path });
      this.#emitError(error, callId);
      throw error;
    }

    return this.#decode(response.body, response.status, response.headers, method, path, callId);
  }

  /**
   * Refuse an operation the token is known to lack the permission for, without
   * spending a request on it. Only applies when `permissions` was supplied.
   * @param {'GET'|'PUT'|'POST'|'DELETE'} method
   * @param {string} path
   * @param {string} requiredScope
   */
  #requireScope(method, path, requiredScope) {
    if (this.#permissions.size === 0 || this.#permissions.has(requiredScope)) return;
    const error = new PermissionError(
      `${method} ${path} needs the '${requiredScope}' permission, which this token does not have `
      + `(it has: ${[...this.#permissions].join(', ') || 'none'})`,
      { httpStatus: 403, errorCode: null, desc: 'insufficient_scope',
        method, path, requiredScope },
    );
    this.#emitError(error, null);
    throw error;
  }

  /**
   * Turn the raw response body into either a payload or a typed error.
   * @param {string} body
   * @param {number} httpStatus
   * @param {Record<string, string|string[]|undefined>} headers
   * @param {'GET'|'PUT'|'POST'|'DELETE'} method
   * @param {string} path
   * @param {number} callId
   * @returns {Record<string, unknown>}
   */
  #decode(body, httpStatus, headers, method, path, callId) {
    let payload;
    try {
      payload = body === '' ? {} : JSON.parse(body);
    } catch {
      const error = new ApiError(`response was not JSON (HTTP ${httpStatus})`,
        { httpStatus, method, path });
      this.#emitError(error, callId);
      throw error;
    }
    if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
      const error = new ApiError(`response was not a JSON object (HTTP ${httpStatus})`,
        { httpStatus, method, path });
      this.#emitError(error, callId);
      throw error;
    }

    if (httpStatus >= 200 && httpStatus < 300 && isSuccess(payload.status)) {
      return payload;
    }

    const code = payload.error === undefined || payload.error === null ? null : String(payload.error);
    const desc = payload.desc ? String(payload.desc) : `HTTP ${httpStatus}`;
    const message = `${desc} (error ${code}, HTTP ${httpStatus})`;
    const details = {
      httpStatus,
      errorCode: code,
      desc,
      // '2'/'3' mean "may be retried"; carry the flag so callers can decide.
      statusFlag: payload.status === undefined || payload.status === null
        ? '1' : String(payload.status),
      method,
      path,
    };

    let error;
    if (httpStatus === 401) {
      error = new AuthenticationError(message, details);
    } else if (httpStatus === 403) {
      error = new PermissionError(message, {
        ...details, requiredScope: scopeFromHeaders(headers),
      });
    } else {
      error = new ApiError(message, details);
    }
    this.#emitError(error, callId);
    throw error;
  }

  /**
   * @param {'request'|'error'} type
   * @param {Record<string, unknown>} detail
   */
  #emit(type, detail) {
    this.#events.dispatchEvent(new CustomEvent(type, { detail }));
  }

  /**
   * @param {WorldTextError} error
   * @param {number|null} callId
   */
  #emitError(error, callId) {
    // Errors are loop-free here, so the detail can be inspected and serialised.
    this.#emit('error', {
      callId,
      httpStatus: error.httpStatus,
      errorCode: error.errorCode,
      method: error.method,
      path: error.path,
      retryable: error.retryable,
      error,
    });
  }

  // ------------------------------------------------------------------ admin

  /**
   * Liveness check.
   * @returns {Promise<models.PingResult>}  Requires the `admin` permission.
   */
  async ping() {
    return new models.PingResult(await this.#request('GET', '/admin/ping', {}, 'http'));
  }

  /**
   * Credit balance.
   * @returns {Promise<models.Credits>}  Requires the `admin` permission.
   *   `credits` is a string, e.g. `'2950'`.
   */
  async getCredits() {
    return new models.Credits(await this.#request('GET', '/admin/credits', {}, 'http'));
  }

  /**
   * Name of the host that served the request.
   * @returns {Promise<models.HostName>}  Requires the `admin` permission.
   */
  async getHost() {
    return new models.HostName(await this.#request('GET', '/admin/host', {}, 'http'));
  }

  // -------------------------------------------------------------------- sms

  /**
   * Price a single message without sending it.
   * @param {string} dstaddr  Destination MSISDN.
   * @param {string} [message]  Body, so a multipart message is priced correctly.
   * @returns {Promise<models.Credits>}  Requires the `sms` permission.
   */
  async getSmsCost(dstaddr, message = '') {
    return new models.Credits(
      await this.#request('GET', '/sms/cost', { dstaddr, message }, 'http'),
    );
  }

  /**
   * Send to one destination.
   *
   * @param {string} dstaddr  Destination MSISDN.
   * @param {string} message  Body.
   * @param {Record<string, unknown>} [options]  Optional API fields, passed through so new ones
   *   work without a client release: `sendid`, `schedule`, `sender`, `udh`, etc.
   *   Do not pass `dstaddr` or `message` here.
   * @returns {Promise<models.SendResult>}  Requires the `sms` permission.
   */
  async sendSms(dstaddr, message, options = /** @type {Record<string, unknown>} */ ({})) {
    const params = mergeOptions({ dstaddr, message }, options, ['dstaddr', 'message']);
    return new models.SendResult(await this.#request('PUT', '/sms/send', params, 'http'));
  }

  /**
   * Delivery state of a sent message.
   * @param {string|number} msgid  Message id as returned by a send.
   * @returns {Promise<models.SmsStatus>}  Requires the `sms` permission.
   */
  async querySmsStatus(msgid) {
    return new models.SmsStatus(await this.#request('GET', '/sms/query', { msgid }, 'http'));
  }

  // -------------------------------------------------------------------- hlr

  /**
   * HLR lookup. Costs a credit.
   * @param {string} msisdn
   * @returns {Promise<models.HlrResult>}  Requires the `hlr` permission.
   */
  async lookupHlr(msisdn) {
    return new models.HlrResult(await this.#request('GET', '/hlr/lookup', { msisdn }, 'http'));
  }

  /**
   * Mobile number portability status.
   * @param {string} msisdn
   * @returns {Promise<models.MnpResult>}  Requires the `hlr` permission.
   */
  async lookupMnp(msisdn) {
    return new models.MnpResult(await this.#request('GET', '/hlr/mnp', { msisdn }, 'http'));
  }

  /**
   * Country and network for a number.
   * @param {string} msisdn
   * @returns {Promise<models.NumberInfo>}  Requires the `hlr` permission.
   */
  async getNumberInfo(msisdn) {
    return new models.NumberInfo(await this.#request('GET', '/hlr/info', { msisdn }, 'http'));
  }

  // ----------------------------------------------------------------- groups

  /**
   * Create a group.
   * @param {string} name
   * @returns {Promise<models.CreateGroupResult>}  Requires the `groups` permission.
   */
  async createGroup(name) {
    return new models.CreateGroupResult(
      await this.#request('PUT', '/group/create', { name }, 'http'),
    );
  }

  /**
   * Delete a group and its contents.
   * @param {string|number} grpid
   * @returns {Promise<models.DeleteResult>}  Requires the `groups` permission.
   */
  async deleteGroup(grpid) {
    return new models.DeleteResult(
      await this.#request('DELETE', '/group/destroy', { grpid }, 'http'),
    );
  }

  /**
   * Add one number to a group.
   * @param {string|number} grpid
   * @param {string} number
   * @param {Record<string, unknown>} [options]  Optional API fields, passed through (e.g. `name`).
   * @returns {Promise<models.AddEntriesResult>}  Requires the `groups` permission.
   */
  async addGroupEntry(grpid, number, options = /** @type {Record<string, unknown>} */ ({})) {
    const params = mergeOptions({ grpid, number }, options, ['grpid', 'number']);
    return new models.AddEntriesResult(
      await this.#request('PUT', '/group/entry', params, 'http'),
    );
  }

  /**
   * Remove one number from a group.
   * @param {string|number} grpid
   * @param {string} number
   * @returns {Promise<models.DeleteResult>}  Requires the `groups` permission.
   */
  async deleteGroupEntry(grpid, number) {
    return new models.DeleteResult(
      await this.#request('DELETE', '/group/entry', { grpid, number }, 'http'),
    );
  }

  /**
   * Add several numbers, sent one per line as the API expects.
   * @param {string|number} grpid
   * @param {Iterable<string>} numbers  Array, Set, generator - anything iterable.
   * @returns {Promise<models.AddEntriesResult>}  Requires the `groups` permission.
   */
  async addGroupEntries(grpid, numbers) {
    if (numbers === null || numbers === undefined || typeof numbers === 'string'
      || typeof numbers[Symbol.iterator] !== 'function') {
      throw new TypeError('numbers must be an iterable of strings (not a single string)');
    }
    const params = { grpid, numbers: [...numbers].join('\n') };
    return new models.AddEntriesResult(
      await this.#request('PUT', '/group/entries', params, 'http'),
    );
  }

  /**
   * Numbers in a group.
   * @param {string|number} grpid
   * @returns {Promise<models.GroupNumbers>}  Requires the `groups` permission.
   */
  async getGroupNumbers(grpid) {
    return new models.GroupNumbers(
      await this.#request('GET', '/group/numbers', { grpid }, 'http'),
    );
  }

  /**
   * Numbers in a group with their status.
   * @param {string|number} grpid
   * @returns {Promise<models.GroupDetails>}  Requires the `groups` permission.
   */
  async getGroupDetails(grpid) {
    return new models.GroupDetails(
      await this.#request('GET', '/group/details', { grpid }, 'http'),
    );
  }

  /**
   * All groups on the account.
   * @returns {Promise<models.GroupList>}  Requires the `groups` permission.
   */
  async listGroups() {
    return new models.GroupList(await this.#request('GET', '/group/list', {}, 'http'));
  }

  /**
   * Remove every number from a group, keeping the group.
   * @param {string|number} grpid
   * @returns {Promise<models.DeleteResult>}  Requires the `groups` permission.
   */
  async deleteGroupContents(grpid) {
    return new models.DeleteResult(
      await this.#request('DELETE', '/group/contents', { grpid }, 'http'),
    );
  }

  /**
   * Cost of sending to a whole group.
   *
   * Note the permission: pricing a send is an SMS operation, so this needs
   * **`sms`**, not `groups`.
   * @param {string|number} grpid
   * @param {string} [message]
   * @returns {Promise<models.GroupCost>}  Requires the `sms` permission.
   */
  async getGroupCost(grpid, message = '') {
    return new models.GroupCost(
      await this.#request('GET', '/group/cost', { grpid, message }, 'http'),
    );
  }

  /**
   * Send to every number in a group. Needs **`sms`**, not `groups`.
   * @param {string|number} grpid
   * @param {string} message
   * @param {Record<string, unknown>} [options]  Optional API fields, passed through.
   * @returns {Promise<models.SendResult>}  Requires the `sms` permission.
   */
  async sendGroupSms(grpid, message, options = /** @type {Record<string, unknown>} */ ({})) {
    const params = mergeOptions({ grpid, message }, options, ['grpid', 'message']);
    return new models.SendResult(await this.#request('PUT', '/group/send', params, 'http'));
  }
}

/**
 * Spread caller-supplied options over the named arguments, refusing options
 * that would silently overwrite them (e.g. a `dstaddr` inside `options`).
 * @param {Record<string, unknown>} base
 * @param {Record<string, unknown>} options
 * @param {string[]} reserved
 * @returns {Record<string, unknown>}
 */
function mergeOptions(base, options, reserved) {
  if (options === null || typeof options !== 'object' || Array.isArray(options)) {
    throw new TypeError('options must be an object');
  }
  for (const key of reserved) {
    if (key in options) {
      throw new TypeError(`options must not contain '${key}' - pass it as an argument`);
    }
  }
  return { ...base, ...options };
}

/**
 * The envelope's `status`: '0' is success; a missing value is also treated as
 * success, as the reference client does.
 * @param {unknown} status
 * @returns {boolean}
 */
function isSuccess(status) {
  return status === undefined || status === null || status === '0';
}

/**
 * Pull `scope="http"` out of a `WWW-Authenticate` header, if present.
 * @param {Record<string, string|string[]|undefined>} headers
 * @returns {string|null}
 */
function scopeFromHeaders(headers) {
  const value = headers['www-authenticate'];
  const challenges = Array.isArray(value) ? value.join(',') : (value ?? '');
  if (challenges === '') return null;
  for (const challenge of challenges.split(',')) {
    const part = challenge.trim();
    const match = /^scope=(?:"([^"]*)"|([^,\s]+))$/i.exec(part);
    if (match) return match[1] ?? match[2];
  }
  return null;
}

/**
 * Explain a transport failure without echoing the URL (a caller may have put
 * parameters in it) or anything that could carry credentials.
 * @param {unknown} cause
 * @returns {string}
 */
function describeFailure(cause) {
  if (cause instanceof Error) {
    if (cause.name === 'TimeoutError') return 'request timed out';
    if (cause.name === 'AbortError') return 'request aborted';
    // Errors from `node:http` carry a string `code` (ECONNREFUSED, ETIMEDOUT...).
    const tagged = /** @type {Error & { code?: string, cause?: { code?: string } }} */ (cause);
    const code = tagged.code ?? tagged.cause?.code;
    return code ? `${cause.message} (${code})` : cause.message;
  }
  return String(cause);
}