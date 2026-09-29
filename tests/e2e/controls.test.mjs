// The global controls bar (spec 4.0.2): presets, custom duration, step buttons, the derived
// range label, absolute entry in the display timezone, the sources, protocol and unit
// controls, menu keyboard behaviour, and the import chip. The last part triggers an import on
// the "test" profile; E2E_SKIP_MUTATING=1 leaves that part out.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const SKIP_MUTATING = ['1', 'true', 'yes'].includes(String(process.env.E2E_SKIP_MUTATING ?? '').toLowerCase());

const RANGE_TOGGLE = "document.querySelector('#rangeMenu .menu-toggle')";

/** Offset of `zone` from UTC in minutes at the instant `ms`, from Intl's own offset name. */
function zoneOffsetMinutes(ms, zone) {
    const name = new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'longOffset' })
        .formatToParts(new Date(ms))
        .find((part) => part.type === 'timeZoneName').value;
    const m = /GMT([+-])(\d{2}):(\d{2})/.exec(name);
    return m ? (m[1] === '-' ? -1 : 1) * (Number(m[2]) * 60 + Number(m[3])) : 0;
}

/** A "YYYY-MM-DD" a few days back, and the epoch of `hh:00` on it in `zone`. */
function wallTime(daysBack, hour, zone) {
    const day = new Date(Date.now() - daysBack * 86400_000).toISOString().slice(0, 10);
    const [y, m, d] = day.split('-').map(Number);
    const naive = Date.UTC(y, m - 1, d, hour, 0);
    const epoch = (naive - zoneOffsetMinutes(naive - zoneOffsetMinutes(naive, zone) * 60_000, zone) * 60_000) / 1000;
    return { local: `${day}T${String(hour).padStart(2, '0')}:00`, epoch };
}

