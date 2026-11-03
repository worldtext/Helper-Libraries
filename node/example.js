#!/usr/bin/env node
/**
 * Runnable tour of the client.
 *
 *   node example.js                        # against the live API (needs WORLD_TEXT_TOKEN)
 *   node example.js http://127.0.0.1:PORT  # against a running mock gateway
 *
 * The mock accepts the token "test-all", e.g.:
 *
 *   python3 ../fixtures/mock_gateway.py 4242 &
 *   node example.js http://127.0.0.1:4242
 */

import {
  WorldText, AuthenticationError, PermissionError, ApiError, TransportError,
} from './index.js';

const baseUrl = process.argv[2];
const token = baseUrl ? 'test-all' : process.env.WORLD_TEXT_TOKEN;

if (!token) {
  console.error('set WORLD_TEXT_TOKEN, or pass the base URL of a mock gateway');
  process.exit(1);
}

const wt = new WorldText({ token, ...(baseUrl ? { baseUrl } : {}) });

// Log calls without seeing credentials: names, never values.
wt.events.addEventListener('request', ({ detail }) => {
  console.log(`  -> ${detail.method} ${detail.path} (${detail.keys.join(', ')})`);
});

try {
  console.log(String(wt));

  const credits = await wt.getCredits();
  console.log('credits:', credits.credits, `(type ${typeof credits.credits})`);

  const cost = await wt.getSmsCost('447989000001', 'Hello');
  console.log('cost:', cost.credits, `- as a decimal: ${cost.asDecimal}`);

  const sent = await wt.sendSms('447989000001', 'Hello from Node');
  console.log('sent:', sent.msgid, 'to', sent.messages[0].dstaddr);

  const status = await wt.querySmsStatus(sent.msgid ?? '');
  console.log('status:', status.state, status.final ? '(final)' : '(in flight)');

  const group = await wt.createGroup('example');
  console.log('group:', group.grpid);
  await wt.addGroupEntries(group.grpid ?? '', ['447989000001', '447989000002']);
  const details = await wt.getGroupDetails(group.grpid ?? '');
  console.log('group entries:', details.entries.map((entry) => entry.number).join(', '));
  console.log('group cost:', (await wt.getGroupCost(group.grpid ?? '')).credits);
  await wt.deleteGroup(group.grpid ?? '');
} catch (error) {
  if (error instanceof AuthenticationError) {
    console.error('the token was rejected; renew it');
  } else if (error instanceof PermissionError) {
    console.error(`the token lacks the '${error.requiredScope}' permission`);
  } else if (error instanceof ApiError) {
    console.error(`API error ${error.errorCode}: ${error.desc}`,
      error.retryable ? '(worth retrying)' : '(permanent)');
  } else if (error instanceof TransportError) {
    console.error('could not reach the gateway:', error.message);
  } else {
    throw error;
  }
  process.exitCode = 1;
}