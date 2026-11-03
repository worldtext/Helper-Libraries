/**
 * Unit tests for the small HTTP transport: deadlines, aborts and truncation.
 *
 * Each test runs a throwaway `node:http` server bound to an ephemeral port on
 * 127.0.0.1, so nothing leaves the machine and no ports are fixed.
 */

import assert from 'node:assert/strict';
import http from 'node:http';
import { after, before, describe, test } from 'node:test';

import { send } from '../lib/http.js';

let server;
let baseUrl;

before(async () => {
  server = http.createServer((req, res) => {
    if (req.url === '/slow') return;                 // never answers
    if (req.url === '/truncated') {
      // Promise 100 bytes, send 13, then hang up: a truncated body.
      res.writeHead(503, { 'Content-Type': 'application/json', 'Content-Length': '100' });
      res.write('{"status":"1",');
      setTimeout(() => res.socket.destroy(), 20);
      return;
    }
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ method: req.method, length: req.headers['content-length'], body }));
    });
  });
  await new Promise((resolve) => { server.listen(0, '127.0.0.1', resolve); });
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

after(async () => {
  server.closeAllConnections();
  await new Promise((resolve) => { server.close(resolve); });
});

/** @param {object} [overrides] */
function request(overrides = {}) {
  return send({
    url: `${baseUrl}/echo`,
    method: 'GET',
    headers: {},
    body: null,
    timeoutMs: 5000,
    signal: null,
    ...overrides,
  });
}

describe('http transport', () => {
  test('buffers the response and sends a byte-accurate Content-Length', async () => {
    // A non-ASCII body proves the length is in bytes, not characters.
    const body = 'message=héllo-£';
    const response = await request({ method: 'PUT', body, headers: { 'X-Test': '1' } });

    assert.equal(response.status, 200);
    const echoed = JSON.parse(response.body);
    assert.equal(echoed.method, 'PUT');
    assert.equal(echoed.body, body);
    assert.equal(Number(echoed.length), Buffer.byteLength(body));
  });

  test('a slow response times out within the deadline', async () => {
    const started = performance.now();
    const error = await request({ url: `${baseUrl}/slow`, timeoutMs: 100 }).then(
      () => assert.fail('expected the request to time out'),
      (failure) => failure,
    );
    const elapsed = performance.now() - started;

    assert.equal(error.name, 'TimeoutError');
    assert.match(error.message, /timed out/);
    assert.ok(elapsed < 2000, `took ${elapsed}ms`);
  });

  test('an aborted request rejects with the abort reason', async () => {
    const controller = new AbortController();
    setTimeout(() => controller.abort(new Error('shutdown')), 50);

    await assert.rejects(
      request({ url: `${baseUrl}/slow`, signal: controller.signal, timeoutMs: 5000 }),
      /shutdown/,
    );
  });

  test('an already-aborted signal rejects without connecting', async () => {
    const controller = new AbortController();
    controller.abort(new Error('already gone'));
    await assert.rejects(
      request({ url: 'http://127.0.0.1:9/', signal: controller.signal }),
      /already gone/,
    );
  });

  test('a body cut short keeps the status it had received', async () => {
    const error = await request({ url: `${baseUrl}/truncated` }).then(
      () => assert.fail('expected a truncated response to fail'),
      (failure) => failure,
    );
    assert.match(error.message, /aborted|closed before the response was complete/);
    assert.equal(error.httpStatus, 503);
  });

  test('a refused connection rejects with a code', async () => {
    await assert.rejects(
      request({ url: 'http://127.0.0.1:9/' }),   // discard port: nothing listens
      (error) => {
        assert.match(error.message, /ECONNREFUSED/);
        assert.equal(error.httpStatus, undefined, 'no status was received');
        return true;
      },
    );
  });
});