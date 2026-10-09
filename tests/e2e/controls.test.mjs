// The global controls bar (spec 4.0.2): presets, custom duration, step buttons, the derived
// range label, absolute entry in the display timezone, the sources, protocol and unit
// controls, the range, absolute range and sources popovers (POPOVER-SPEC PB1) by pointer, keyboard
// and touch, and the import chip. The last part triggers an import on the "test" profile;
// E2E_SKIP_MUTATING=1 leaves that part out.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const SKIP_MUTATING = ['1', 'true', 'yes'].includes(String(process.env.E2E_SKIP_MUTATING ?? '').toLowerCase());

const POPOVERS = ['rangeMenu', 'rangeDisplay'];
const RANGE = "document.getElementById('rangeMenu')";
const RANGE_TOGGLE = "document.querySelector('#rangeMenu .menu-toggle')";
const DISPLAY = "document.getElementById('rangeDisplay')";
const DISPLAY_TOGGLE = "document.querySelector('#rangeDisplay .menu-toggle')";
// The sources picker is an sb-select (#177): its field, list and rows live in its shadow root.
const SOURCES = "document.getElementById('sourcesSelect')";
const SOURCES_INPUT = `${SOURCES}.shadowRoot.querySelector('input')`;
const SOURCES_ROWS = `[...${SOURCES}.shadowRoot.querySelectorAll('[role=option]')]`;
// Actions that read capture files; opening a popover or changing the range posts none of them.
const CAPTURE_READS = ['stats-actions', 'flow-actions', 'conversations-run', 'run-filtered-graph', 'talkers-panel', 'overview-topn-run'];

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

async function press(page, key, code = key, keyCode = 0) {
    const text = { Enter: '\r', ' ': ' ' }[key];
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode, text });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode });
    await sleep(120);
}

/** A real press and release in the middle of the element. */
async function clickAt(page, expr) {
    const { x, y } = await page.evaluate(`(function(){
        var r = (${expr}).getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    })()`);
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
    await sleep(200);
}

/** A touch tap in the middle of the element. */
async function tapAt(page, expr) {
    const { x, y } = await page.evaluate(`(function(){
        var r = (${expr}).getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    })()`);
    await page.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await sleep(80);
    await page.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await sleep(400);
}

/** A popover's state and where the focus is: 'trigger', a preset id, or the focused element's id. */
const popoverState = (id) => `(function(){
    var host = document.getElementById(${JSON.stringify(id)});
    var trigger = host.querySelector('[slot="trigger"]');
    var active = document.activeElement;
    return {
        open: host.open === true && host.shadowRoot.querySelector('.pop').matches(':popover-open'),
        expanded: trigger.getAttribute('aria-expanded'),
        focus: active === trigger ? 'trigger' : (active && (active.dataset.rangePreset || active.id || active.localName)) || null,
    };
})()`;

/** Whether a popover is open, and the rounded viewport boxes of its trigger and panel. */
const popoverBoxes = (id) => `(function(){
    var host = document.getElementById(${JSON.stringify(id)});
    var pop = host.shadowRoot.querySelector('.pop');
    var round = function(r){ return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right) }; };
    return {
        open: host.open === true && pop.matches(':popover-open'),
        trigger: round(host.querySelector('[slot="trigger"]').getBoundingClientRect()),
        panel: round(pop.getBoundingClientRect()),
        view: { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight },
    };
})()`;

/** Every item of the bar's first row has its middle inside that row's first item. */
const ONE_ROW = `(function(){
    var bar = document.querySelector('.controls-bar');
    var items = [...bar.querySelectorAll('.profile-select, .menu-toggle, .button-group, .protocol-select, .segmented, #reloadButton')]
        .filter(function(el){ return el.getClientRects().length > 0 && !el.closest('.popover-list'); });
    var first = items[0].getBoundingClientRect();
    return items.every(function(el){ var r = el.getBoundingClientRect(), mid = (r.top + r.bottom) / 2; return mid > first.top && mid < first.bottom; });
})()`;

