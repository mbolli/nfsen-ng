// Health (4.7): the import card with its per-profile controls, capture sources, disk usage,
// the system facts, the grouped checks with the SQLite group, and the recent log with its
// client-side level filter. The controls are walked with the keyboard only. Trigger imports
// the test profile's new files, which the daemon would do anyway; E2E_SKIP_MUTATING skips it.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const skipMutating = ['1', 'true', 'yes'].includes(String(process.env.E2E_SKIP_MUTATING ?? '').toLowerCase());

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const KEYS = {
    ArrowRight: { code: 'ArrowRight', vk: 39 },
    ArrowLeft: { code: 'ArrowLeft', vk: 37 },
    Escape: { code: 'Escape', vk: 27 },
    Tab: { code: 'Tab', vk: 9 },
    Enter: { code: 'Enter', vk: 13, text: '\r' },
    ' ': { code: 'Space', vk: 32, text: ' ' },
};

/** Press a key on whatever has focus, the way a keyboard does; Enter and Space also type. */
async function press(page, key, { shift = false } = {}) {
    const { code, vk, text } = KEYS[key];
    const base = { key, code, windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk, modifiers: shift ? 8 : 0 };
    await page.send(
        'Input.dispatchKeyEvent',
        text ? { type: 'keyDown', text, unmodifiedText: text, ...base } : { type: 'rawKeyDown', ...base }
    );
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

const focusedName = `(() => { const a = document.activeElement; return a?.id || a?.getAttribute('aria-label') || a?.textContent.trim().slice(0, 40) || a?.tagName || ''; })()`;

/** Press Tab until the focus is on `selector`; returns the names of the stops. */
async function tabTo(page, selector, { max = 30, shift = false } = {}) {
    const path = [];
    for (let i = 0; i < max; i++) {
        await press(page, 'Tab', { shift });
        path.push(await page.evaluate(focusedName));
        if (await page.evaluate(`!!document.activeElement?.matches(${JSON.stringify(selector)})`)) return path;
    }
    assert.fail(`Tab never reached ${selector}, went through ${path.join(' > ')}`);
}

/** The keyboard walk starts at the Import heading, which is where an import start leaves the focus. */
async function fromImportHeading(page, selector) {
    await page.evaluate(`document.getElementById('healthImportTitle').focus()`);
    return tabTo(page, selector);
}

/** The log rows with their level, whether the filter shows them, and the filter itself. */
const logSnapshot = `(() => {
    const rows = [...document.querySelectorAll('#healthLog tbody tr[data-level]')];
    const filter = document.getElementById('healthLog').dataset.filter;
    return {
        filter,
        rows: rows.map((r) => ({ level: r.dataset.level, shown: r.getClientRects().length > 0, fixture: 'e2e' in r.dataset })),
        empty: document.querySelector('#healthLog tr[data-empty="' + filter + '"]').getClientRects().length > 0,
    };
})()`;

const passes = { all: () => true, warning: (l) => l === 'warning' || l === 'error', error: (l) => l === 'error' };

/** Exactly the rows of the filter's levels are visible, and an empty filter says so. */
function assertFilter(snap, filter) {
    assert.equal(snap.filter, filter, 'the filter the keys selected');
    for (const row of snap.rows) {
        assert.equal(row.shown, passes[filter](row.level), `filter ${filter} and a ${row.level} row: ${JSON.stringify(snap.rows)}`);
    }
    assert.equal(snap.empty, !snap.rows.some((r) => passes[filter](r.level)), `filter ${filter} says when nothing matches`);
}

// The dev log rarely holds a warning or an error, so one of each is added for the check. A
// refresh morph removes them again, and they are then added back.
const addFixtures = `(() => {
    const body = document.querySelector('#healthLog tbody');
    for (const level of ['warning', 'error']) {
        const tr = document.createElement('tr');
        tr.dataset.level = level;
        tr.dataset.e2e = '';
        tr.innerHTML = '<td></td><td>' + level + '</td><td>e2e fixture</td>';
        body.insertBefore(tr, body.firstElementChild);
    }
})()`;

async function snapshotWithFixtures(page) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        const snap = await page.evaluate(logSnapshot);
        if (snap.rows.filter((r) => r.fixture).length === 2) return snap;
        await page.evaluate(`document.querySelectorAll('#healthLog tr[data-e2e]').forEach((r) => r.remove())`);
        await page.evaluate(addFixtures);
    }
    assert.fail('the fixture log rows kept disappearing');
}

