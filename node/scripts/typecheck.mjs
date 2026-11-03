#!/usr/bin/env node
/**
 * Type-check the package's JSDoc with a local TypeScript install.
 *
 * Not needed to use or test the package: it exists so the JSDoc that editors
 * rely on can be verified without adding TypeScript as a dependency. Run it
 * with a path to a TypeScript install:
 *
 *   node scripts/typecheck.mjs /path/to/node_modules/typescript/lib/typescript.js
 *
 * TypeScript and @types/node are optional and neither is installed by this
 * package, so diagnostics caused by Node built-in modules are counted and
 * reported rather than failed: the check targets the client's own types.
 */

import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const [, , typescriptPath = 'typescript'] = process.argv;
const require = createRequire(import.meta.url);
const ts = require(typescriptPath);

const root = path.dirname(fileURLToPath(new URL('../package.json', import.meta.url)));
const options = {
  allowJs: true,
  checkJs: true,
  noEmit: true,
  strict: true,
  skipLibCheck: true,
  module: ts.ModuleKind.NodeNext,
  moduleResolution: ts.ModuleResolutionKind.NodeNext,
  target: ts.ScriptTarget.ES2022,
  // Node's own declarations are not a dependency here.
  types: [],
  typeRoots: [],
};

/** Diagnostics explained by the absence of @types/node, not by this package. */
const needsNodeTypes = /node:[a-z]+|\bBuffer\b|implicitly has an 'any' type/;

const program = ts.createProgram([path.join(root, 'index.js')], options);
const all = ts.getPreEmitDiagnostics(program);
const problems = all.filter(
  (diagnostic) => !needsNodeTypes.test(ts.flattenDiagnosticMessageText(diagnostic.messageText, ' ')),
);

for (const diagnostic of problems) {
  const text = ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n');
  if (diagnostic.file) {
    const { line } = diagnostic.file.getLineAndCharacterOfPosition(diagnostic.start);
    console.log(`${path.relative(root, diagnostic.file.fileName)}:${line + 1}  ${text}`);
  } else {
    console.log(text);
  }
}

const ignored = all.length - problems.length;
console.log(problems.length === 0
  ? `JSDoc type check: clean (${ignored} diagnostic(s) need @types/node)`
  : `JSDoc type check: ${problems.length} problem(s)`);
process.exit(problems.length === 0 ? 0 : 1);