/** One row at 1280 px with the sidebar expanded and no import running (4.0.2), in both table densities. */
async function oneRow() {
    await withPage(
        async (page) => {
            await page.navigate(BASE + '/#/overview');
            await page.waitForBoot();
            await page.waitForPage('overview');
            const importing = await page.signalValue('import_running');
            if (!importing) assert.ok(await page.evaluate(ONE_ROW), 'the controls fit one row at 1280 px');
            assert.equal(await page.evaluate(`document.body.dataset.sidebar`), 'expanded');

            // POPOVER-SPEC PC1, PC2: each popover shows its own trigger, which sb-popover marks as opening a dialog.
            assert.deepEqual(
                await page.evaluate(`${JSON.stringify(POPOVERS)}.map(function(id){
                    var host = document.getElementById(id);
                    var trigger = host.querySelector('[slot="trigger"]');
                    return {
                        id: id,
                        defined: host.localName === 'sb-popover' && typeof host.show === 'function',
                        slotted: host.shadowRoot.querySelector('slot[name="trigger"]').assignedElements().length,
                        toggle: trigger.classList.contains('menu-toggle'),
                        haspopup: trigger.getAttribute('aria-haspopup'),
                        expanded: trigger.getAttribute('aria-expanded'),
                        controls: trigger.getAttribute('aria-controls'),
                        wrapped: !!host.closest('.menu'),
                    };
                })`),
                POPOVERS.map((id) => ({
                    id,
                    defined: true,
                    slotted: 1,
                    toggle: true,
                    haspopup: 'dialog',
                    expanded: 'false',
                    controls: null,
                    wrapped: false,
                }))
            );
            assert.deepEqual(
                await page.evaluate(
                    `['rangeMenuList', 'rangeDisplayList'].map(function(id){ var el = document.getElementById(id); return el.parentElement.localName + ' ' + el.hasAttribute('popover'); })`
                ),
                ['sb-popover false', 'sb-popover false'],
                'the lists keep their ids in the popovers, without a popover attribute of their own'
            );

            // Table density changes nothing about the bar or its popovers (POPOVER-SPEC section 6, item 12).
            const looks = () =>
                page.evaluate(`${JSON.stringify(POPOVERS)}.map(function(id){
                    var r = document.querySelector('#' + id + ' [slot="trigger"]').getBoundingClientRect();
                    return [Math.round(r.width), Math.round(r.height)];
                })`);
            const density = await page.evaluate(`document.documentElement.dataset.density`);
            const before = await looks();
            await page.evaluate(
                `document.documentElement.dataset.density = ${JSON.stringify(density === 'compact' ? 'comfortable' : 'compact')}`
            );
            try {
                assert.deepEqual(await looks(), before, 'the triggers keep their size in the other density');
                if (!importing) assert.ok(await page.evaluate(ONE_ROW), 'one row in the other density too');
                await page.evaluate(`${RANGE_TOGGLE}.click()`);
                await page.waitFor(`${RANGE}.open === true`, { label: 'the range popover in the other density' });
                const { panel, view } = await page.evaluate(popoverBoxes('rangeMenu'));
                assert.ok(
                    panel.top >= 0 && panel.bottom <= view.height,
                    `the range panel is inside the viewport: ${JSON.stringify(panel)}`
                );
                await press(page, 'Escape', 'Escape', 27);
            } finally {
                await page.evaluate(`document.documentElement.dataset.density = ${JSON.stringify(density)}`);
            }
            const errors = page.realErrors();
            assert.deepEqual(errors, [], `expected no console errors, got:\n${errors.join('\n')}`);
        },
        { width: 1280, height: 900 }
    );
}

/** Holds every request matching `urlPattern` until the returned function is called. */
async function hold(page, urlPattern) {
    const held = [];
    let holding = true;
    page.ws.addEventListener('message', (event) => {
        const msg = JSON.parse(event.data);
        if (msg.method !== 'Fetch.requestPaused') return;
        if (holding) held.push(msg.params.requestId);
        else page.send('Fetch.continueRequest', { requestId: msg.params.requestId }).catch(() => {});
    });
    await page.send('Fetch.enable', { patterns: [{ urlPattern, requestStage: 'Request' }] });
    return async () => {
        holding = false;
        for (const requestId of held.splice(0)) await page.send('Fetch.continueRequest', { requestId }).catch(() => {});
        await page.send('Fetch.disable').catch(() => {});
    };
}

/** Until sb-popover is defined, the bar shows the two triggers and none of their panels, at its final height. */
async function beforeDefinition() {
    await withPage(
        async (page) => {
            const looks = `(function(){
                var status = document.querySelector('.controls-status');
                return {
                    defined: !!customElements.get('sb-popover'),
                    height: Math.round(document.querySelector('.controls-bar').getBoundingClientRect().height),
                    chip: !status.hidden,
                    triggers: ${JSON.stringify(POPOVERS)}.filter(function(id){ return document.querySelector('#' + id + ' [slot="trigger"]').checkVisibility(); }),
                    shown: ['#rangeMenuList', '#rangeDisplayList', '#rangeFrom', '#customDurationValue', '[data-range-preset]']
                        .filter(function(sel){ return document.querySelector(sel).checkVisibility(); }),
                };
            })()`;
            const release = await hold(page, '*/popover.js*');
            let early;
            try {
                page.expectNavigation();
                await page.send('Page.navigate', { url: BASE + '/#/overview' });
                await page.waitFor(`document.readyState !== 'loading' && !!document.querySelector('.controls-bar')`, {
                    label: 'the page parsed while popover.js is held',
                });
                await page.evaluate(`new Promise(function(r){ requestAnimationFrame(function(){ requestAnimationFrame(r); }); })`);
                early = await page.evaluate(looks);
            } finally {
                await release();
            }
            assert.deepEqual(
                { defined: early.defined, triggers: early.triggers, shown: early.shown },
                { defined: false, triggers: POPOVERS, shown: [] },
                'before sb-popover is defined the bar shows the triggers and no panel content'
            );
            await page.waitForBoot();
            await page.waitFor(`${POPOVERS.map((id) => `typeof document.getElementById('${id}').show === 'function'`).join(' && ')}`, {
                label: 'the two popovers to be defined',
            });
            const late = await page.evaluate(looks);
            if (late.chip === early.chip) assert.equal(late.height, early.height, 'the bar keeps its height once sb-popover is defined');
            const errors = page.realErrors();
            assert.deepEqual(errors, [], `expected no console errors, got:\n${errors.join('\n')}`);
        },
        { width: 1280, height: 900 }
    );
}

