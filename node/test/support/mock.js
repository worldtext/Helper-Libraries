/**
 * Start the shared mock gateway for the test run.
 *
 * The mock is a Python script (the language-neutral executable contract), so
 * these tests need `python3`. When it is absent the suite is skipped rather
 * than failed. The mock binds port 0 and prints "PORT <n>", so parallel runs
 * never collide, and it only ever listens on 127.0.0.1 - no network.
 */

import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';

export const MOCK_GATEWAY = fileURLToPath(
  new URL('../../../fixtures/mock_gateway.py', import.meta.url),
);

/**
 * @returns {string|null} The python3 executable, or null when unavailable.
 */
export function findPython() {
  const candidates = [process.env.PYTHON, 'python3'].filter(Boolean);
  for (const candidate of candidates) {
    try {
      const result = spawnSync(candidate, ['--version'], { stdio: 'ignore', timeout: 10_000 });
      if (result.status === 0) return candidate;
    } catch {
      // Try the next candidate.
    }
  }
  return null;
}

/**
 * Start the mock gateway on an ephemeral port.
 * @param {string} python  Executable from {@link findPython}.
 * @returns {Promise<{ baseUrl: string, close: () => Promise<void> }>}
 */
export async function startMock(python) {
  const child = spawn(python, [MOCK_GATEWAY, '0'], {
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  let stderr = '';
  child.stderr.on('data', (chunk) => { stderr += chunk; });

  const close = async () => {
    if (child.exitCode !== null || child.signalCode !== null) return;
    child.kill('SIGKILL');
    await once(child, 'exit');
  };

  let stdout = '';
  for await (const chunk of child.stdout) {
    stdout += chunk;
    const match = /^PORT (\d+)$/m.exec(stdout);
    if (match) {
      return { baseUrl: `http://127.0.0.1:${match[1]}`, close };
    }
  }
  // The stream ended without a port line, so the process is exiting.
  let code = child.exitCode;
  if (code === null) {
    ([code] = await once(child, 'exit'));
  }
  throw new Error(`mock gateway exited before reporting a port (code ${code}): ${stderr}`);
}