export default async function controlsTest() {
    // One row at 1280 px with the sidebar expanded and no import running (4.0.2).
    await withPage(
        async (page) => {
            await page.navigate(BASE + '/#/overview');
            await page.waitForBoot();
            await page.waitForPage('overview');
            const oneRow = await page.evaluate(`(function(){
                var bar = document.querySelector('.controls-bar');
                var items = [...bar.querySelectorAll('.profile-select, .menu-toggle, .button-group, .protocol-select, .segmented, #reloadButton')]
                    .filter(function(el){ return el.getClientRects().length > 0 && !el.closest('.menu-list'); });
                var first = items[0].getBoundingClientRect();
                return items.every(function(el){ var r = el.getBoundingClientRect(), mid = (r.top + r.bottom) / 2; return mid > first.top && mid < first.bottom; });
            })()`);
            if (!(await page.signalValue('import_running'))) assert.ok(oneRow, 'the controls fit one row at 1280 px');
            assert.equal(await page.evaluate(`document.body.dataset.sidebar`), 'expanded');
        },
        { width: 1280, height: 900 }
    );

    await withPage(async (page) => {
        // Absolute entry is read in the browser's timezone unless the display says server.
        await page.send('Emulation.setTimezoneOverride', { timezoneId: 'Asia/Kolkata' });
        await page.navigate(BASE + '/#/overview');
        await page.waitForBoot();
        await page.waitForPage('overview');
        const log = await page.requestLog();

        // One read of all four, so a patch landing in between cannot mix two windows.
        const range = () =>
            page.evaluate(`(async function(){
                var root = (await import('datastar')).root;
                var get = function(n){ var k = Object.keys(root).find(function(x){ return x === n || x.startsWith(n + '____'); }); return k === undefined ? undefined : root[k]; };
                return { from: get('datestart'), to: get('dateend'), live: get('range_live'), preset: get('range_preset') };
            })()`);
        const until = async (label, test, timeout = 8000) => {
            const start = Date.now();
            for (;;) {
                const r = await range();
                if (test(r)) return r;
                if (Date.now() - start > timeout) throw new Error(`${label}: got ${JSON.stringify(r)}`);
                await sleep(150);
            }
        };
        const label = () => page.evaluate(`${RANGE_TOGGLE}.textContent.trim()`);
        const click = (selector) => page.evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);

        // Presets: live windows of their width, named by the toggle and marked in the menu.
        for (const [id, seconds, text] of [
            ['1h', 3600, 'Last 1 hour'],
            ['7d', 604800, 'Last 7 days'],
            ['24h', 86400, 'Last 24 hours'],
        ]) {
            await page.setRangePreset(id);
            const r = await until(`preset ${id}`, (x) => x.live === true && x.preset === id);
            assert.ok(Math.abs(r.to - r.from - seconds) <= 1, `preset ${id} is ${seconds} s wide`);
            await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === ${JSON.stringify(text)}`, { label: `toggle "${text}"` });
            assert.equal(await page.evaluate(`document.querySelector('[data-range-preset="${id}"]').getAttribute('aria-pressed')`), 'true');
        }

        // Custom duration: invalid input keeps the menu open and posts nothing, 6 h applies.
        await page.evaluate(`${RANGE_TOGGLE}.click()`);
        await page.waitFor(`document.getElementById('customDurationValue').getClientRects().length > 0`, { label: 'custom duration row' });
        log.clear();
        await page.setInputValue('#customDurationValue', '0');
        await click('#customDurationApply');
        await sleep(400);
        assert.equal(log.count('set-range'), 0, 'an invalid duration posts nothing');
        assert.equal(await page.evaluate(`${RANGE_TOGGLE}.getAttribute('aria-expanded')`), 'true', 'the menu stays open');
        await page.setInputValue('#customDurationValue', '6');
        await page.setSelectValue('#customDurationUnit', 'h');
        await page.evaluate(
            `document.getElementById('customDurationValue').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))`
        );
        let r = await until('6 hours', (x) => x.preset === 'custom' && Math.abs(x.to - x.from - 21600) <= 1);
        assert.equal(r.live, true);
        await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === 'Last 6 hours'`, { label: 'toggle "Last 6 hours"' });

        // Back, forward, now.
        await page.setRangePreset('7d');
        const week = await until('7d', (x) => x.preset === '7d' && x.live);
        await page.waitFor(`document.getElementById('rangeForward').disabled`, { label: 'Forward disabled while live' });
        await click('#rangeBack');
        r = await until('back', (x) => x.live === false);
        // A live window starts a width before now, so a few seconds on the way count too.
        assert.ok(r.to >= week.from && r.to - week.from <= 10, `back ends where the live window starts, got ${r.to} for ${week.from}`);
        assert.equal(r.to - r.from, week.to - week.from, 'back keeps the width');
        await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === '7 days'`, { label: 'toggle shows the width after Back' });
        await page.waitFor(`!document.getElementById('rangeForward').disabled`, { label: 'Forward enabled once fixed' });
        const oneBack = r;
        await click('#rangeBack');
        const twoBack = await until('back twice', (x) => x.to === oneBack.from);
        await click('#rangeForward');
        r = await until('forward', (x) => x.from === twoBack.to);
        assert.equal(r.live, false);
        await click('#rangeNow');
        r = await until('now', (x) => x.live === true);
        assert.ok(Math.abs(r.to - r.from - 604800) <= 1 && Math.abs(r.to - Date.now() / 1000) < 120, 'now keeps the width and ends now');
        await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === 'Last 7 days'`, { label: 'toggle back to the preset' });

        // Absolute range in the browser's timezone (emulated Asia/Kolkata, UTC+05:30).
        const openEntry = async () => {
            await page.evaluate(`(function(){
                var toggle = document.querySelector('#rangeDisplay .menu-toggle');
                if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
            })()`);
            await page.waitFor(`document.getElementById('rangeFrom').getClientRects().length > 0`, { label: 'absolute range entry' });
        };

        // An entry that reaches now is live, and Previous range brings it back live at its width,
        // not rounded to whole hours.
        await openEntry();
        const nowS = Math.floor(Date.now() / 1000);
        const localAt = (epoch) => page.evaluate(`window.nfsenTime.toLocalInput(${epoch}, 'browser', '')`);
        await page.setInputValue('#rangeFrom', await localAt(nowS - 2700));
        await page.setInputValue('#rangeTo', await localAt(nowS + 60));
        await click('#rangeApply');
        const shortLive = await until('a live 45 minute window', (x) => x.live && x.preset === 'custom' && x.to - x.from < 3600);
        await page.setRangePreset('24h');
        await until('24h after the short window', (x) => x.preset === '24h');
        await page.waitFor(`!document.getElementById('rangeUndo').disabled`, { label: '#rangeUndo to enable' });
        await click('#rangeUndo');
        r = await until('the short window back', (x) => x.live && x.preset === 'custom');
        const [shortWidth, restoredWidth] = [shortLive.to - shortLive.from, r.to - r.from];
        assert.ok(
            restoredWidth >= shortWidth && restoredWidth - shortWidth < 300 && restoredWidth < 3600,
            `Previous range keeps ${shortWidth} s, got ${restoredWidth} s`
        );
        await openEntry();
        const prefilled = await page.evaluate(`document.getElementById('rangeTo').value`);
        assert.match(prefilled, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/, 'the entry is prefilled from the window');
        const browserFrom = wallTime(3, 10, 'Asia/Kolkata');
        const browserTo = wallTime(3, 16, 'Asia/Kolkata');
        await page.setInputValue('#rangeFrom', browserFrom.local);
        await page.setInputValue('#rangeTo', browserTo.local);
        await click('#rangeApply');
        r = await until('absolute range', (x) => x.from === browserFrom.epoch && x.to === browserTo.epoch);
        assert.equal(r.live, false);
        const shown = await page.evaluate(`document.querySelector('#rangeDisplay .menu-toggle').textContent`);
        assert.ok(shown.includes('10:00') && shown.includes('16:00'), `the display shows the entered times, got "${shown.trim()}"`);

        // The same entry in the capture timezone once the display is set to server time.
        const serverTz = await page.signalValue('nfcapdTz');
        await page.evaluate(`(async function(){
            var root = (await import('datastar')).root;
            var key = Object.keys(root).find(function(k){ return k.startsWith('displayTz____'); });
            root[key] = 'server';
        })()`);
        await openEntry();
        assert.match(
            await page.evaluate(`document.querySelector('.range-entry .help').textContent`),
            new RegExp(serverTz.replace('/', '\\/'))
        );
        const serverFrom = wallTime(2, 9, serverTz);
        const serverTo = wallTime(2, 12, serverTz);
        await page.setInputValue('#rangeFrom', serverFrom.local);
        await page.setInputValue('#rangeTo', serverTo.local);
        await click('#rangeApply');
        await until('absolute range in server time', (x) => x.from === serverFrom.epoch && x.to === serverTo.epoch);

        // An end before the start stays in the menu with a message.
        await openEntry();
        log.clear();
        await page.setInputValue('#rangeFrom', serverTo.local);
        await page.setInputValue('#rangeTo', serverFrom.local);
        await click('#rangeApply');
        await page.waitFor(`document.querySelector('.range-entry [role=alert]').textContent.includes('end after it starts')`, {
            label: 'the entry error',
        });
        assert.equal(log.count('set-range'), 0, 'a backwards range posts nothing');

        // Escape closes a menu and gives the focus back to its toggle.
        await page.evaluate(`document.getElementById('rangeFrom').focus()`);
        await page.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await page.waitFor(`document.activeElement === document.querySelector('#rangeDisplay .menu-toggle')`, {
            label: 'focus back on the range display toggle',
        });
        assert.equal(await page.evaluate(`document.querySelector('#rangeDisplay .menu-toggle').getAttribute('aria-expanded')`), 'false');

        // Keyboard only: Enter opens the range menu, Tab reaches a preset, Enter picks it.
        await page.evaluate(`${RANGE_TOGGLE}.focus()`);
        const key = async (name, code = name, keyCode = 0) => {
            await page.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: name, code, windowsVirtualKeyCode: keyCode });
            if (name === 'Enter') await page.send('Input.dispatchKeyEvent', { type: 'char', text: '\r', key: 'Enter' });
            await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key: name, code, windowsVirtualKeyCode: keyCode });
            await sleep(120);
        };
        await key('Enter', 'Enter', 13);
        assert.equal(await page.evaluate(`${RANGE_TOGGLE}.getAttribute('aria-expanded')`), 'true', 'Enter opens the range menu');
        await key('Tab', 'Tab', 9);
        assert.equal(await page.evaluate(`document.activeElement.dataset.rangePreset`), '1h', 'Tab moves into the menu');
        await key('Enter', 'Enter', 13);
        await until('keyboard preset', (x) => x.preset === '1h' && x.live);
        await page.setRangePreset('24h');

        // Sources: at least one stays checked, whatever is unticked.
        await page.evaluate(`document.querySelector('#sourcesMenu .menu-toggle').click()`);
        await page.waitFor(`document.querySelector('#sourcesMenuList input[name=globalSource]')?.getClientRects().length > 0`, {
            label: 'the sources list',
        });
        const before = await page.signalValue('graph_sources');
        log.clear();
        await page.evaluate(
            `[...document.querySelectorAll('#sourcesMenuList input[name=globalSource]')].forEach(function(box){ if (box.checked) box.click(); })`
        );
        const left = await page.evaluate(
            `[...document.querySelectorAll('#sourcesMenuList input[name=globalSource]')].filter(function(b){ return b.checked; }).length`
        );
        assert.ok(left >= 1, 'one source stays checked');
        await sleep(700);
        assert.ok(log.count('apply-globals') >= 1, 'a sources change posts apply-globals once the ticking stops');
        const after = await page.signalValue('graph_sources');
        assert.ok(Array.isArray(after) && after.length >= 1, `graph_sources keeps a source, got ${JSON.stringify(after)}`);
        await page.evaluate(`(function(){
            var all = document.getElementById('sourcesAll');
            if (all) { if (!all.checked) all.click(); return; }
            [...document.querySelectorAll('#sourcesMenuList input[name=globalSource]')].forEach(function(box){ if (!box.checked) box.click(); });
        })()`);
        await page.evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`);
        await sleep(700);
        assert.deepEqual(
            (await page.signalValue('graph_sources')).slice().sort(),
            before.includes('any') ? after.slice().sort() : before.slice().sort()
        );

        // Protocol and unit post apply-globals, and the unit follows the arrow keys.
        log.clear();
        await page.setSelectValue('#protocolSelect', 'tcp');
        await sleep(600);
        assert.equal(log.count('apply-globals'), 1, 'the protocol posts apply-globals');
        assert.equal(await page.signalValue('protocol'), 'tcp');
        await page.setSelectValue('#protocolSelect', 'any');
        await sleep(600);
        // The arrow keys move the unit like any radio group, from whichever unit the tab started with.
        const unit = await page.signalValue('graph_trafficUnit');
        const [focusId, other, arrow, back] = unit === 'bits' ? ['unitBits', 'bytes', 39, 37] : ['unitBytes', 'bits', 37, 39];
        const arrowName = (code) => (code === 39 ? 'ArrowRight' : 'ArrowLeft');
        log.clear();
        await page.evaluate(`document.getElementById('${focusId}').focus()`);
        await key(arrowName(arrow), arrowName(arrow), arrow);
        await sleep(600);
        assert.equal(await page.signalValue('graph_trafficUnit'), other, `${arrowName(arrow)} picks ${other}`);
        assert.ok(log.count('apply-globals') >= 1, 'the unit posts apply-globals');
        await key(arrowName(back), arrowName(back), back);
        await sleep(600);
        assert.equal(await page.signalValue('graph_trafficUnit'), unit, 'and back');

        // No range op or global posts a capture-reading query.
        for (const name of [
            'stats-actions',
            'flow-actions',
            'conversations-run',
            'run-filtered-graph',
            'talkers-panel',
            'overview-topn-run',
        ]) {
            assert.equal(log.count(name), 0, `${name} was never posted`);
        }

        // Forced colours: the bar with a menu open, for the wave gate's manual look.
        await page.withForcedColors(async () => {
            await page.evaluate(`${RANGE_TOGGLE}.click()`);
            await sleep(300);
            await page.screenshot('/tmp/nfsen-controls-forced-colors.png');
            await page.evaluate(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))`);
        });

        // The import chip: hidden while idle, neutral while an import runs, hidden again after.
        const idleStart = Date.now();
        while ((await page.signalValue('import_running')) && Date.now() - idleStart < 120_000) await sleep(500);
        await sleep(500);
        // A failed pass from Health keeps 'Import failed' up while idle; anything else hides the chip.
        const idleChip = await page.evaluate(`(function(){
            var chip = document.getElementById('importChip');
            return { shown: chip.getClientRects().length > 0, level: chip.getAttribute('data-level'), text: chip.textContent.trim() };
        })()`);
        assert.ok(
            !idleChip.shown || (idleChip.level === 'error' && /Import failed/.test(idleChip.text)),
            `no chip while idle (${JSON.stringify(idleChip)})`
        );

        if (SKIP_MUTATING) {
            console.log('  (controls: profile switch and import chip run skipped, E2E_SKIP_MUTATING)');
        } else {
            const profiles = await page.signalValue('available_profiles');
            if (!Array.isArray(profiles) || !profiles.includes('test')) {
                console.log('  (controls: no "test" profile here, profile switch and import chip run skipped)');
            } else {
                // A profile switch keeps the width; the previous profile is restored after.
                const original = await page.signalValue('selected_profile');
                const other = original === 'test' ? profiles.find((p) => p !== 'test') : 'test';
                const width = (await range()).to - (await range()).from;
                log.clear();
                try {
                    await page.setSelectValue('#profileSelect', other);
                    const start = Date.now();
                    while (log.count('change-profile') === 0 && Date.now() - start < 5000) await sleep(100);
                    assert.equal(log.count('change-profile'), 1, 'the profile select posts change-profile');
                    await sleep(800);
                    assert.equal(await page.signalValue('selected_profile'), other);
                    r = await range();
                    assert.ok(Math.abs(r.to - r.from - width) <= 1, 'a profile switch keeps the width');
                } finally {
                    // The profile is saved for the whole dev stack, so it goes back whatever failed.
                    await page.setSelectValue('#profileSelect', original);
                    await sleep(800);
                }
                assert.equal(await page.signalValue('selected_profile'), original, 'the profile is restored');

                await page.evaluate(`(function(){
                    window.__chip = [];
                    var record = function(){
                        var chip = document.getElementById('importChip');
                        if (chip && chip.getClientRects().length) window.__chip.push({ level: chip.getAttribute('data-level'), text: chip.textContent.replace(/\\s+/g, ' ').trim() });
                    };
                    new MutationObserver(record).observe(document.querySelector('.controls-bar'), { subtree: true, childList: true, attributes: true, characterData: true });
                })()`);
                const status = await page.evaluate(`(async function(){
                    var html = document.documentElement.outerHTML;
                    var ctx = (html.match(/via_ctx&quot;:&quot;([^&]+)&quot;/) || html.match(/via_ctx":"([^"]+)"/) || [])[1];
                    var hash = (html.match(/\\bdatestart____([a-z0-9]+)/) || [])[1];
                    var id = hash && 'admin_target_profile____' + hash;
                    if (!ctx || !id) return 'missing ' + JSON.stringify({ ctx: ctx, id: id });
                    var body = { via_ctx: ctx };
                    body[id] = 'test';
                    var res = await fetch('/_action/trigger-import', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Datastar-Request': 'true' }, body: JSON.stringify(body) });
                    return res.status;
                })()`);
                assert.ok(status === 200 || status === 204, `trigger-import answered ${status}`);
                await page.waitFor(`window.__chip.length > 0`, { timeout: 20_000, label: 'the chip while the import runs' });
                const seen = await page.evaluate(`window.__chip`);
                assert.ok(
                    seen.some((s) => s.level === null && /Import running/.test(s.text)),
                    `the chip is neutral while running, saw ${JSON.stringify(seen)}`
                );
                await page.waitFor(`document.getElementById('importChip').getClientRects().length === 0`, {
                    timeout: 120_000,
                    label: 'the chip to go once the import is done',
                });
            }
        }

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    controlsTest()
        .then(() => console.log('controls: PASS'))
        .catch((e) => {
            console.error('controls: FAIL\n', e);
            process.exit(1);
        });
}