// POPOVER-SPEC 6, item 4: /_sse is held, because the load-time sync morphs the bar about 130 ms after navigation
// and would replace the first load's bindings before a hand could use them.
async function presetRightAway() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/#/overview');
        await page.waitForBoot();
        await page.waitForPage('overview');
        const log = await page.requestLog();
        // Once the next frame is painted: the earliest a hand can press what that frame shows.
        const { identifier } = await page.send('Page.addScriptToEvaluateOnNewDocument', {
            source: `(function(){
                if (window.top !== window) return;
                var painted = function(fn){ requestAnimationFrame(function(){ setTimeout(fn, 0); }); };
                window.__e2eEarly = {};
                document.addEventListener('DOMContentLoaded', function(){
                    var bar = document.querySelector('.controls-bar');
                    bar.setAttribute('data-e2e-unsynced', '');
                    (function poll(){
                        var host = document.getElementById('rangeMenu');
                        var entry = document.getElementById('rangeDisplay');
                        var ready = function(el){ return typeof el.show === 'function' && !!el.shadowRoot; };
                        if (!ready(host) || !ready(entry)) return requestAnimationFrame(poll);
                        var pick = ['7d', '24h'].find(function(id){ return document.querySelector('[data-range-preset="' + id + '"]').getAttribute('aria-pressed') !== 'true'; });
                        var from = document.getElementById('rangeFrom');
                        Object.assign(window.__e2eEarly, { ready: performance.now(), pick: pick });
                        painted(function(){
                            // Empty whatever the browser restored, so only the trigger's prefill can fill it.
                            from.value = '';
                            entry.querySelector('[slot="trigger"]').click();
                            painted(function(){
                                Object.assign(window.__e2eEarly, {
                                    entryOpen: entry.open === true,
                                    entryFocus: document.activeElement === from,
                                    prefill: from.value,
                                    entryUnsynced: bar.hasAttribute('data-e2e-unsynced'),
                                });
                                entry.querySelector('[slot="trigger"]').click();
                                window.__e2eEarly.entryClosed = entry.open === false;
                                painted(function(){
                                    host.querySelector('[slot="trigger"]').click();
                                    painted(function(){
                                        var open = host.open === true;
                                        var first = document.activeElement === document.querySelector('[data-range-preset]');
                                        document.querySelector('[data-range-preset="' + pick + '"]').click();
                                        Object.assign(window.__e2eEarly, {
                                            open: open,
                                            first: first,
                                            closed: host.open === false,
                                            unsynced: bar.hasAttribute('data-e2e-unsynced'),
                                            chosen: performance.now(),
                                        });
                                    });
                                });
                            });
                        });
                    })();
                });
            })()`,
        });
        const release = await hold(page, '*/_sse*');
        let early;
        try {
            log.clear();
            await page.reload();
            await page.waitFor('window.__e2eEarly?.chosen > 0', { timeout: 15000, label: 'a preset picked right after the reload' });
            early = await page.evaluate('window.__e2eEarly');
        } finally {
            await release();
            await page.send('Page.removeScriptToEvaluateOnNewDocument', { identifier }).catch(() => {});
        }
        assert.deepEqual(
            { open: early.entryOpen, focus: early.entryFocus, closed: early.entryClosed, unsynced: early.entryUnsynced },
            { open: true, focus: true, closed: true, unsynced: true },
            'before any sync, the absolute range trigger opened its entry on From and closed it again'
        );
        assert.match(early.prefill ?? '', /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/, 'before any sync, the trigger prefilled From');
        assert.deepEqual(
            { open: early.open, first: early.first, closed: early.closed, unsynced: early.unsynced },
            { open: true, first: true, closed: true, unsynced: true },
            'before any sync, the trigger opened the range popover on its first preset and the pick closed it'
        );
        assert.ok(early.chosen < 1000, `the preset was picked within the first second (${Math.round(early.chosen)} ms)`);
        const seconds = { '7d': 604800, '24h': 86400 }[early.pick];
        const start = Date.now();
        for (;;) {
            const r = await page.signalValues(['datestart', 'dateend', 'range_live', 'range_preset']);
            if (r.range_live && r.range_preset === early.pick && Math.abs(r.dateend - r.datestart - seconds) <= 1) break;
            if (Date.now() - start > 8000)
                throw new Error(`the ${early.pick} preset picked right away did not apply: ${JSON.stringify(r)}`);
            await sleep(150);
        }
        assert.equal(log.count('set-range'), 1, 'the pick posted set-range once');
        await page.waitFor(`${RANGE_TOGGLE}.getAttribute('aria-expanded') === 'false'`, { label: 'the trigger to say closed' });
        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors picking a preset right away, got:\n${errors.join('\n')}`);
    });
}

// The same before any sync for the keyboard into the absolute range and the sources' bindings. Its own reload:
// an apply-globals while /_sse is held would post the range from before presetRightAway's pick.
async function keysRightAway() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/#/overview');
        await page.waitForBoot();
        await page.waitForPage('overview');
        const log = await page.requestLog();
        const { identifier } = await page.send('Page.addScriptToEvaluateOnNewDocument', {
            source: `document.addEventListener('DOMContentLoaded', function(){ document.querySelector('.controls-bar')?.setAttribute('data-e2e-unsynced', ''); })`,
        });
        const release = await hold(page, '*/_sse*');
        try {
            await page.reload();
            await page.waitFor(`${POPOVERS.map((id) => `typeof document.getElementById('${id}').show === 'function'`).join(' && ')}`, {
                label: 'the three popovers right after the reload',
            });
            // Enter opens the absolute range on From with its prefill.
            await page.evaluate(`document.getElementById('rangeFrom').value = ''; ${DISPLAY_TOGGLE}.focus()`);
            await press(page, 'Enter', 'Enter', 13);
            const entry = await page.evaluate(
                `({ state: ${popoverState('rangeDisplay')}, from: document.getElementById('rangeFrom').value })`
            );
            assert.deepEqual(
                entry.state,
                { open: true, expanded: 'true', focus: 'rangeFrom' },
                'before any sync, Enter opens the absolute range'
            );
            assert.match(entry.from, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/, 'before any sync, Enter prefilled From');
            await press(page, 'Escape', 'Escape', 27);
            assert.deepEqual(await page.evaluate(popoverState('rangeDisplay')), { open: false, expanded: 'false', focus: 'trigger' });

            assert.equal(
                await page.evaluate(`document.querySelector('.controls-bar').hasAttribute('data-e2e-unsynced')`),
                true,
                'all of it ran before any sync'
            );
        } finally {
            await release();
            await page.send('Page.removeScriptToEvaluateOnNewDocument', { identifier }).catch(() => {});
        }
        assert.equal(log.count('set-range'), 0, 'none of it changed the range');
        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors before the first sync, got:\n${errors.join('\n')}`);
    });
}

