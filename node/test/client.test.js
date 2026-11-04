/**
 * Tests for the Node client, run against the shared mock gateway.
 *
 * No network, no credentials: the mock is started as a child process on port 0
 * (see ./support/mock.js). Without python3 the whole file is skipped.
 */

import assert from 'node:assert/strict';
import { after, before, describe, test } from 'node:test';
import { inspect } from 'node:util';

import { startMock, findPython } from './support/mock.js';
import {
  ApiError,
  AuthenticationError,
  PermissionError,
  StatusValue,
  TransportError,
  WorldText,
  WorldTextError,
  redact,
} from '../index.js';

const python = findPython();
const skip = python === null
  ? 'python3 not found: the mock gateway cannot be started'
  : false;

/** Secrets that must never appear in rendered output. */
const SECRET = 'wt_live_supersecretvalue_that_must_never_leak';

let mock;
let baseUrl;

before(async () => {
  if (skip) return;
  mock = await startMock(python);
  baseUrl = mock.baseUrl;
});

after(async () => {
  await mock?.close();
});

/** @param {string} [token] */
function client(token = 'test-all', options = {}) {
  return new WorldText({ token, baseUrl, timeout: 5, ...options });
}

/**
 * Resolve the rejection of `promise`, failing if it resolves, and assert the
 * error is of exactly the given class.
 * @param {Promise<unknown>} promise
 * @param {Function} [type]
 */
async function rejection(promise, type) {
  try {
    await promise;
  } catch (error) {
    if (type) {
      assert.ok(error instanceof type, `expected ${type.name}, got ${error?.constructor?.name}: ${error}`);
    }
    return error;
  }
  assert.fail('expected the call to fail');
}

/**
 * Render an error the way a careless log sink would.
 * @param {Error} error
 */
function render(error) {
  return [String(error), error.message, error.stack ?? '', inspect(error, { depth: 5 }),
    JSON.stringify(error, Object.getOwnPropertyNames(error))].join('\n');
}

/**
 * Assert that no rendering of a value contains the literal bytes of the secret
 * (nor the part of it that is unique to it).
 */
function assertRedacted(value, where) {
  assert.doesNotMatch(String(value), /wt_live_supersecret/,
    `${where}: token appeared unredacted: ${String(value)}`);
}

// ---------------------------------------------------------------- successes

