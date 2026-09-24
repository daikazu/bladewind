// Regenerates tests/fixtures/public-tachyons from the Tachyons npm package's own minified build —
// the file an application imports and Vite ships as it is — so the Tachyons driver's tests run
// against the real stylesheet rather than a hand-written approximation of it. (The unminified
// source keeps its custom media queries unresolved, which a second minifier refuses.)
//
//     cd tests/fixtures/tachyons && npm install && npm run build
//
// The generated CSS and manifest are committed: they are test inputs, and the suite must not depend
// on Node being installed. node_modules stays out of the repository.

import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const version = require('tachyons/package.json').version;

const input = require.resolve('tachyons/css/tachyons.min.css');
const output = resolve(here, '../public-tachyons/build/assets/app-tachyons.css');
const css = await readFile(input, 'utf8');

await mkdir(dirname(output), { recursive: true });
await writeFile(output, css);
await writeFile(
    resolve(dirname(output), '../manifest.json'),
    `${JSON.stringify({ 'resources/css/app.css': { file: 'assets/app-tachyons.css', src: 'resources/css/app.css', isEntry: true } }, null, 4)}\n`,
);

console.log(`wrote ${css.length} bytes of Tachyons ${version} to ${output}`);
