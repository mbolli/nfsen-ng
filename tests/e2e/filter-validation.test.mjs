// Query kit on Flows (3.5.3, 5.5); requests are counted over CDP. E2E_FAST=1 skips the wait for a live tick.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const FIELD = '#filterNfdumpTextarea';
const STATUS = '[data-filter-status="flows"]';
const ESTIMATE = '[data-estimate="flows"]';
const RUN_STATUS = '#flowsRun [role=status]';
/** The POSTs since the last clear, for failure messages. */
let requests = null;

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function press(page, key, code, keyCode) {
    for (const type of ['keyDown', 'keyUp']) {
        await page.send('Input.dispatchKeyEvent', { type, key, code, windowsVirtualKeyCode: keyCode });
    }
}

/** Replaces the field's text the way typing does: focus, select all, then type or delete. */
async function typeFilter(page, text) {
    await page.evaluate(`(function(){ var t = document.querySelector('${FIELD}'); t.focus(); t.select(); })()`);
    if (text === '') await press(page, 'Backspace', 'Backspace', 8);
    else await page.send('Input.insertText', { text });
}

/** The posts since the last clear, and whether the app reloaded meanwhile (a new context navigates). */
function recentPosts() {
    if (!requests) return '';
    const names = requests.names();
    const reloaded = names.includes('navigate') ? ' THE APP RELOADED (a navigate post);' : '';
    return `${reloaded} posts since the last clear: ${names.slice(-12).join(', ') || 'none'}`;
}

/** The field's state, text and answer, for a failure message. */
async function fieldState(page) {
    const state = await page.evaluate(`document.querySelector('${STATUS}')?.dataset.state`);
    const text = await page.evaluate(`document.querySelector('${FIELD}')?.value`);
    const answer = await page.signalValue('_flt_flows');
    return `state ${state}, text ${JSON.stringify(text)}, _flt_flows ${JSON.stringify(answer)};${recentPosts()}`;
}

async function waitForState(page, state, label = `the ${state} state`) {
    try {
        await page.waitFor(`document.querySelector('${STATUS}')?.dataset.state === '${state}'`, { timeout: 5000, label });
    } catch (e) {
        throw new Error(`${e.message}; ${await fieldState(page)}`);
    }
}

/** A protocol name no run has checked before, so the server's validator cache cannot answer it. */
function unknownProtocol() {
    return `qk${Math.random().toString(36).slice(2, 8).replace(/[0-9]/g, 'x')}`;
}

/** Types a filter nfdump rejects and waits for the answer, which has to be the invalid one. */
async function expectInvalid(page, type, label) {
    const text = await type();
    try {
        await page.waitFor(`['valid', 'invalid'].includes(document.querySelector('${STATUS}')?.dataset.state)`, { timeout: 5000, label });
    } catch (e) {
        throw new Error(`${e.message}; typed ${JSON.stringify(text)}; ${await fieldState(page)}`);
    }
    assert.equal(
        await page.evaluate(`document.querySelector('${STATUS}').dataset.state`),
        'invalid',
        `nfdump -Z rejects ${JSON.stringify(text)}, but the field says valid (a lost nfdump exit code?); ${await fieldState(page)}`
    );
}

async function statusOf(page) {
    return page.evaluate(`(function(){
        var s = document.querySelector('${STATUS}');
        return { shown: s.querySelector('.filter-status-shown').textContent,
                 live: s.querySelector('.visually-hidden').textContent,
                 politeness: s.getAttribute('aria-live'),
                 invalid: document.querySelector('${FIELD}').getAttribute('aria-invalid') };
    })()`);
}

/** Records every text the query control's live region takes from now on. */
async function listenToRunStatus(page) {
    await page.evaluate(`(function(){
        var r = document.querySelector('${RUN_STATUS}');
        window.__qkHeard = [];
        new MutationObserver(function(){ window.__qkHeard.push(r.textContent); }).observe(r, { childList: true, characterData: true, subtree: true });
    })()`);
}

