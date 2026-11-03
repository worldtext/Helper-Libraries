/**
 * The suite must skip cleanly, not fail, when python3 is absent.
 *
 * This re-runs the client suite in a child process with PATH stripped, so
 * `python3` cannot be found, and asserts that it reports skipped tests and a
 * zero exit code. Without python3 available here, this test is itself skipped.
 */

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { findPython } from './support/mock.js';

const SUITE = fileURLToPath(new URL('./client.test.js', import.meta.url));
const CWD = path.dirname(fileURLToPath(new URL('../package.json', import.meta.url)));
const python = findPython();

test('the suite skips when python3 is missing', { skip: python === null && 'python3 not found to run the check' },
  () => {
    // Env with a known-content PATH, minus anything named python*: no python3.
    const bin = fs.mkdtempSync(path.join(os.tmpdir(), 'wt-no-python-'));
    try {
      fs.mkdirSync(path.join(bin, 'python3'));   // shadow: a directory cannot be executed
      fs.mkdirSync(path.join(bin, 'python'));
      const env = {
        ...process.env,
        PATH: bin,
        PYTHON: '',
        // This test runs *inside* a runner; without this the child runner
        // declines to run files recursively and silently does nothing.
        NODE_TEST_CONTEXT: '',
      }; delete env.NODE_TEST_CONTEXT;

      const result = spawnSync(process.execPath, ['--test', '--test-reporter=tap', SUITE], {
        cwd: CWD,
        env,
        encoding: 'utf8',
        timeout: 60_000,
      });

      assert.equal(result.status, 0, `child exited ${result.status}:\n${result.stdout}\n${result.stderr}`);
      assert.equal(result.error, undefined);
      assert.doesNotMatch(result.stderr, /skipping running files/, 'child runner must actually run');
      // Every subtest - and so every assert in them - is reported skipped,
      // and the skip reason names the missing interpreter.
      for (const name of ['successful calls and typed parsing', 'typed errors', 'token redaction',
        'request wiring']) {
        assert.match(result.stdout, new RegExp(`^ok \\d+ - ${name} # SKIP python3 not found`, 'm'));
      }
      assert.doesNotMatch(result.stdout, /^not ok \d+/m);
      assert.doesNotMatch(result.stdout, /# fail [1-9]/);
    } finally {
      fs.rmSync(bin, { recursive: true, force: true });
    }
  });