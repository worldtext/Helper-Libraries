/**
 * Minimal HTTP/1.1 transport with deadlines that do not outlive the request.
 *
 * Why not `fetch`: `AbortSignal.timeout(n)` arms a timer for the *full*
 * timeout, and that timer keeps the event loop alive even when the response
 * arrives in a millisecond. A short script doing one call would linger for the
 * timeout before exiting, and a test suite pays it on every request. A socket
 * deadline (`socket.setTimeout`) is cleared the moment the request finishes,
 * so a script exits as soon as its work is done.
 *
 * Request bodies are sent with `Content-Length` rather than chunked encoding:
 * the mock gateway - like a number of simple proxies - does not decode chunked
 * bodies and would see an empty form.
 *
 * One connection per request keeps this small. A pooled agent would be the next
 * step if throughput mattered.
 */

import http from 'node:http';
import https from 'node:https';

/**
 * @typedef {object} HttpRequest
 * @property {string} url          Absolute URL.
 * @property {'GET'|'PUT'|'POST'|'DELETE'} method
 * @property {Record<string, string>} headers
 * @property {string|null} body
 * @property {number} timeoutMs    Deadline for connect, send and read, together.
 * @property {AbortSignal|null} signal
 */

/**
 * @typedef {object} HttpResponse
 * @property {number} status
 * @property {string} body
 * @property {Record<string, string|string[]|undefined>} headers
 */

/**
 * Mark a failure that happened part-way through a response with the status
 * line already received, so the caller can still tell a 5xx from a 4xx.
 * @param {Error} error
 * @param {any} res  An http.IncomingMessage (loosely typed so the file needs no @types/node).
 * @returns {Error}
 */
function withStatus(error, res) {
  return Object.assign(error, { httpStatus: res.statusCode });
}

/**
 * Perform one request and buffer the whole response body.
 *
 * Resolves with the response whatever its status; rejects on connection
 * failure, timeout, abort, or a truncated body.
 * @param {HttpRequest} request
 * @returns {Promise<HttpResponse>}
 */
export function send({ url, method, headers, body, timeoutMs, signal }) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);

    if (signal?.aborted) {
      reject(signal.reason ?? new Error('request aborted'));
      return;
    }

    let settled = false;
    /** @param {Error|undefined} error @param {HttpResponse} [response] */
    const finish = (error, response) => {
      if (settled) return;
      settled = true;
      signal?.removeEventListener('abort', onAbort);
      if (response === undefined) reject(error ?? new Error('request failed'));
      else resolve(response);
    };

    const requestHeaders = { ...headers };
    if (body !== null) {
      requestHeaders['Content-Length'] = String(Buffer.byteLength(body));
    }

    const transport = target.protocol === 'https:' ? https : http;
    const req = transport.request(target, {
      method,
      headers: requestHeaders,
      // A redirect would replay the request - with its bearer header - at a
      // URL the caller never named, so requests are not followed ('error').
      // Each request gets its own connection; see the note at the top.
      maxRedirects: 0,
      agent: false,
    });

    const onAbort = () => req.destroy(signal?.reason ?? new Error('request aborted'));
    signal?.addEventListener('abort', onAbort, { once: true });

    let timedOut = false;
    req.setTimeout(timeoutMs, () => {
      timedOut = true;
      req.destroy(Object.assign(new Error('request timed out'), { code: 'ETIMEDOUT' }));
    });

    req.on('error', (error) => {
      // 'socket hang up' with no status line: the connection failed outright.
      finish(timedOut ? Object.assign(error, { name: 'TimeoutError' }) : error);
    });
    let response;
    req.on('response', (res) => {
      response = res;
      /** @type {Buffer[]} */
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('error', (error) => finish(withStatus(error, res)));
      res.on('end', () => {
        if (!res.complete) {
          finish(withStatus(
            new Error('connection closed before the response was complete'), res,
          ));
          return;
        }
        finish(undefined, {
          status: res.statusCode ?? 0,
          headers: res.headers,
          body: Buffer.concat(chunks).toString('utf8'),
        });
      });
    });

    if (body !== null) req.write(body);
    req.end();
  });
}