// POPOVER-SPEC 6, item 11, and 7: the focus that opening the absolute range puts on From must not open a picker.
async function onPhone() {
    await withPage(
        async (page) => {
            await page.navigate(BASE + '/#/overview');
            await page.waitForBoot();
            await page.waitForPage('overview');
            const log = await page.requestLog();
            await tapAt(page, `document.getElementById('controlsMore')`);
            await page.waitFor(
                `document.querySelector('.controls-bar').dataset.more === 'open' && ${DISPLAY_TOGGLE}.getClientRects().length > 0`,
                {
                    label: 'More controls to show the absolute range',
                }
            );
            for (const [label, id] of [
                ['Range', 'rangeMenu'],
                ['Absolute range', 'rangeDisplay'],
            ]) {
                const trigger = `document.querySelector('#${id} [slot="trigger"]')`;
                const before = await page.evaluate(popoverBoxes(id));
                await tapAt(page, trigger);
                const { open, trigger: moved, panel, view } = await page.evaluate(popoverBoxes(id));
                assert.equal(open, true, `a tap opens ${label}`);
                assert.equal(view.width, 390);
                assert.deepEqual(moved, before.trigger, `${label}: the trigger stays where it was`);
                const inside = panel.left >= 0 && panel.top >= 0 && panel.right <= view.width && panel.bottom <= view.height;
                assert.ok(
                    inside && panel.bottom > panel.top,
                    `${label}: the panel lies inside the viewport: ${JSON.stringify({ panel, view })}`
                );
                if (id === 'rangeDisplay') {
                    const from = await page.evaluate(`(function(){
                        var field = document.getElementById('rangeFrom');
                        return { focused: document.activeElement === field, picker: field.matches(':open'), value: field.value };
                    })()`);
                    assert.equal(from.focused, true, 'opening the absolute range focuses its From field');
                    assert.equal(from.picker, false, 'the focus alone opens no date picker');
                    assert.match(from.value, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/, 'the tap prefilled the entry');
                    // The check above means something: a tap on the field does open the picker.
                    await tapAt(page, `document.getElementById('rangeFrom')`);
                    assert.equal(
                        await page.evaluate(`document.getElementById('rangeFrom').matches(':open')`),
                        true,
                        'a tap on From opens its picker'
                    );
                    assert.equal(await page.evaluate(`${DISPLAY}.open`), true, 'the picker keeps the popover open');
                    await press(page, 'Escape', 'Escape', 27);
                    await page.waitFor(`!document.getElementById('rangeFrom').matches(':open')`, { label: 'Escape to close the picker' });
                }
                await press(page, 'Escape', 'Escape', 27);
                await page.waitFor(`!${popoverBoxes(id)}.open`, { label: `${label}: Escape to close it` });
            }
            assert.equal(log.count('set-range'), 0, 'opening the popovers changed no range');
            for (const name of CAPTURE_READS) assert.equal(log.count(name), 0, `${name} was never posted on a phone`);
            const errors = page.realErrors();
            assert.deepEqual(errors, [], `expected no console errors on a phone, got:\n${errors.join('\n')}`);
        },
        { width: 390, height: 844, mobile: true }
    );
}

