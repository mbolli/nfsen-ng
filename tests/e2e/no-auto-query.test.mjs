// The binding rule of the redesign: nothing reads capture files on its own (REQUIREMENTS, spec
// 5.5). On Overview (stored mode), Top Talkers, Flows and Conversations the range preset, the
// protocol and the sources change, a live tick passes and the graph refreshes; no action that
// runs nfdump over capture files may be posted, no query may start, and no run may be recorded.
// query_runs is shared by every client, so run this against an otherwise idle instance.
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { withPage, BASE } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Every action whose work is an nfdump run over capture files (QueryRunner, the filtered
// builds, imports and the top-N fill).
const READS_CAPTURE_FILES = [
    'stats-actions',
    'talkers-panel',
    'flow-actions',
    'flows-summary-run',
    'build-flows-graph',
    'conversations-run',
    'run-filtered-graph',
    'overview-topn-run',
    'trigger-import',
    'backfill-import',
    'force-rescan',
    'topn-fill',
];

// The dev stack keeps its state in the mounted tree; NFSEN_SQLITE points elsewhere.
const SQLITE = process.env.NFSEN_SQLITE ?? join(dirname(fileURLToPath(import.meta.url)), '../../backend/settings/nfsen-ng.sqlite');
const SKIP_RUNS = ['1', 'true', 'yes'].includes(String(process.env.E2E_SKIP_QUERY_RUNS ?? '').toLowerCase());

/**
 * The runs recorded after the id `after`, or without it the newest id: record() prunes each kind
 * to 200 rows, so only the id shows a new run. node:sqlite loads here, so the skip works on any Node.
 */
async function recordedRuns(after = null) {
    if (SKIP_RUNS) return null;
    assert.ok(existsSync(SQLITE), `the store ${SQLITE} is not readable here: set NFSEN_SQLITE to it, or E2E_SKIP_QUERY_RUNS=1`);
    const { DatabaseSync } = await import('node:sqlite');
    const db = new DatabaseSync(SQLITE, { readOnly: true });
    try {
        if (after === null) return Number(db.prepare('SELECT COALESCE(MAX(id), 0) AS id FROM query_runs').get().id);
        return db
            .prepare('SELECT id, kind, ts FROM query_runs WHERE id > ? ORDER BY id')
            .all(after)
            .map((row) => `${row.id} ${row.kind} at ${new Date(Number(row.ts) * 1000).toISOString()}`);
    } finally {
        db.close();
    }
}

/** A real change of the source set: one box less with two sources or more, else 'any' for the list and back. */
async function changeSources(page) {
    const boxes = await page.evaluate(`document.querySelectorAll('#sourcesMenuList input[name=globalSource]').length`);
    if (boxes > 1) {
        await page.evaluate(`document.querySelector('#sourcesMenu .menu-toggle').click()`);
        await page.waitFor(`!!document.querySelector('#sourcesMenuList input[name=globalSource]')?.getClientRects().length`, {
            label: 'the sources menu',
        });
        await page.evaluate(`[...document.querySelectorAll('#sourcesMenuList input[name=globalSource]')].at(-1).click()`);
        await page.evaluate(`document.querySelector('#sourcesMenu .menu-toggle').click()`);
        return;
    }
    const change = await page.evaluate(`(async function(){
        var src = document.querySelector('script[type=module][src*="/js/datastar.js"]').src;
        var root = (await import(src)).root;
        var key = Object.keys(root).find(function(k){ return k.startsWith('graph_sources____'); });
        var before = JSON.stringify(root[key]);
        var configured = [...document.querySelectorAll('#sourcesMenuList input[name=globalSource]')].map(function(b){ return b.value; });
        root[key] = root[key].includes('any') ? configured : ['any'];
        document.getElementById('sourcesMenuList').dispatchEvent(new Event('change', { bubbles: true }));
        return [before, JSON.stringify(root[key])];
    })()`);
    assert.notEqual(change[1], change[0], `graph_sources changed: ${change.join(' -> ')}`);
}

/** Samples query_running in the page every 100 ms, so a run between two checks is still seen. */
async function watchQueryRunning(page) {
    await page.evaluate(`(async function(){
        var src = document.querySelector('script[type=module][src*="/js/datastar.js"]').src;
        var root = (await import(src)).root;
        window.__queryRan = false;
        window.__queryWatch = setInterval(function(){
            var key = Object.keys(root).find(function(k){ return k.startsWith('query_running____'); });
            if (key && root[key] === true) window.__queryRan = true;
        }, 100);
    })()`);
}

async function changeGlobals(page, log, preset) {
    await page.setRangePreset(preset);

    const posted = log.count('apply-globals');
    const protocol = await page.evaluate(`document.getElementById('protocolSelect').value`);
    await page.setSelectValue('#protocolSelect', protocol === 'tcp' ? 'udp' : 'tcp');

    await changeSources(page);
    const start = Date.now();
    while (log.count('apply-globals') < posted + 2 && Date.now() - start < 5000) await sleep(100);
    assert.ok(log.count('apply-globals') >= posted + 2, `protocol and sources post apply-globals: ${log.names().join(', ')}`);
}

function forbidden(log) {
    return log.names().filter((name) => READS_CAPTURE_FILES.includes(name));
}

export default async function noAutoQueryTest() {
    const runBefore = await recordedRuns();

    await withPage(async (page) => {
        const log = await page.requestLog();
        await page.navigate(BASE + '/#/overview');
        await page.waitForBoot();
        await page.waitForPage('overview');
        assert.equal(await page.signalValue('graph_mode'), 'stored', 'a fresh tab shows the stored graph');
        await watchQueryRunning(page);

        const presets = { overview: '7d', talkers: '30d', flows: '24h', conversations: '7d' };
        for (const [id, preset] of Object.entries(presets)) {
            await page.gotoPage(id);
            await changeGlobals(page, log, preset);
            assert.deepEqual(forbidden(log), [], `${id}: nothing read capture files`);
        }

        // Live: the tick posts refresh-graphs within 60 s; the render it causes moves the window.
        await page.setRangePreset('24h');
        assert.equal(await page.signalValue('range_live'), true, 'a preset is live');
        const ticks = log.count('refresh-graphs');
        const start = Date.now();
        while (log.count('refresh-graphs') === ticks && Date.now() - start < 65_000) await sleep(500);
        assert.ok(log.count('refresh-graphs') > ticks, 'a live tick posted refresh-graphs within 65 s');

        // Overview's own refresh: a change inside the options panel posts refresh-graphs.
        await page.gotoPage('overview');
        const refreshes = log.count('refresh-graphs');
        await page.evaluate(`document.getElementById('filterDisplaySelect').dispatchEvent(new Event('change', { bubbles: true }))`);
        const posted = Date.now();
        while (log.count('refresh-graphs') === refreshes && Date.now() - posted < 5000) await sleep(100);
        assert.ok(log.count('refresh-graphs') > refreshes, 'the graph options posted refresh-graphs');
        await sleep(1500);

        assert.deepEqual(forbidden(log), [], `no capture-reading action was posted: ${log.names().join(', ')}`);
        assert.equal(await page.evaluate('window.__queryRan'), false, 'query_running never became true');
        assert.equal(await page.signalValue('graph_mode'), 'stored', 'the graph stayed in stored mode');
        assert.deepEqual(page.realErrors(), [], 'no console errors');
    });

    if (runBefore === null) {
        console.log('  (no-auto-query: E2E_SKIP_QUERY_RUNS is set, query_runs is not checked)');
    } else {
        const added = await recordedRuns(runBefore);
        assert.deepEqual(added, [], `query_runs gained rows (from this test, or from another client of the instance): ${added.join('; ')}`);
    }
}

if (import.meta.url === `file://${process.argv[1]}`) {
    noAutoQueryTest()
        .then(() => console.log('no-auto-query: PASS'))
        .catch((e) => {
            console.error('no-auto-query: FAIL\n', e);
            process.exit(1);
        });
}