/** Arrow to the next level and wait for the card to take it. */
async function arrowTo(page, key, filter) {
    await press(page, key);
    await page.waitFor(`document.getElementById('healthLog').dataset.filter === ${JSON.stringify(filter)}`, {
        label: `the ${filter} filter`,
    });
}

async function walkHealth(page) {
    await page.navigate(BASE + '/');
    await page.waitForBoot();
    await page.gotoPage('health');
    // Gone after a reload, which tells a dev-app restart apart from a real failure.
    await page.evaluate(`window.__healthRun = true`);
    // The first open after a start renders before the checks and metrics land.
    await page.waitFor(`!document.querySelector('#page-health .health[aria-busy]')`, { timeout: 15000, label: 'the first health refresh' });

    // The cards of 2.7.9.
    for (const id of ['healthImport', 'healthSources', 'healthDisks', 'healthSystem', 'healthChecks', 'healthLog']) {
        assert.ok(await page.evaluate(`!!document.querySelector('#page-health #${id}')`), `#${id} is on the page`);
    }

    // Import controls per profile, with the Dirs watched column of the old multi-profile table.
    const profiles = await page.evaluate(
        `[...document.querySelectorAll('#healthImport tbody th[scope=row]')].map((th) => th.textContent.trim())`
    );
    assert.ok(profiles.length > 0, 'the import card lists the daemon profiles');
    const importHead = await page.evaluate(`document.querySelector('#healthImport thead').textContent`);
    assert.match(importHead, /Dirs watched/, 'the profile table has the Dirs watched column');
    const triggerOf = (profile) => `#healthImport button[aria-label="Trigger import for ${profile}"]`;
    for (const profile of profiles) {
        assert.ok(await page.evaluate(`!!document.querySelector('${triggerOf(profile)}')`), `profile ${profile} has a Trigger button`);
    }

    // Capture sources: one row per profile and source, the newest file and a state.
    const sourcesHead = await page.evaluate(`document.querySelector('#healthSources thead').textContent`);
    assert.match(sourcesHead, /Pending \(7 d\)/, 'the sources table counts pending files over 7 days');
    const sourceRows = await page.evaluate(`[...document.querySelectorAll('#healthSources tbody tr')].map((tr) => ({
            profile: tr.cells[0]?.textContent.trim(),
            newest: tr.cells[2]?.textContent.trim(),
            state: tr.querySelector('.badge')?.textContent.trim(),
        }))`);
    for (const profile of profiles) {
        assert.ok(
            sourceRows.some((r) => r.profile === profile),
            `profile ${profile} has a capture source row, got ${JSON.stringify(sourceRows)}`
        );
    }
    for (const row of sourceRows) {
        assert.match(row.state ?? '', /^(Healthy|Stale|No data|Missing)$/, `a source row carries a state: ${JSON.stringify(row)}`);
        if (row.state === 'Healthy' || row.state === 'Stale') {
            assert.match(row.newest, /^nfcapd\.\d{12}$/, `a live source names its newest file: ${JSON.stringify(row)}`);
        }
    }

    // One meter per measured filesystem, with its value and a name.
    const meters = await page.evaluate(`[...document.querySelectorAll('#healthDisks .disk')].map((li) => {
            const m = li.querySelector('.usage-bar[role=meter]');
            return { remote: !li.querySelector('code'), meter: !!m, now: m ? Number(m.getAttribute('aria-valuenow')) : null, label: m?.getAttribute('aria-label') ?? '' };
        })`);
    assert.ok(meters.length > 0, 'the disk card lists the filesystems');
    for (const disk of meters.filter((d) => !d.remote)) {
        assert.ok(disk.meter, `every local filesystem has a usage meter: ${JSON.stringify(meters)}`);
        assert.ok(disk.now >= 0 && disk.now <= 100, `a meter's value is a percentage: ${JSON.stringify(disk)}`);
        assert.match(disk.label, /disk usage$/, 'a meter is named after its filesystem');
    }

    const system = await page.evaluate(`document.querySelector('#healthSystem').textContent`);
    for (const fact of ['nfdump', 'Active queries', 'Uptime', 'Datasource', 'PHP', 'OpenSwoole', 'SQLite journal']) {
        assert.ok(system.includes(fact), `the system card shows ${fact}`);
    }

    // Each group of checks is a tbody headed by a rowgroup header, the SQLite group among them.
    const groups = await page.evaluate(
        `[...document.querySelectorAll('#healthChecks tbody > tr.row-group:first-child > th[colspan="2"][scope="rowgroup"]')].map((th) => th.textContent.trim())`
    );
    assert.ok(groups.includes('Storage (SQLite)'), `the checks have the Storage (SQLite) group, got ${groups.join(', ')}`);
    const sqliteRows = await page.evaluate(`(() => {
            const rows = [...document.querySelectorAll('#healthChecks tbody tr')];
            const start = rows.findIndex((r) => r.textContent.trim() === 'Storage (SQLite)');
            const out = [];
            for (const r of rows.slice(start + 1)) { if (r.classList.contains('row-group')) break; out.push(r.querySelector('th')?.textContent.trim()); }
            return out;
        })()`);
    for (const label of ['Database file', 'Journal mode', 'SQLite library']) {
        assert.ok(
            sqliteRows.some((l) => l?.endsWith(label)),
            `the SQLite group has a ${label} row, got ${JSON.stringify(sqliteRows)}`
        );
    }
    // Every status glyph carries its word for screen readers.
    assert.equal(
        await page.evaluate(
            `[...document.querySelectorAll('#healthChecks tbody th[scope=row] .status-dot')].filter((d) => !/^(OK|Warning|Error):$/.test(d.textContent.trim())).length`
        ),
        0,
        'each check glyph has visually hidden text'
    );

    // Collect missing top-N now, reached with Tab from the Import heading and pressed with
    // Enter: queues the gaps, or says nothing is missing. A dev-app restart drops the tab's
    // context mid-press, so the press is retried.
    if (!(await page.evaluate(`document.getElementById('topnFill').disabled`))) {
        // The result is app-wide and outlives this tab, so a new run shows a new time.
        const stamp = `(document.querySelector('[data-topn-fill] time')?.getAttribute('datetime') ?? '')`;
        const before = await page.evaluate(stamp);
        const filled = `${stamp} !== ${JSON.stringify(before)} && /Queued \\d|Nothing missing/.test(document.querySelector('[data-topn-fill]').textContent)`;
        for (let attempt = 1; ; attempt++) {
            const path = await fromImportHeading(page, '#topnFill');
            assert.equal(path.length, 1, `#topnFill is the first stop after the Import heading, went through ${path.join(' > ')}`);
            await press(page, 'Enter');
            try {
                await page.waitFor(filled, { timeout: 15000, label: 'the top-N fill result' });
                break;
            } catch (e) {
                if (attempt >= 3) throw e;
                await page.waitForPage('health', { timeout: 20000 });
            }
        }
    }

    // Rescan (RRD): Tab reaches every profile's buttons in order; Enter opens the shared
    // confirmation on its Delete button, Tab and Enter on Cancel close it, Space opens it
    // again and Escape closes it, each time returning the focus to the Rescan button.
    const profile = profiles.at(-1);
    const rescanOf = (p) => `#healthImport [data-rescan="${p}"]`;
    if (await page.evaluate(`!!document.querySelector('${rescanOf(profile)}:not(:disabled)')`)) {
        const path = await fromImportHeading(page, rescanOf(profile));
        for (const p of profiles) {
            assert.ok(path.includes(`Trigger import for ${p}`), `Tab passes Trigger of ${p}: ${path.join(' > ')}`);
            assert.ok(path.includes(`Rescan ${p}`), `Tab passes Rescan of ${p}: ${path.join(' > ')}`);
        }
        const backOnRescan = `document.activeElement?.dataset.rescan === ${JSON.stringify(profile)}`;

        await press(page, 'Enter');
        await page.waitFor(`!document.getElementById('rescanConfirm').hidden`, { label: 'Enter opens the rescan confirmation' });
        await page.waitFor(`document.activeElement?.id === 'rescanConfirmRun'`, { label: 'focus on the confirm button' });
        assert.match(
            await page.evaluate(`document.getElementById('rescanConfirmText').textContent`),
            new RegExp(profile),
            'the confirmation names the profile'
        );
        assert.equal(
            await page.evaluate(`document.querySelector('#rescanConfirm > .status-dot[data-level=warning]')?.textContent.trim()`),
            'Warning:',
            'the confirmation leads with a warning glyph and its word'
        );
        await page.withForcedColors(async () => {
            await sleep(300);
            await page.screenshot('/tmp/health-e2e-confirm-forced.png');
        });
        await press(page, 'Tab');
        assert.equal(await page.evaluate(`document.activeElement?.textContent.trim()`), 'Cancel', 'Tab moves to Cancel');
        await press(page, 'Enter');
        await page.waitFor(`document.getElementById('rescanConfirm').hidden`, { label: 'Cancel closes the confirmation' });
        await page.waitFor(backOnRescan, { label: 'focus back on Rescan after Cancel' });

        await press(page, ' ');
        await page.waitFor(`document.activeElement?.id === 'rescanConfirmRun'`, { label: 'Space opens the confirmation again' });
        await press(page, 'Escape');
        await page.waitFor(`document.getElementById('rescanConfirm').hidden`, { label: 'Escape closes the confirmation' });
        await page.waitFor(backOnRescan, { label: 'focus back on Rescan after Escape' });
    }

    // Recent log: Tab into the level filter, then the arrow keys. Every step checks which
    // rows are visible, first on the real lines, then with a warning and an error added.
    await page.waitFor(`document.querySelectorAll('#healthLog tbody tr[data-level]').length > 0`, { label: 'log rows' });
    const logRadio = '#healthLog input[name="healthLogLevel"]:checked';
    await fromImportHeading(page, logRadio);
    assert.equal(await page.evaluate(`document.activeElement.id`), 'healthLogAll', 'Tab enters the filter on its checked level');
    assertFilter(await page.evaluate(logSnapshot), 'all');
    assert.ok(
        (await page.evaluate(logSnapshot)).rows.some((r) => r.shown),
        'the log shows lines'
    );
    // At log level Debug the ring keeps the DEBUG lines too, and All shows them.
    const logLevel = await page.evaluate(
        `document.querySelector('#healthLog .card-meta').textContent.match(/log level (\\w+)/)?.[1] ?? ''`
    );
    assert.ok(logLevel !== '', 'the log card names the log level');
    if (logLevel === 'debug') {
        assert.ok(
            (await page.evaluate(logSnapshot)).rows.some((r) => r.level === 'debug' && r.shown),
            'at log level debug, All shows DEBUG lines'
        );
    } else {
        console.log(`health: log level is ${logLevel}, so the DEBUG line check is skipped (set NFSEN_LOG_LEVEL=debug)`);
    }
    await arrowTo(page, 'ArrowRight', 'warning');
    assertFilter(await page.evaluate(logSnapshot), 'warning');
    await arrowTo(page, 'ArrowRight', 'error');
    assertFilter(await page.evaluate(logSnapshot), 'error');

    await page.evaluate(addFixtures);
    assertFilter(await snapshotWithFixtures(page), 'error');
    await arrowTo(page, 'ArrowLeft', 'warning');
    const withWarnings = await snapshotWithFixtures(page);
    assertFilter(withWarnings, 'warning');
    assert.ok(
        withWarnings.rows.some((r) => r.level === 'warning' && r.shown),
        'Warnings and errors keeps a warning row visible'
    );
    await arrowTo(page, 'ArrowLeft', 'all');
    assertFilter(await snapshotWithFixtures(page), 'all');
    await page.evaluate(`document.querySelectorAll('#healthLog tr[data-e2e]').forEach((r) => r.remove())`);
    // The group wraps around: left of All is Errors.
    await arrowTo(page, 'ArrowLeft', 'error');

    // The 10 s refresh posts health-refresh, and its morph keeps the filter. A dev-app restart
    // reloads the page, which resets client state, so that round is repeated.
    const requests = await page.requestLog();
    for (let attempt = 1; ; attempt++) {
        await page.evaluate(`window.__healthMark = true`);
        const posted = requests.count('health-refresh');
        const deadline = Date.now() + 30000;
        while (requests.count('health-refresh') === posted && Date.now() < deadline) await sleep(250);
        assert.ok(requests.count('health-refresh') > posted, 'an open Health page refreshes itself');
        await sleep(1000);
        if (await page.evaluate(`window.__healthMark === true`)) break;
        assert.ok(attempt < 3, 'the page kept reloading');
        await page.waitForPage('health', { timeout: 20000 });
        await fromImportHeading(page, logRadio);
        await arrowTo(page, 'ArrowLeft', 'error');
    }
    assert.equal(await page.evaluate(`document.getElementById('healthLog').dataset.filter`), 'error', 'a refresh keeps the level filter');
    assert.ok(await page.evaluate(`document.getElementById('healthLogErrors').checked`), 'a refresh keeps the checked level');

    // Back to All, then Tab to Copy and press Enter. Copy works on plain HTTP too (the
    // textarea fallback) and says so.
    if (!(await page.evaluate(`document.activeElement?.name === 'healthLogLevel'`))) await fromImportHeading(page, logRadio);
    await arrowTo(page, 'ArrowRight', 'all');
    await press(page, 'Tab');
    assert.equal(await page.evaluate(`document.activeElement?.id`), 'healthLogCopy', 'Tab leaves the filter for Copy');
    await press(page, 'Enter');
    await page.waitFor(`document.getElementById('healthLogCopy').textContent.trim() === 'Copied'`, { label: 'copy confirmation' });
    await press(page, 'Tab', { shift: true });
    assert.equal(await page.evaluate(`document.activeElement?.id`), 'healthLogAll', 'Shift+Tab returns to the checked level');

    await page.screenshot('/tmp/health-e2e.png');
    await page.withForcedColors(async () => {
        await sleep(300);
        await page.screenshot('/tmp/health-e2e-forced.png');
    });

    // Trigger an import of the last profile with the keyboard. The outcome notice of an
    // earlier pass stays until a new one starts, so the start is watched for first: Cancel
    // import shows while the pass runs, however briefly.
    if (!skipMutating) {
        const watchStart = `(() => {
                window.__importSeen = false;
                new MutationObserver((records) => {
                    const mine = records.filter((r) => r.target.id === 'importCancel');
                    mine.forEach((r, i) => {
                        const next = mine[i + 1];
                        if (r.oldValue !== null && (next ? next.oldValue === null : !r.target.hidden)) window.__importSeen = true;
                    });
                }).observe(document.getElementById('page-health'), { subtree: true, attributes: true, attributeFilter: ['hidden'], attributeOldValue: true });
            })()`;
        for (let attempt = 1; ; attempt++) {
            await page.waitFor(`!document.querySelector('${triggerOf(profile)}').disabled`, {
                timeout: 60000,
                label: 'no import running',
            });
            await page.evaluate(watchStart);
            await page.evaluate(`window.__outcomeRegion = document.getElementById('importOutcome')`);
            await fromImportHeading(page, triggerOf(profile));
            const posted = requests.count('trigger-import');
            await press(page, 'Enter');
            try {
                await page.waitFor(`window.__importSeen === true`, { timeout: 30000, label: 'the triggered pass to start' });
                assert.ok(requests.count('trigger-import') > posted, 'Enter on Trigger posts trigger-import');
                break;
            } catch (e) {
                // Only a reload (dev-app restart) forgets the watcher; anything else is a failure.
                if (attempt >= 3 || (await page.evaluate(`window.__importSeen === false`))) throw e;
                await page.waitForPage('health', { timeout: 20000 });
            }
        }
        const notice = `document.querySelector('#importOutcome > p.notice')`;
        await page.waitFor(
            `document.getElementById('importCancel').hidden && /Import (complete|cancelled|failed)/.test(${notice}?.textContent ?? '')`,
            {
                timeout: 120000,
                label: 'the triggered import to finish',
            }
        );
        assert.match(await page.evaluate(`${notice}.textContent`), /Import complete\./, 'the triggered import completes');
        assert.ok(await page.evaluate(`!!${notice}.querySelector('.status-dot[data-level]')`), 'a levelled outcome carries a glyph');
        // A live region only announces changes to itself, so the pass must not have replaced it.
        assert.ok(
            await page.evaluate(
                `document.getElementById('importOutcome') === window.__outcomeRegion && window.__outcomeRegion.getAttribute('role') === 'status'`
            ),
            'the outcome arrives in the live region that was there before the pass'
        );
        assert.equal(
            await page.evaluate(`document.activeElement?.id`),
            'healthImportTitle',
            'the disabled Trigger hands the focus to the Import heading, where it stays'
        );
    }

    // Leaving the page removes the refresh with its content.
    await page.gotoPage('overview');
    assert.equal(await page.evaluate(`!!document.querySelector('#page-health .health')`), false, 'Health stops refreshing once closed');

    const errors = page.realErrors();
    assert.deepEqual(errors, [], `expected no console errors on Health, got:\n${errors.join('\n')}`);
}

export default async function healthTest() {
    for (let attempt = 1; ; attempt++) {
        const failure = await withPage(async (page) => {
            try {
                await walkHealth(page);
                return null;
            } catch (error) {
                const reloaded = await page.evaluate(`window.__healthRun !== true`).catch(() => true);
                return { error, reloaded };
            }
        });
        if (failure === null) return;
        if (!failure.reloaded || attempt >= 3) throw failure.error;
        console.log(`health: the dev app restarted mid-run (${String(failure.error.message).split('\n')[0]}), starting over`);
    }
}

if (import.meta.url === `file://${process.argv[1]}`) {
    healthTest()
        .then(() => console.log('health: PASS'))
        .catch((e) => {
            console.error('health: FAIL\n', e);
            process.exit(1);
        });
}