export default async function controlsTest() {
    await oneRow();
    await beforeDefinition();

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
        const click = (selector) => page.evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
        const closedOnTrigger = { open: false, expanded: 'false', focus: 'trigger' };
        // A sync that morphs the popover's panel, which drops the probe attribute set on it beforehand.
        const syncMorphing = async (listId) => {
            await page.evaluate(`document.getElementById('${listId}').setAttribute('data-e2e-probe', '')`);
            await page.syncNow('overview');
            await page.waitFor(`!document.getElementById('${listId}').hasAttribute('data-e2e-probe')`, {
                label: `the sync to morph #${listId}`,
            });
        };

        // Presets: live windows of their width, named by the trigger and marked in the list; choosing one
        // closes the popover and gives the focus back to its trigger.
        for (const [id, seconds, text] of [
            ['1h', 3600, 'Last 1 hour'],
            ['7d', 604800, 'Last 7 days'],
            ['24h', 86400, 'Last 24 hours'],
        ]) {
            await page.setRangePreset(id);
            const r = await until(`preset ${id}`, (x) => x.live === true && x.preset === id);
            assert.ok(Math.abs(r.to - r.from - seconds) <= 1, `preset ${id} is ${seconds} s wide`);
            await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === ${JSON.stringify(text)}`, { label: `trigger "${text}"` });
            assert.equal(await page.evaluate(`document.querySelector('[data-range-preset="${id}"]').getAttribute('aria-pressed')`), 'true');
            assert.deepEqual(
                await page.evaluate(popoverState('rangeMenu')),
                closedOnTrigger,
                `choosing ${id} closed the popover onto its trigger`
            );
        }

        // Custom duration: invalid input keeps the popover open and posts nothing, 6 h applies.
        await page.evaluate(`${RANGE_TOGGLE}.click()`);
        await page.waitFor(`${RANGE}.open === true && document.getElementById('customDurationValue').checkVisibility()`, {
            label: 'custom duration row',
        });
        log.clear();
        await page.setInputValue('#customDurationValue', '0');
        await click('#customDurationApply');
        await sleep(400);
        assert.equal(log.count('set-range'), 0, 'an invalid duration posts nothing');
        assert.deepEqual(
            await page.evaluate(`[${RANGE}.open, ${RANGE_TOGGLE}.getAttribute('aria-expanded')]`),
            [true, 'true'],
            'the popover stays open'
        );
        await page.setInputValue('#customDurationValue', '6');
        await page.setSelectValue('#customDurationUnit', 'h');
        await page.evaluate(`document.getElementById('customDurationValue').focus()`);
        await press(page, 'Enter', 'Enter', 13);
        let r = await until('6 hours', (x) => x.preset === 'custom' && Math.abs(x.to - x.from - 21600) <= 1);
        assert.equal(r.live, true);
        await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === 'Last 6 hours'`, { label: 'trigger "Last 6 hours"' });
        assert.deepEqual(
            await page.evaluate(popoverState('rangeMenu')),
            closedOnTrigger,
            'Enter in the duration applied it and closed the popover'
        );

        // Back, forward, now.
        await page.setRangePreset('7d');
        const week = await until('7d', (x) => x.preset === '7d' && x.live);
        await page.waitFor(`document.getElementById('rangeForward').disabled`, { label: 'Forward disabled while live' });
        await click('#rangeBack');
        r = await until('back', (x) => x.live === false);
        // A live window starts a width before now, so a few seconds on the way count too.
        assert.ok(r.to >= week.from && r.to - week.from <= 10, `back ends where the live window starts, got ${r.to} for ${week.from}`);
        assert.equal(r.to - r.from, week.to - week.from, 'back keeps the width');
        await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === '7 days'`, { label: 'trigger shows the width after Back' });
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
        await page.waitFor(`${RANGE_TOGGLE}.textContent.trim() === 'Last 7 days'`, { label: 'trigger back to the preset' });

        // Absolute range in the browser's timezone (emulated Asia/Kolkata, UTC+05:30).
        const openEntry = async () => {
            await page.evaluate(`${DISPLAY}.open || ${DISPLAY_TOGGLE}.click()`);
            await page.waitFor(`${DISPLAY}.open === true && document.getElementById('rangeFrom').checkVisibility()`, {
                label: 'absolute range entry',
            });
        };

        // An entry that reaches now is live, and Previous range brings it back live at its width,
        // not rounded to whole hours.
        await openEntry();
        assert.equal(await page.evaluate(`document.activeElement.id`), 'rangeFrom', 'opening the entry focuses From');
        const nowS = Math.floor(Date.now() / 1000);
        const localAt = (epoch) => page.evaluate(`window.nfsenTime.toLocalInput(${epoch}, 'browser', '')`);
        await page.setInputValue('#rangeFrom', await localAt(nowS - 2700));
        await page.setInputValue('#rangeTo', await localAt(nowS + 60));
        await click('#rangeApply');
        const shortLive = await until('a live 45 minute window', (x) => x.live && x.preset === 'custom' && x.to - x.from < 3600);
        assert.deepEqual(await page.evaluate(popoverState('rangeDisplay')), closedOnTrigger, 'Apply closed the entry onto its trigger');
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
        // Enter in a field applies like Apply, and the closed entry stays closed on its trigger.
        log.clear();
        await page.evaluate(`document.getElementById('rangeTo').focus()`);
        await press(page, 'Enter', 'Enter', 13);
        r = await until('absolute range', (x) => x.from === browserFrom.epoch && x.to === browserTo.epoch);
        assert.equal(r.live, false);
        assert.deepEqual(
            await page.evaluate(popoverState('rangeDisplay')),
            closedOnTrigger,
            'Enter in To closed the entry onto its trigger'
        );
        assert.equal(log.count('set-range'), 1, 'Enter in To posted set-range once');
        const shown = await page.evaluate(`${DISPLAY_TOGGLE}.textContent`);
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
        await page.evaluate(`document.getElementById('rangeFrom').focus()`);
        await press(page, 'Enter', 'Enter', 13);
        await until('absolute range in server time', (x) => x.from === serverFrom.epoch && x.to === serverTo.epoch);
        assert.deepEqual(
            await page.evaluate(popoverState('rangeDisplay')),
            closedOnTrigger,
            'Enter in From closed the entry onto its trigger'
        );

        // An end before the start stays in the popover with a message.
        await openEntry();
        log.clear();
        await page.setInputValue('#rangeFrom', serverTo.local);
        await page.setInputValue('#rangeTo', serverFrom.local);
        await click('#rangeApply');
        await page.waitFor(`document.querySelector('.range-entry [role=alert]').textContent.includes('end after it starts')`, {
            label: 'the entry error',
        });
        assert.equal(log.count('set-range'), 0, 'a backwards range posts nothing');
        assert.equal(await page.evaluate(`${DISPLAY}.open`), true, 'the entry stays open with its error');

        // Escape closes the popover and gives the focus back to its trigger.
        await page.evaluate(`document.getElementById('rangeFrom').focus()`);
        await press(page, 'Escape', 'Escape', 27);
        await page.waitFor(`document.activeElement === ${DISPLAY_TOGGLE}`, { label: 'focus back on the range display trigger' });
        assert.deepEqual(await page.evaluate(popoverState('rangeDisplay')), closedOnTrigger);

        // Space opens the absolute range on From with its prefill; Enter runs before any sync, in presetRightAway.
        for (const [label, host, toggle, first, key, code, keyCode] of [
            ['absolute range', DISPLAY, DISPLAY_TOGGLE, `document.getElementById('rangeFrom')`, ' ', 'Space', 32],
        ]) {
            await page.evaluate(`document.getElementById('rangeFrom').value = ''; ${toggle}.focus()`);
            await press(page, key, code, keyCode);
            await page.waitFor(`${host}.open === true && document.activeElement === ${first}`, {
                label: `${code} on the ${label} trigger to open it on its first field`,
            });
            if (host === DISPLAY) {
                assert.match(
                    await page.evaluate(`document.getElementById('rangeFrom').value`),
                    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/,
                    `${code} prefilled From`
                );
            }
            await press(page, 'Escape', 'Escape', 27);
            await page.waitFor(`${host}.open === false && document.activeElement === ${toggle}`, {
                label: `Escape after ${code} on the ${label} trigger`,
            });
        }

        // Keyboard: Enter and Space open the range popover on the first preset, Escape closes it again.
        const presets = await page.evaluate(
            `[...document.querySelectorAll('#rangeMenuList [data-range-preset]')].map(function(b){ return b.dataset.rangePreset; })`
        );
        const [firstPreset, lastPreset] = [presets[0], presets.at(-1)];
        await page.evaluate(`${RANGE_TOGGLE}.focus()`);
        for (const [key, code, keyCode] of [
            ['Enter', 'Enter', 13],
            [' ', 'Space', 32],
            ['ArrowDown', 'ArrowDown', 40],
        ]) {
            await press(page, key, code, keyCode);
            await page.waitFor(`${RANGE}.open === true && document.activeElement?.dataset.rangePreset === '${firstPreset}'`, {
                label: `${code} on the trigger to open the range popover on ${firstPreset}`,
            });
            await press(page, 'Escape', 'Escape', 27);
            await page.waitFor(`${RANGE}.open === false && document.activeElement === ${RANGE_TOGGLE}`, { label: `Escape after ${code}` });
        }
        // ArrowUp opens on the list's last item, the duration's Apply; the arrows skip its field and select.
        await press(page, 'ArrowUp', 'ArrowUp', 38);
        await page.waitFor(`${RANGE}.open === true && document.activeElement?.id === 'customDurationApply'`, {
            label: 'ArrowUp on the trigger to open the range popover on Apply',
        });
        for (const [key, code, keyCode, to] of [
            ['ArrowDown', 'ArrowDown', 40, firstPreset],
            ['ArrowDown', 'ArrowDown', 40, presets[1]],
            ['End', 'End', 35, 'customDurationApply'],
            ['ArrowUp', 'ArrowUp', 38, lastPreset],
            ['Home', 'Home', 36, firstPreset],
            ['ArrowUp', 'ArrowUp', 38, 'customDurationApply'],
        ]) {
            await press(page, key, code, keyCode);
            assert.deepEqual(
                await page.evaluate(popoverState('rangeMenu')),
                { open: true, expanded: 'true', focus: to },
                `${key} moves to ${to}`
            );
        }
        // The number field keeps its own arrows.
        await page.evaluate(`(function(){ var f = document.getElementById('customDurationValue'); f.value = '5'; f.focus(); })()`);
        await press(page, 'ArrowUp', 'ArrowUp', 38);
        assert.deepEqual(
            await page.evaluate(`[document.activeElement.id, document.getElementById('customDurationValue').value]`),
            ['customDurationValue', '6'],
            'ArrowUp in the duration field steps its number'
        );
        // Tab past the last item leaves the popover, which closes without pulling the focus back.
        await page.evaluate(`document.getElementById('customDurationApply').focus()`);
        await press(page, 'Tab', 'Tab', 9);
        await page.waitFor(`${RANGE}.open === false`, { label: 'Tab out of the range popover to close it' });
        assert.equal(
            await page.evaluate(`document.activeElement !== document.body && !${RANGE}.contains(document.activeElement)`),
            true,
            'the focus moved on past the popover'
        );
        // ArrowDown opens on the first preset, Enter picks it and the focus is back on the trigger.
        await page.evaluate(`${RANGE_TOGGLE}.focus()`);
        await press(page, 'ArrowDown', 'ArrowDown', 40);
        await page.waitFor(`document.activeElement?.dataset.rangePreset === '${firstPreset}'`, {
            label: 'ArrowDown onto the first preset',
        });
        await press(page, 'Enter', 'Enter', 13);
        await until('keyboard preset', (x) => x.preset === firstPreset && x.live);
        assert.deepEqual(
            await page.evaluate(popoverState('rangeMenu')),
            closedOnTrigger,
            'Enter on a preset closed the popover onto its trigger'
        );

        // An outside press closes it and leaves the focus where the press put it.
        await page.evaluate(`${RANGE_TOGGLE}.click()`);
        await page.waitFor(`${RANGE}.open === true`, { label: 'the range popover for an outside press' });
        await clickAt(page, `document.getElementById('pageTitle')`);
        await page.waitFor(`${RANGE}.open === false`, { label: 'an outside press to close the range popover' });
        const pressed = await page.evaluate(popoverState('rangeMenu'));
        assert.deepEqual(pressed, { open: false, expanded: 'false', focus: 'pageTitle' }, 'the focus stays where the press put it');

        // A sync morph keeps the popover open with the typed duration, its unit and the focus (POPOVER-SPEC 6, item 1).
        await page.evaluate(`${RANGE_TOGGLE}.click()`);
        await page.waitFor(`${RANGE}.open === true`, { label: 'the range popover before a sync' });
        await page.evaluate(`(function(){
            var f = document.getElementById('customDurationValue');
            f.value = '';
            f.focus();
            document.getElementById('customDurationUnit').value = 'd';
            window.__e2eDuration = f;
        })()`);
        await page.send('Input.insertText', { text: '12' });
        await syncMorphing('rangeMenuList');
        assert.deepEqual(
            await page.evaluate(`(function(){
                var s = ${popoverState('rangeMenu')};
                var f = document.getElementById('customDurationValue');
                return { open: s.open, expanded: s.expanded, same: document.activeElement === window.__e2eDuration && f === window.__e2eDuration, value: f.value, unit: document.getElementById('customDurationUnit').value };
            })()`),
            { open: true, expanded: 'true', same: true, value: '12', unit: 'd' },
            'a sync keeps the range popover open with the typed duration and the focus'
        );
        await press(page, 'Escape', 'Escape', 27);
        await page.waitFor(`${RANGE}.open === false && document.activeElement === ${RANGE_TOGGLE}`, { label: 'Escape after the sync' });

        // The same for the absolute range: both fields filled, the focus in From after a key changed it.
        await openEntry();
        await page.setInputValue('#rangeFrom', '2026-03-14T09:30');
        await page.setInputValue('#rangeTo', '2026-03-15T18:45');
        await page.evaluate(`(function(){ var f = document.getElementById('rangeFrom'); f.focus(); window.__e2eFrom = f; })()`);
        await press(page, 'ArrowUp', 'ArrowUp', 38);
        const typedFrom = await page.evaluate(`document.getElementById('rangeFrom').value`);
        assert.notEqual(typedFrom, '2026-03-14T09:30', 'ArrowUp changed a part of From');
        assert.match(typedFrom, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
        await syncMorphing('rangeDisplayList');
        assert.deepEqual(
            await page.evaluate(`(function(){
                var s = ${popoverState('rangeDisplay')};
                var f = document.getElementById('rangeFrom');
                return { open: s.open, expanded: s.expanded, same: document.activeElement === window.__e2eFrom && f === window.__e2eFrom, from: f.value, to: document.getElementById('rangeTo').value };
            })()`),
            { open: true, expanded: 'true', same: true, from: typedFrom, to: '2026-03-15T18:45' },
            'a sync keeps the absolute range open with both dates and the focus in From'
        );
        await press(page, 'Escape', 'Escape', 27);
        await page.waitFor(`${DISPLAY}.open === false && document.activeElement === ${DISPLAY_TOGGLE}`, {
            label: 'Escape after the sync of the absolute range',
        });
        await page.setRangePreset('24h');

        // Sources (#176, #177): an sb-select. Nothing picked means every source; a pick posts once, and Clear and
        // Select all both go back to every source.
        const configured = JSON.parse(await page.evaluate(`${SOURCES}.getAttribute('options')`)).map((o) => o.value);
        const isAll = (v) => v.length === 0 || v.includes('any') || configured.every((s) => v.includes(s));
        const picker = () =>
            page.evaluate(`({
                placeholder: ${SOURCES_INPUT}.placeholder,
                summary: ${SOURCES}.shadowRoot.querySelector('[part~="summary"]')?.textContent.trim() ?? '',
                open: ${SOURCES}.shadowRoot.querySelector('[role=listbox]').matches(':popover-open'),
            })`);
        const choose = async (index, label) => {
            log.clear();
            await page.evaluate(`${SOURCES_ROWS}[${index}].dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true })), true`);
            await sleep(800);
            assert.equal(log.count('apply-globals'), 1, `${label} posts apply-globals once`);
        };
        assert.ok(isAll(await page.signalValue('graph_sources')), 'every source to start with');
        assert.equal((await picker()).placeholder, 'All sources', 'nothing picked reads All sources');
        await page.evaluate(`${SOURCES_INPUT}.click()`);
        await page.waitFor(`${SOURCES}.shadowRoot.querySelector('[role=listbox]').matches(':popover-open')`, { label: 'the sources list' });
        const rows = await page.evaluate(`${SOURCES_ROWS}.map(function(r){ return r.textContent.trim().replace(/\\s+/g, ' '); })`);
        assert.equal(rows.length, configured.length + 2, `Select all, Clear and one row per source: ${JSON.stringify(rows)}`);
        assert.deepEqual(rows.slice(0, 2), ['Select all', 'Clear']);
        await choose(2, 'a pick');
        if (configured.length > 1) {
            assert.deepEqual(await page.signalValue('graph_sources'), [configured[0]], 'the pick is the source set');
            assert.equal((await picker()).summary, `1 of ${configured.length} sources`, 'the closed field counts the picks');
            await choose(1, 'Clear');
            assert.ok(isAll(await page.signalValue('graph_sources')), 'Clear picks every source again');
            await choose(2, 'a pick');
            await choose(0, 'Select all');
            assert.ok(isAll(await page.signalValue('graph_sources')), 'Select all picks every source');
        } else {
            assert.ok(isAll(await page.signalValue('graph_sources')), 'the only source picked is every source');
        }
        assert.equal((await picker()).open, true, 'picking keeps the list open');
        await press(page, 'Escape', 'Escape', 27);
        await page.waitFor(`!${SOURCES}.shadowRoot.querySelector('[role=listbox]').matches(':popover-open')`, { label: 'Escape closes the sources list' });
        await page.waitFor(`${SOURCES_INPUT}.placeholder === 'All sources' && ${SOURCES_INPUT}.value === ''`, { label: 'All sources once the server agrees' });

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
        await press(page, arrowName(arrow), arrowName(arrow), arrow);
        await sleep(600);
        assert.equal(await page.signalValue('graph_trafficUnit'), other, `${arrowName(arrow)} picks ${other}`);
        assert.ok(log.count('apply-globals') >= 1, 'the unit posts apply-globals');
        await press(page, arrowName(back), arrowName(back), back);
        await sleep(600);
        assert.equal(await page.signalValue('graph_trafficUnit'), unit, 'and back');

        // No range op or global posts a capture-reading query.
        for (const name of CAPTURE_READS) assert.equal(log.count(name), 0, `${name} was never posted`);

        // Forced colours: the panel keeps its edge, the focused item its focus ring, and the current preset
        // its state ring and check mark; the screenshot is for the wave gate's manual look.
        await page.withForcedColors(async () => {
            await page.evaluate(`${RANGE_TOGGLE}.focus()`);
            await press(page, 'Enter', 'Enter', 13);
            // Past the panel's fade-in, which the screenshot would catch half transparent.
            await page.waitFor(`${RANGE}.open === true && getComputedStyle(${RANGE}.shadowRoot.querySelector('.pop')).opacity === '1'`, {
                label: 'the range popover in forced colours',
            });
            const ring = (expr) => `(function(el){
                var s = el && getComputedStyle(el);
                return s ? s.outlineStyle + ' ' + s.outlineWidth : null;
            })(${expr})`;
            const looks = await page.evaluate(`(function(){
                var panel = getComputedStyle(${RANGE}.shadowRoot.querySelector('.panel'));
                var active = document.activeElement;
                var current = document.querySelector('#rangeMenuList [aria-pressed="true"]');
                var mark = current && getComputedStyle(current, '::before');
                return {
                    edge: panel.outlineStyle !== 'none' && parseFloat(panel.outlineWidth) >= 1,
                    focus: active.dataset.rangePreset === ${JSON.stringify(firstPreset)} && active.matches(':focus-visible') && active !== current
                        && getComputedStyle(active).outlineStyle !== 'none' && parseFloat(getComputedStyle(active).outlineWidth) >= 1,
                    ring: !!current && getComputedStyle(current).outlineStyle !== 'none',
                    mark: !!mark && mark.content === '""' && mark.maskImage !== 'none' && mark.forcedColorAdjust === 'none' && parseFloat(mark.width) > 0,
                };
            })()`);
            assert.deepEqual(
                looks,
                { edge: true, focus: true, ring: true, mark: true },
                'forced colours keep the panel edge, the focus ring, the state ring and the check mark'
            );
            // On the current preset the focus ring shows on top of its state ring.
            const stateRing = await page.evaluate(ring(`document.querySelector('#rangeMenuList [aria-pressed="true"]')`));
            for (let i = 0; i < presets.length; i++) {
                if (await page.evaluate(`document.activeElement.getAttribute('aria-pressed') === 'true'`)) break;
                await press(page, 'ArrowDown', 'ArrowDown', 40);
            }
            assert.equal(
                await page.evaluate(`document.activeElement.getAttribute('aria-pressed')`),
                'true',
                'the arrows reach the current preset'
            );
            const focusedRing = await page.evaluate(ring('document.activeElement'));
            assert.ok(
                focusedRing && !focusedRing.startsWith('none') && focusedRing !== stateRing,
                `the focus ring shows on the current preset: ${stateRing} then ${focusedRing}`
            );
            await page.screenshot('/tmp/nfsen-controls-forced-colors.png');
            await press(page, 'Escape', 'Escape', 27);
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

    await presetRightAway();
    await keysRightAway();
    await onPhone();
}

if (import.meta.url === `file://${process.argv[1]}`) {
    controlsTest()
        .then(() => console.log('controls: PASS'))
        .catch((e) => {
            console.error('controls: FAIL\n', e);
            process.exit(1);
        });
}