/** Waits until the window's ends change, by a tick of this tab or a server push. */
async function windowMovedFrom(page, previous, timeout) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        // A reload in progress has no Datastar to ask; the caller sees its navigate post.
        const now = await buckets(page).catch(() => previous);
        if (now.from !== previous.from || now.to !== previous.to) return Date.now() - start;
        await sleep(500);
    }
    return null;
}

/** One live advance of the window and the posts it caused; observed again after an app reload. */
async function observeLiveAdvance(page, log) {
    for (let attempt = 1; ; attempt++) {
        await page.waitForBoot();
        await estimateSettled(page, 'the estimate before the live advance');
        await sleep(1000);
        log.clear();
        const before = await buckets(page);
        const tookMs = await windowMovedFrom(page, before, 75000);
        await sleep(2000);
        if (log.names().includes('navigate') && attempt < 3) {
            console.warn('  (the app reloaded during the live check, observing again)');
            continue;
        }
        assert.ok(tookMs !== null, `a live window advances within 75 s, ${before.from}-${before.to} did not;${recentPosts()}`);
        return { before, after: await buckets(page), tookMs };
    }
}

async function estimateSettled(page, label) {
    await page.waitFor(
        `(function(){ var e = document.querySelector('${ESTIMATE}'); return !!e && ['ready', 'empty'].includes(e.dataset.state) && !e.hasAttribute('aria-busy'); })()`,
        { timeout: 10000, label }
    );
    return page.evaluate(`(function(){
        var e = document.querySelector('${ESTIMATE}');
        var shown = [...e.querySelectorAll('.estimate-figures > li')].filter(function(li){ return li.getClientRects().length > 0; });
        return { state: e.dataset.state, text: shown.map(function(li){ return li.textContent.trim(); }).join(' · ') };
    })()`);
}

/** Moves the window the way an SSE signal patch does, without a request of its own. */
async function shiftWindow(page, seconds) {
    await page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        var find = function(n){ return Object.keys(root).find(function(k){ return k.startsWith(n + '____'); }); };
        root[find('datestart')] = root[find('datestart')] + ${seconds};
        root[find('dateend')] = root[find('dateend')] + ${seconds};
    })()`);
}

/** Sets a signal the way a server patch or a revival does. */
async function setSignal(page, name, value) {
    await page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        var key = Object.keys(root).find(function(k){ return k.startsWith('${name}____'); });
        root[key] = ${JSON.stringify(value)};
    })()`);
}

async function buckets(page) {
    const { datestart: from, dateend: to } = await page.signalValues(['datestart', 'dateend']);
    return { from, to, key: `${Math.floor(from / 300)}:${Math.floor(to / 300)}` };
}

/** A one second shift that keeps both ends in their 5 minute bucket, or 0 when none does. */
function inBucketShift({ from, to }) {
    if (from % 300 < 299 && to % 300 < 299) return 1;
    if (from % 300 > 0 && to % 300 > 0) return -1;
    return 0;
}

/** Waits until `_est_flows` is answered for a window other than `previous`. */
async function estimateMovedFrom(page, previous, label) {
    const start = Date.now();
    for (;;) {
        const est = await page.signalValue('_est_flows');
        if (est && !est.pending && est.window !== previous) return est;
        if (Date.now() - start > 10000) throw new Error(`${label}: _est_flows is still ${JSON.stringify(est)}`);
        await sleep(150);
    }
}

async function openFlows(page) {
    await page.navigate(BASE + '/#/flows');
    await page.waitForBoot();
    await page.gotoPage('flows');
}

/** Navigating to the URL the page already shows fires no load event, so this reloads instead. */
async function reloadFlows(page) {
    await page.reload();
    await page.waitForBoot();
    await page.gotoPage('flows');
}

async function waitForCount(log, name, n, timeout = 8000) {
    const start = Date.now();
    while (log.count(name) < n && Date.now() - start < timeout) await sleep(150);
}

