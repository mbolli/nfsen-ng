// Runs every tests/e2e/*.test.mjs file in sequence against a live app
// (default http://localhost:8080, override with BASE=...). Sequential on
// purpose: alerts.test.mjs mutates the real, shared preferences.json, and
// each test launches its own headless Chrome via CDP -- no need to race them.
import { readdirSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

const files = readdirSync(here)
    .filter((f) => f.endsWith('.test.mjs'))
    .sort();

let failed = 0;
const start = process.hrtime.bigint();

for (const file of files) {
    const name = file.replace(/\.test\.mjs$/, '');
    const { default: test } = await import(pathToFileURL(join(here, file)).href);
    const t0 = process.hrtime.bigint();
    try {
        await test();
        const ms = Number(process.hrtime.bigint() - t0) / 1e6;
        console.log(`ok   ${name} (${ms.toFixed(0)}ms)`);
    } catch (e) {
        failed++;
        const ms = Number(process.hrtime.bigint() - t0) / 1e6;
        console.error(`FAIL ${name} (${ms.toFixed(0)}ms)`);
        console.error(e);
    }
}

const totalMs = Number(process.hrtime.bigint() - start) / 1e6;
console.log(`\n${files.length - failed}/${files.length} passed (${totalMs.toFixed(0)}ms)`);
process.exit(failed ? 1 : 0);