describe('successful calls and typed parsing', { skip }, () => {
  test('credits parse into typed fields and stay strings', async () => {
    const credits = await client().getCredits();

    assert.ok(credits instanceof Object);
    assert.equal(credits.credits, '2950');
    // Numbers arrive as strings; the helper converts only when asked.
    assert.equal(typeof credits.credits, 'string');
    assert.equal(credits.asDecimal, '2950');
    assert.equal(Number(credits.asDecimal), 2950);
    // The raw envelope is kept for anything not yet modelled.
    assert.deepEqual(credits.raw, { status: '0', credits: '2950' });
  });

  test('ping and host', async () => {
    const wt = client('test-admin');
    assert.equal((await wt.ping()).ok, true);
    assert.equal((await wt.getHost()).hostname, 'sms1.example');
  });

  test('sms cost is a decimal string', async () => {
    const cost = await client('test-sms').getSmsCost('447989000001', 'hi');
    assert.equal(cost.credits, '1.0');
    assert.equal(typeof cost.credits, 'string');
    assert.equal(cost.asDecimal, '1');
  });

  test('send parses msgid as a string', async () => {
    const result = await client('test-sms').sendSms('447989000001', 'hi');

    assert.equal(result.messages.length, 1);
    assert.equal(result.msgid, '10001');
    assert.equal(typeof result.msgid, 'string');
    assert.equal(result.messages[0].dstaddr, '447989000001');
    assert.equal(result.messages[0].status, '5');
  });

  test('sms status knows whether a message is final', async () => {
    const status = await client('test-sms').querySmsStatus('10001');
    assert.equal(status.state, '5');
    assert.equal(status.final, true);
  });

  test('group lifecycle', async () => {
    const wt = client('test-groups');

    assert.equal((await wt.createGroup('g')).grpid, '4242');
    assert.equal((await wt.addGroupEntry('4242', '447989000001')).added, '1');
    assert.equal(typeof (await wt.addGroupEntry('4242', '447989000001')).added, 'string');
    assert.equal((await wt.addGroupEntries('4242', ['447989000001', '447989000002'])).added, '2');

    const numbers = await wt.getGroupNumbers('4242');
    assert.deepEqual(numbers.numbers.sort(), ['447989000001', '447989000002']);

    const details = await wt.getGroupDetails('4242');
    assert.equal(details.entries.length, 2);
    assert.equal(details.entries[0].number, '447989000001');
    assert.equal(details.entries[0].status, '5');

    const [group] = (await wt.listGroups()).groups;
    assert.equal(group.grpid, '4242');
    assert.equal(group.name, 'test-group');
    assert.equal(group.size, '2');

    assert.equal((await wt.deleteGroupContents('4242')).deleted, '2');
    assert.equal((await wt.deleteGroup('4242')).deleted, null, 'destroy reports no row count');
    assert.equal((await wt.deleteGroupEntry('4242', '447989000001')).deleted, null);
  });

  test('group id is accepted as grpid or groupid', async () => {
    const wt = client('test-groups');

    // The create route returns the same id under both names...
    const created = await wt.createGroup('g');
    assert.equal(created.grpid, '4242');
    assert.equal(created.raw.groupid, '4242');

    // ...and the documented alternative name is read when it is the only one.
    const { Group } = await import('../index.js');
    assert.equal(new Group({ groupid: '77', name: 'x' }).grpid, '77');
    assert.equal(new Group({ grpid: '88', groupid: '77' }).grpid, '88');
  });

  test('addGroupEntries accepts any iterable and rejects a bare string', async () => {
    const wt = client('test-groups');
    assert.equal((await wt.addGroupEntries('4242', new Set(['1', '2']))).added, '2');
    await assert.rejects(() => wt.addGroupEntries('4242', '447989000001'), TypeError);
  });

  test('a credential without the http interface is refused everywhere', async () => {
    // Scopes name interfaces, so there is no per-operation boundary to test:
    // an email-only credential is refused for reads and sends alike.
    assert.equal((await client('test-http').getGroupCost('4242')).credits, '2.0');
    const sent = await client('test-http').sendGroupSms('4242', 'hi');
    assert.equal(sent.msgid, '10002');

    const wrongScope = await rejection(client('test-email').getGroupCost('4242'), PermissionError);
    assert.equal(wrongScope.requiredScope, 'http');
    await rejection(client('test-email').sendGroupSms('4242', 'hi'), PermissionError);
    await rejection(client('test-email').sendSms('447989000001', 'hi'), PermissionError);
    // A credential carrying no interfaces at all is refused too.
    await rejection(client('test-none').sendSms('447989000001', 'hi'), PermissionError);
  });

  test('hlr lookups', async () => {
    const wt = client('test-hlr');
    assert.equal((await wt.lookupHlr('447989000001')).region, 'UK');
    assert.equal((await wt.lookupMnp('447989000001')).mnpportstatus, 'ported');
    const info = await wt.getNumberInfo('447989000001');
    assert.equal(info.network, 'O2-UK');
    assert.equal(info.mcc, '234');
  });

  test('options are passed through but cannot shadow arguments', async () => {
    const wt = client('test-sms');
    // Optional fields reach the gateway without the client enumerating them.
    assert.equal((await wt.sendSms('447989000001', 'hi', { sender: 'WT', sendid: 'x' })).msgid, '10001');
    await assert.rejects(() => wt.sendSms('447989000001', 'hi', { dstaddr: 'other' }), TypeError);
  });

  test('models serialise to their typed fields', async () => {
    const credits = await client().getCredits();
    assert.deepEqual(JSON.parse(JSON.stringify(credits)), { credits: '2950' });
    const other = await client().getCredits();
    assert.equal(credits.equals(other), true);
    const host = await client().getHost();
    assert.equal(credits.equals(host), false);
  });
});

// ------------------------------------------------------------------- errors

