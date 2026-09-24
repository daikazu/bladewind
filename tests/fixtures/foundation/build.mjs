// Regenerates tests/fixtures/public-foundation from the Foundation for Sites npm package's own
// minified build (dist/css/foundation.min.css, the file most applications import and Vite ships as
// it is), so the Foundation driver's tests run against the real stylesheet.
//
//     cd tests/fixtures/foundation && npm install && npm run build
//
// The generated CSS and manifest are committed: they are test inputs, and the suite must not depend
// on Node being installed. node_modules stays out of the repository.

import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const version = require('foundation-sites/package.json').version;

const input = require.resolve('foundation-sites/dist/css/foundation.min.css');
const output = resolve(here, '../public-foundation/build/assets/app-foundation.css');
const css = await readFile(input, 'utf8');

await mkdir(dirname(output), { recursive: true });
await writeFile(output, css);
await writeFile(
    resolve(dirname(output), '../manifest.json'),
    `${JSON.stringify({ 'resources/css/app.css': { file: 'assets/app-foundation.css', src: 'resources/css/app.css', isEntry: true } }, null, 4)}\n`,
);

console.log(`wrote ${css.length} bytes of Foundation ${version} to ${output}`);
