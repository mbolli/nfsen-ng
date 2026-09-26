// Runs every tests/e2e/*.test.mjs file in sequence against a live app
// (default http://localhost:8080, override with BASE=...). Sequential on
// purpose: some tests mutate the real, shared preferences.json, and each
// test launches its own headless Chrome via CDP -- no need to race them.
import { readdirSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

const files = readdirSync(here)
    .filter((f) => f.endsWith('.test.mjs'))
    .sort();

// A module that changes persisted state exports MUTATING = true; E2E_SKIP_MUTATING=1 skips it.
const skipMutating = ['1', 'true', 'yes'].includes(String(process.env.E2E_SKIP_MUTATING ?? '').toLowerCase());

let failed = 0;
let skipped = 0;
const start = process.hrtime.bigint();

for (const file of files) {
    const name = file.replace(/\.test\.mjs$/, '');
    const { default: test, MUTATING } = await import(pathToFileURL(join(here, file)).href);
    if (MUTATING === true && skipMutating) {
        skipped++;
        console.log(`skip ${name} (mutating, E2E_SKIP_MUTATING)`);
        continue;
    }
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
const ran = files.length - skipped;
console.log(`\n${ran - failed}/${ran} passed${skipped ? `, ${skipped} skipped` : ''} (${totalMs.toFixed(0)}ms)`);
process.exit(failed ? 1 : 0);