describe('typed errors', { skip }, () => {
  test('a bad token is 401', async () => {
    const error = await rejection(client('not-a-token').getCredits(), AuthenticationError);
    assert.equal(error.httpStatus, 401);
    assert.equal(error.errorCode, '1000');
    assert.equal(error.desc, 'Authorisation Failure');
    assert.equal(error.retryable, false);
    assert.match(error.message, /HTTP 401/);
  });

  test('a wrong interface is 403 and exposes the required scope', async () => {
    const error = await rejection(client('test-email').getCredits(), PermissionError);
    assert.equal(error.httpStatus, 403);
    assert.ok(error instanceof WorldTextError);
    // The required interface is surfaced so the caller can say which one to add.
    assert.equal(error.requiredScope, 'http');
    // ...and not confused with the gateway's 1000 code in the envelope.
    assert.equal(error.errorCode, '1000');
  });

  test('every operation reports the scope it needs', async () => {
    // One call per operation with a token that has the wrong permission.
    const cases = [
      ['admin', (wt) => wt.ping()],
      ['admin', (wt) => wt.getHost()],
      ['sms', (wt) => wt.sendSms('447989000001', 'hi')],
      ['sms', (wt) => wt.getSmsCost('447989000001')],
      ['sms', (wt) => wt.querySmsStatus('1')],
      ['sms', (wt) => wt.getGroupCost('4242')],
      ['sms', (wt) => wt.sendGroupSms('4242', 'hi')],
      ['groups', (wt) => wt.createGroup('g')],
      ['groups', (wt) => wt.listGroups()],
      ['groups', (wt) => wt.deleteGroup('4242')],
      ['hlr', (wt) => wt.lookupHlr('447989000001')],
      ['hlr', (wt) => wt.lookupMnp('447989000001')],
      ['hlr', (wt) => wt.getNumberInfo('447989000001')],
    ];
    // Every operation names the same interface: the vocabulary has no finer
    // granularity, so this checks that all of them are gated at all.
    for (const [, call] of cases) {
      const error = await rejection(call(client('test-email')), PermissionError);
      assert.equal(error.requiredScope, 'http', `${call} should need the http interface`);
    }
  });

  test('a missing argument is a 400', async () => {
    const error = await rejection(client('test-sms').getSmsCost(''), ApiError);
    assert.equal(error.httpStatus, 400);
    assert.equal(error.errorCode, '1200');
    assert.equal(error.retryable, false);
    assert.ok(!(error instanceof AuthenticationError));
    assert.ok(!(error instanceof PermissionError));
  });

  test('all errors share a base class', async () => {
    for (const token of ['nope', 'test-email']) {
      const error = await rejection(client(token).getCredits(), WorldTextError);
      assert.equal(error instanceof WorldTextError, true);
      assert.equal(error.name, token === 'nope' ? 'AuthenticationError' : 'PermissionError');
    }
  });

  test('branching works without string matching', async () => {
    /** Mimic a caller's error handler. */
    const handle = (error) => {
      if (error instanceof AuthenticationError) return 'renew-token';
      if (error instanceof PermissionError) return `add-interface:${error.requiredScope}`;
      if (error.retryable) return 'retry';
      return 'fail';
    };

    assert.equal(handle(await rejection(client('nope').getCredits())), 'renew-token');
    assert.equal(handle(await rejection(client('test-email').getCredits())), 'add-interface:http');
    assert.equal(handle(await rejection(client('test-sms').getSmsCost(''))), 'fail');
  });

  test('the retryable accessor follows the status flag and 5xx', () => {
    assert.deepEqual([...StatusValue.TEMPORARY], ['2', '3']);
    assert.equal(new ApiError('t', { httpStatus: 200, statusFlag: '2' }).retryable, true);
    assert.equal(new ApiError('t', { httpStatus: 200, statusFlag: '3' }).retryable, true);
    assert.equal(new ApiError('p', { httpStatus: 200, statusFlag: '1' }).retryable, false);
    assert.equal(new ApiError('s', { httpStatus: 503 }).retryable, true);
    assert.equal(new ApiError('c', { httpStatus: 400 }).retryable, false);
    assert.equal(new ApiError('n', { httpStatus: 429 }).retryable, true);
    // No HTTP status at all means the request never got a reply: worth a retry.
    assert.equal(new WorldTextError('default').retryable, true);
    assert.equal(new TransportError('down').retryable, true);
    // An error that arrived with a 4xx is permanent whatever the order of events.
    const error = new ApiError('frozen', { httpStatus: 400 });
    assert.equal(Object.isFrozen(error), false, 'callers may annotate');
    assert.equal(error.statusFlag, '1', 'defaults to permanent');
  });

  test('constructor-side interface checks refuse before any request', async () => {
    let requests = 0;
    const wt = client('test-email', { permissions: ['email'] });
    wt.events.addEventListener('request', () => { requests += 1; });

    const error = await rejection(wt.getCredits(), PermissionError);
    assert.equal(error.requiredScope, 'http');
    assert.equal(error.httpStatus, 403);
    assert.equal(error.desc, 'insufficient_scope');
    assert.equal(requests, 0, 'no request should have been sent');

    // Declaring the interface lets the call through.
    const declared = client('test-http', { permissions: ['http'] });
    assert.equal((await declared.getGroupCost('4242')).credits, '2.0');
    // ...and declaring it is not a bypass. This credential claims `http` but the
    // gateway knows it carries only `email`, so the refusal is the server's.
    const lying = client('test-email', { permissions: ['http'] });
    const remote = await rejection(lying.getGroupCost('4242'), PermissionError);
    assert.equal(remote.requiredScope, 'http');
  });

  test('constructor validation', () => {
    assert.throws(() => new WorldText(), TypeError);
    assert.throws(() => new WorldText({ token: '' }), TypeError);
    assert.throws(() => new WorldText({ token: 'x', timeout: 0 }), RangeError);
  });
});

