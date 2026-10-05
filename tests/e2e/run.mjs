// Runs tests/e2e/*.test.mjs, or the files named as arguments, one after another against BASE.
// Sequential on purpose: some tests mutate the shared preferences.json.
import { readdirSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { closeAllBrowsers, runAsFile } from './lib/cdp.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const all = readdirSync(here)
    .filter((f) => f.endsWith('.test.mjs'))
    .sort();

const wanted = process.argv.slice(2).map((a) => basename(a).replace(/\.test\.mjs$/, ''));
const unknown = wanted.filter((w) => !all.includes(`${w}.test.mjs`));
if (unknown.length) {
    console.error(`unknown e2e file(s): ${unknown.join(', ')}; there are ${all.map((f) => f.replace(/\.test\.mjs$/, '')).join(', ')}`);
    process.exit(2);
}
const files = wanted.length ? wanted.map((w) => `${w}.test.mjs`) : all;

const skipMutating = ['1', 'true', 'yes'].includes(String(process.env.E2E_SKIP_MUTATING ?? '').toLowerCase());
const fileTimeout = Number(process.env.E2E_FILE_TIMEOUT || 900) * 1000;

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
    let timer;
    try {
        await Promise.race([
            runAsFile(name, test),
            new Promise((_, reject) => {
                timer = setTimeout(() => reject(new Error(`${name} did not finish within ${fileTimeout / 1000} s`)), fileTimeout);
            }),
        ]);
        const ms = Number(process.hrtime.bigint() - t0) / 1e6;
        console.log(`ok   ${name} (${ms.toFixed(0)}ms)`);
    } catch (e) {
        failed++;
        // A hung file's browsers go now, and it may not start new ones while the next file runs.
        closeAllBrowsers(name);
        const ms = Number(process.hrtime.bigint() - t0) / 1e6;
        console.error(`FAIL ${name} (${ms.toFixed(0)}ms)`);
        console.error(e);
    } finally {
        clearTimeout(timer);
    }
}

const totalMs = Number(process.hrtime.bigint() - start) / 1e6;
const ran = files.length - skipped;
console.log(`\n${ran - failed}/${ran} passed${skipped ? `, ${skipped} skipped` : ''} (${totalMs.toFixed(0)}ms)`);
process.exit(failed ? 1 : 0);