export default async function filterValidationTest() {
    await withPage(async (page) => {
        const log = await page.requestLog();
        requests = log;
        await openFlows(page);

        // Opening Flows shows files, size and time before any run.
        let first = await estimateSettled(page, 'the Flows estimate on open');
        assert.equal(log.count('flow-actions'), 0, 'opening Flows ran no query');
        console.log(`  (estimate on open: ${first.state}, "${first.text}")`);
        if (first.state !== 'ready') {
            // The default range holds no captures here; a year holds every capture the stack has.
            console.warn('  NOTE: no capture files in the default range, checking the figures over 1y');
            const previous = (await page.signalValue('_est_flows')).window;
            await page.setRangePreset('1y');
            await estimateMovedFrom(page, previous, 'the estimate over 1y');
            first = await estimateSettled(page, 'the Flows estimate over 1y');
        }
        assert.equal(first.state, 'ready', `the estimate has capture files to show: ${first.text}`);
        assert.match(first.text, /^[\d,]+ files? · [\d.]+ (B|KiB|MiB|GiB|TiB) · up to \d+ (s|min|h)/, `estimate figures: ${first.text}`);
        assert.match(first.text, /\(.*stops early once the limit is reached\)/, `Flows says why it is "up to": ${first.text}`);

        // A finished Flows run is recorded, so the estimate asks again once, after the run. The
        // query control announces the start and the outcome, and its spinners are not status regions.
        await sleep(500);
        let before = await buckets(page);
        log.clear();
        await listenToRunStatus(page);
        await page.runQuery('flows');
        const heard = await page.evaluate('window.__qkHeard');
        assert.equal(heard[0], 'Query started', `the run is announced as started: ${JSON.stringify(heard)}`);
        assert.match(heard.at(-1) ?? '', /(^|\. )(Done in |Failed: )/, `the outcome is announced last: ${JSON.stringify(heard)}`);
        assert.equal(
            await page.evaluate(`document.querySelectorAll('#flowsRun [role=status]').length`),
            1,
            'one status region per query control'
        );
        const spinners = await page.evaluate(
            `[...document.querySelectorAll('.query-control .spinner, .estimate .spinner')].map(function(s){ return s.getAttribute('role') + '/' + s.getAttribute('aria-hidden'); })`
        );
        assert.ok(
            spinners.length > 0 && spinners.every((s) => s === 'null/true'),
            `spinners are hidden and roleless: ${spinners.join(', ')}`
        );
        await waitForCount(log, 'estimate-query', 1);
        await sleep(1000);
        let after = await buckets(page);
        const afterRun = log.names();
        assert.ok(afterRun.includes('flow-actions'), `the run was posted: ${afterRun.join(', ')}`);
        assert.equal(
            log.count('estimate-query'),
            1,
            `one estimate-query after the run (window ${before.key} to ${after.key}): ${afterRun.join(', ')}`
        );
        assert.ok(
            afterRun.indexOf('estimate-query') > afterRun.indexOf('flow-actions'),
            `the estimate is asked after the run: ${afterRun.join(', ')}`
        );
        await estimateSettled(page, 'the estimate after the run');

        // Keyboard: Shift+Tab from the Limit select reaches the field.
        await page.evaluate(`document.getElementById('flowsLimit').focus()`);
        for (let i = 0; i < 6 && (await page.evaluate('document.activeElement?.id')) !== FIELD.slice(1); i++) {
            for (const type of ['keyDown', 'keyUp']) {
                await page.send('Input.dispatchKeyEvent', { type, key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9, modifiers: 8 });
            }
        }
        assert.equal(await page.evaluate('document.activeElement?.id'), FIELD.slice(1), 'Shift+Tab reaches the filter field');
        const quiet = await statusOf(page);
        assert.equal(quiet.politeness, 'off', 'the answer for the loaded filter is not announced');

        // Typing an invalid filter shows nfdump's message and marks the field invalid.
        let typedAt = 0;
        await expectInvalid(
            page,
            async () => {
                const text = `proto ${unknownProtocol()}`;
                await page.send('Input.insertText', { text });
                typedAt = Date.now();
                return text;
            },
            'the invalid state'
        );
        const invalidAfter = Date.now() - typedAt;
        let status = await statusOf(page);
        assert.match(status.shown, /Unknown protocol/i, `nfdump's message is shown: ${status.shown}`);
        assert.equal(status.invalid, 'true', 'the textarea has aria-invalid="true"');
        assert.match(status.live, /^Invalid filter: .*Unknown protocol/i, `the live region carries the final text: ${status.live}`);
        assert.equal(status.politeness, 'polite', 'answers are announced once the user has typed');
        assert.equal(
            await page.evaluate(`document.querySelector('${FIELD}').getAttribute('aria-describedby')`),
            await page.evaluate(`document.querySelector('${STATUS}').id`),
            'the field is described by its status line'
        );
        assert.ok(invalidAfter < 900, `the answer arrived about half a second after typing, took ${invalidAfter} ms`);
        console.log(`  (invalid shown ${invalidAfter} ms after typing, 300 ms of it debounce)`);

        // A valid filter says so, and the field is no longer marked invalid.
        await typeFilter(page, 'proto tcp');
        await waitForState(page, 'valid');
        status = await statusOf(page);
        assert.equal(status.shown, 'Valid filter');
        assert.equal(status.live, 'Valid filter');
        assert.equal(status.invalid, null, 'aria-invalid is gone');

        // A revival resets the answer while the field has focus: it asks again without a keystroke.
        assert.equal(await page.evaluate('document.activeElement?.id'), FIELD.slice(1), 'the field still has focus');
        await setSignal(page, '_flt_flows', { status: '', message: '', checked: '' });
        await waitForState(page, 'valid', 'the answer after a reset in the focused field');

        // An answer for other text leaves the focused field checking; leaving the field asks again.
        await setSignal(page, '_flt_flows', { status: 'valid', message: '', checked: 'proto udp' });
        await waitForState(page, 'checking');
        await page.evaluate(`document.querySelector('${FIELD}').blur()`);
        await waitForState(page, 'valid', 'the answer after leaving the field');
        await page.evaluate(`document.querySelector('${FIELD}').focus()`);

        // nfdump quotes the filter back; its markup stays text (D21).
        await expectInvalid(
            page,
            async () => {
                const text = `proto "<b>${unknownProtocol()}</b>"`;
                await typeFilter(page, text);
                return text;
            },
            'invalid markup'
        );
        assert.equal(await page.evaluate(`document.querySelector('${STATUS} b')`), null, 'no element from the filter text');

        // An emptied field has nothing to announce, and Tab moves on out of it and on to Run.
        await typeFilter(page, '');
        await waitForState(page, 'empty');
        await press(page, 'Tab', 'Tab', 9);
        assert.notEqual(await page.evaluate('document.activeElement?.id'), FIELD.slice(1), 'Tab leaves the filter field');
        for (let i = 0; i < 40 && (await page.evaluate('document.activeElement?.id')) !== 'flowsRunSubmit'; i++) {
            await press(page, 'Tab', 'Tab', 9);
        }
        assert.equal(await page.evaluate('document.activeElement?.id'), 'flowsRunSubmit', 'Tab reaches the Run button');
        assert.equal(
            await page.evaluate(`document.querySelector('#flowsRun .kill').getClientRects().length`),
            0,
            'Kill is hidden while idle'
        );

        // A range change posts one estimate-query, and the figures follow it. The preset differs
        // from the current window, whatever the default range preference is.
        const current = await page.signalValues(['datestart', 'dateend']);
        const width = current.dateend - current.datestart;
        const preset = Math.abs(width - 604800) <= 300 ? '30d' : '7d';
        await sleep(500);
        log.clear();
        await page.setRangePreset(preset);
        await waitForCount(log, 'estimate-query', 1);
        await sleep(1500);
        assert.equal(log.count('estimate-query'), 1, `one estimate-query after the ${preset} preset, got ${log.count('estimate-query')}`);
        await estimateSettled(page, 'the estimate for the new range');
        const moved = await page.signalValue('_est_flows');
        assert.equal(moved.pending, false);
        assert.equal(moved.window, preset === '7d' ? '7 days' : '30 days', `estimate window: ${moved.window}`);

        // An advance inside the same 5 minute bucket posts nothing; crossing it posts. The server
        // pulls a live window pushed into the future back to now, which may cross once more.
        for (let attempt = 1; attempt <= 3; attempt++) {
            const b = await buckets(page);
            const shift = inBucketShift(b);
            if (shift === 0) {
                console.warn(`  SKIPPED: the in-bucket check, ${b.from}-${b.to} has no one second move inside its buckets`);
                break;
            }
            log.clear();
            await shiftWindow(page, shift);
            await sleep(1000);
            const moved = await buckets(page);
            if (moved.key !== b.key && attempt < 3) {
                // The window's end sat at its bucket's end, and the live window moved on meanwhile.
                console.warn(`  (a live advance crossed a bucket during the in-bucket check, ${b.to} to ${moved.to}; checking again)`);
                await sleep(1000);
                continue;
            }
            assert.equal(
                log.count('estimate-query'),
                0,
                `a ${shift} s move inside the bucket does not post (${b.from}-${b.to} to ${moved.from}-${moved.to});${recentPosts()}`
            );
            break;
        }
        log.clear();
        await shiftWindow(page, 300);
        await waitForCount(log, 'estimate-query', 1, 3000);
        await sleep(1000);
        assert.ok(log.count('estimate-query') >= 1, `crossing a bucket posts: ${log.names().join(', ')}`);

        // A real live advance (the 60 s tick on Flows, or a server push) posts only across a bucket.
        if (process.env.E2E_FAST) {
            console.warn('  SKIPPED: the real live tick check (E2E_FAST is set)');
        } else {
            await reloadFlows(page);
            await estimateSettled(page, 'the estimate after reload');
            if ((await page.signalValue('range_live')) !== true) {
                console.warn('  SKIPPED: the real live tick check, the range after reload is not live');
            } else {
                const live = await observeLiveAdvance(page, log);
                ({ before, after } = live);
                assert.equal(
                    log.count('estimate-query'),
                    before.key === after.key ? 0 : 1,
                    `live advance ${before.from}-${before.to} to ${after.from}-${after.to}, estimate-query x${log.count('estimate-query')};${recentPosts()}`
                );
                console.log(
                    `  (live window moved by ${after.to - before.to} s after ${live.tookMs} ms, ${before.key === after.key ? 'same' : 'next'} bucket, ${log.names().join(', ') || 'no posts'})`
                );
            }
        }

        // Forced colors: the status glyph and the estimate keep their shapes (V-A11Y).
        await expectInvalid(
            page,
            async () => {
                const text = `proto ${unknownProtocol()}`;
                await typeFilter(page, text);
                return text;
            },
            'the invalid state for the screenshot'
        );
        await page.withForcedColors(async () => {
            await sleep(300);
            await page.screenshot('/tmp/filter-validation-forced-colors.png');
        });
        await typeFilter(page, '');
        await waitForState(page, 'empty');

        // A filter that arrives with the page is checked and describes the field, but is not announced.
        await reloadFlows(page);
        await setSignal(page, 'flows_filter', 'proto tcp');
        await waitForState(page, 'valid', 'the answer for a loaded filter');
        const loaded = await statusOf(page);
        assert.equal(loaded.live, 'Valid filter', 'the field is described by the answer for its loaded filter');
        assert.equal(loaded.politeness, 'off', 'the answer for a loaded filter is not announced');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    filterValidationTest()
        .then(() => console.log('filter-validation: PASS'))
        .catch((e) => {
            console.error('filter-validation: FAIL\n', e);
            process.exit(1);
        });
}