// ------------------------------------------------------------ token safety

describe('token redaction', { skip }, () => {
  test('redact() describes a token without revealing it', () => {
    assert.equal(redact('abcdefgh'), '<redacted:8 chars>');
    assert.equal(redact(''), '<none>');
    assert.equal(redact(undefined), '<none>');
  });

  test('toString, String() and inspect redact the token', () => {
    const wt = client(SECRET);
    const rendered = [String(wt), wt.toString(), inspect(wt), inspect({ wt }, { depth: 3 }),
      `${wt}`, inspect(wt, { showHidden: true, depth: 5 })];
    for (const text of rendered) {
      assertRedacted(text, 'client rendering');
      assert.match(text, /WorldText\(baseUrl=.*token=<redacted:\d+ chars>\)/);
    }
  });

  test('error messages and inspect redact the token', async () => {
    const error = await rejection(client(SECRET).getCredits(), WorldTextError);
    assertRedacted(render(error), 'error rendering');
    assertRedacted(JSON.stringify(error), 'JSON.stringify(error)');
    // The message still says what happened.
    assert.match(error.message, /Authorisation Failure/);
    assert.match(error.message, /HTTP 401/);
  });

  test('transport errors redact the token and hide the URL', async () => {
    // Port 9 (discard) on loopback refuses connections: no network is touched.
    const wt = client(SECRET, { baseUrl: 'http://127.0.0.1:9/v2.0' });
    const started = performance.now();
    const error = await rejection(wt.getSmsCost('447989000001', 'hi'), TransportError);
    assert.equal(error.httpStatus, null);
    assert.equal(error.desc?.includes('ECONNREFUSED'), true, `unexpected desc: ${error.desc}`);
    assert.equal(error.retryable, true);
    assert.equal(error.path, '/sms/cost');
    const rendered = render(error);
    assertRedacted(rendered, 'transport error rendering');
    // Query parameters are not echoed either, only the base URL and path.
    assert.doesNotMatch(rendered, /447989000001/);
    assert.match(error.message, /^transport failure contacting http:\/\/127\.0\.0\.1:9\/v2\.0: /);
    assert.ok(performance.now() - started < 5000, 'a refused connection fails fast');
  });

  test('debug events carry argument names, never values', async () => {
    const wt = client(SECRET);
    /** @type {string[]} */
    const seen = [];
    for (const type of ['request', 'error']) {
      wt.events.addEventListener(type, (event) => seen.push(JSON.stringify(event.detail)));
    }

    const error = await rejection(wt.sendSms('447989000001', 'a private message body'));

    assert.equal(seen.length, 2, 'expected one request event and one error event');
    for (const payload of seen) {
      assertRedacted(payload, 'event detail');
      assert.doesNotMatch(payload, /447989000001|a private message body/);
    }
    assert.match(seen[0], /"method":"PUT"/);
    assert.match(seen[0], /"keys":\["dstaddr","message"\]/);
    assert.match(seen[1], /"httpStatus":401/);
    // The error itself is still available to the listener for structured logging.
    assert.ok(seen.length === 2 && error instanceof AuthenticationError);
  });
});

// ------------------------------------------------------------------ wiring

describe('request wiring', { skip }, () => {
  test('write routes accept PUT and POST with a form body', async () => {
    const response = await fetch(`${baseUrl}/sms/send`, {
      method: 'POST',
      headers: { Authorization: 'Bearer test-sms', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ dstaddr: '447989000001', message: 'hi' }).toString(),
    });
    assert.equal(response.status, 200);
    const payload = await response.json();
    assert.equal(payload.result[0].dstaddr, '447989000001');
  });

  test('no query-string credentials are sent', async () => {
    // The client authenticates with a bearer header only; a request without one is refused.
    const response = await fetch(`${baseUrl}/admin/credits?id=x&key=y`);
    assert.equal(response.status, 401);
    // Read the body so the (keep-alive) connection is released at once.
    assert.equal((await response.json()).error, '1000');
  });
});