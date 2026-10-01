// The phone and tablet shell (spec 2.6, 4.9, 2.7.11, 5.5): tab bar and More, graph first, the
// phone-only "Show filters", swipes that scroll, "Select range" arming the graph's brush from the
// keyboard and across a move, nothing wider than 390 px, the tablet default.
import assert from 'node:assert/strict';
import { withPage, BASE, isBenignError } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const PAGES = ['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health', 'settings'];
const PHONE = { width: 390, height: 844, mobile: true };
const GRAPH = "document.getElementById('trafficGraph')";

const KEYS = {
    Escape: { code: 'Escape', keyCode: 27 },
    Enter: { code: 'Enter', keyCode: 13, text: '\r' },
    ' ': { code: 'Space', keyCode: 32, text: ' ' },
    Tab: { code: 'Tab', keyCode: 9 },
    ArrowDown: { code: 'ArrowDown', keyCode: 40 },
    ArrowUp: { code: 'ArrowUp', keyCode: 38 },
    Home: { code: 'Home', keyCode: 36 },
    End: { code: 'End', keyCode: 35 },
};

async function press(page, key) {
    const { code, keyCode, text } = KEYS[key];
    const base = { key, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode };
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(text ? { text } : {}) });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

/** A one-finger tap at the middle of the first element matching `selector`. */
async function tap(page, selector) {
    const at = await page.evaluate(`(function(){
        var r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();
        return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
    })()`);
    await page.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [at] });
    await page.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}

/** A one-finger vertical swipe from `from`, `distance` px upwards, as real touch events. */
async function swipe(page, from, distance) {
    const steps = 12;
    await page.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [from] });
    for (let i = 1; i <= steps; i++) {
        await page.send('Input.dispatchTouchEvent', {
            type: 'touchMove',
            touchPoints: [{ x: from.x, y: from.y - (distance * i) / steps }],
        });
        await sleep(16);
    }
    await page.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await sleep(700);
}

/** A one-finger drag across the traffic graph, over 60% to 90% of its stored data, as real touch events. */
async function dragAcrossGraph(page) {
    const { data_range_min: min } = await page.signalValues(['data_range_min']);
    await page.evaluate(`${GRAPH}.scrollIntoView({ block: 'center' })`);
    await sleep(300);
    const at = await page.evaluate(`(function(){
        var el = ${GRAPH}, c = el.chart, r = el.querySelector('.chart-canvas').getBoundingClientRect();
        var src = c.getOption().dataset[0].source, g = c.getModel().getComponent('grid').coordinateSystem.getRect();
        var first = Math.max(src[0][0], ${min} * 1000), last = src[src.length - 1][0];
        var x = function(f){ return r.left + c.convertToPixel({ xAxisIndex: 0 }, first + (last - first) * f); };
        return { from: x(0.6), to: x(0.9), y: Math.round(r.top + g.y + g.height / 2) };
    })()`);
    const point = (i) => ({ x: Math.round(at.from + ((at.to - at.from) * i) / 10), y: at.y });
    await page.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [point(0)] });
    for (let i = 1; i <= 10; i++) {
        await page.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [point(i)] });
        await sleep(20);
    }
    await page.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await sleep(1000);
}

const visible = (selector) =>
    `(function(){ var e = document.querySelector(${JSON.stringify(selector)}); return !!e && e.getClientRects().length > 0; })()`;

const MORE = `document.getElementById('tabbarMoreMenu')`;
const MORE_TRIGGER = `document.querySelector('#tabbarMoreMenu [slot="trigger"]')`;

const MORE_STATE = `(function(){
    var t = ${MORE_TRIGGER};
    return [t.dataset.current ?? null, t.textContent.replace(/\\s+/g, ' ').trim()];
})()`;

/** Whether More is open, and the focus: its trigger, a page link's href, a theme choice, or another element's tag. */
const MORE_FOCUS = `(function(){
    var a = document.activeElement;
    return {
        open: ${MORE}.open,
        focus: a === ${MORE_TRIGGER} ? 'trigger' : a?.getAttribute('href') ?? a?.dataset.themeChoice ?? a?.tagName.toLowerCase(),
    };
})()`;

/** An element's box, rounded. */
const box = (expr) => `(function(){ var r = ${expr}.getBoundingClientRect(); return [r.left, r.top, r.right, r.bottom].map(Math.round); })()`;

/** What is wider than the phone (which widens its layout viewport to fit): the document, a shell
    region, or a visible element no scroll container holds, which the shell's clip would hide. */
const OVERFLOW = `(function(){
    var width = ${PHONE.width};
    var main = document.querySelector('.app-main');
    var out = [];
    if (document.documentElement.scrollWidth > width) out.push('the document is ' + document.documentElement.scrollWidth + ' px wide');
    for (var region of document.querySelectorAll('.app-top, .shell-notices, #page-content, .status-footer')) {
        if (region.getClientRects().length && region.scrollWidth > region.clientWidth + 1) {
            out.push((region.id || region.className) + ' is ' + region.scrollWidth + ' px wide in ' + region.clientWidth + ' px');
        }
    }
    for (var el of main.querySelectorAll('*')) {
        var r = el.getBoundingClientRect();
        if (r.right <= width + 1 || r.width <= 1 || r.height <= 1 || !el.getClientRects().length) continue;
        var held = false;
        for (var p = el.parentElement; p && p !== main && !held; p = p.parentElement) held = getComputedStyle(p).overflowX !== 'visible';
        if (!held) out.push(el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).join('.') : '') + ' ends at ' + Math.round(r.right) + ' px');
    }
    return out.slice(0, 6);
})()`;

export default async function mobileTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/#/flows');
        await page.waitForBoot();
        await page.waitForPage('flows');
        assert.ok(await page.isMobile(), 'the phone layout is active at 390 px');

        // The tab bar replaces the sidebar; every tab says its page's name in full, and fits.
        const bar = await page.evaluate(`(function(){
                var items = [...document.querySelectorAll('.tabbar > .tabbar-item, .tabbar > sb-popover > .tabbar-item')];
                return {
                    tabbar: ${visible('.tabbar')},
                    sidebar: ${visible('.sidebar')},
                    labels: items.map(function(i){ return i.querySelector(':scope > span')?.textContent.trim(); }),
                    conversations: (function(){ var a = document.querySelector('.tabbar a[href="#/conversations"]'); return a && [a.title, a.textContent.trim().split(/\\s+/)[0]]; })(),
                    clipped: items.map(function(i){ return i.querySelector(':scope > span'); }).filter(function(s){ return s.scrollWidth > s.clientWidth + 1; }).length,
                    current: document.querySelector('.tabbar a[aria-current="page"]')?.getAttribute('href'),
                    brand: ${visible('.app-brand')},
                };
            })()`);
        assert.deepEqual(bar, {
            tabbar: true,
            sidebar: false,
            labels: ['Overview', 'Flows', 'Conversations', 'Alerts', 'More'],
            conversations: ['Conversations', 'Conversations'],
            clipped: 0,
            current: '#/flows',
            brand: true,
        });
        // Its semibold current state still fits a fifth of the bar.
        await page.gotoPage('conversations');
        const current = await page.evaluate(`(function(){
                var s = document.querySelector('.tabbar a[href="#/conversations"] > span');
                return { current: s.parentElement.getAttribute('aria-current'), fits: s.scrollWidth <= s.clientWidth + 1 };
            })()`);
        assert.deepEqual(current, { current: 'page', fits: true }, 'the current Conversations tab fits');
        await page.gotoPage('flows');

        // More (POPOVER-SPEC 4.2, 6 item 11): the other pages and the theme, no profile select, above
        // the tab bar and inside the phone with its trigger in place; the arrows, Home and End wrap.
        const triggerAt = await page.evaluate(box(MORE_TRIGGER));
        await page.evaluate(`${MORE_TRIGGER}.focus()`);
        await press(page, 'Enter');
        await page.waitFor(`${MORE}.open === true && document.activeElement?.getAttribute('href') === '#/talkers'`, {
            label: 'Enter to open More on its first page',
        });
        await sleep(200);
        const more = await page.evaluate(`(function(){
                var list = document.getElementById('tabbarMore');
                var panel = ${MORE}.shadowRoot.querySelector('.panel').getBoundingClientRect();
                return {
                    pages: [...list.querySelectorAll('a.menu-item')].map(function(a){
                        return [...a.childNodes].filter(function(n){ return n.nodeType === 3; }).map(function(n){ return n.textContent; }).join('').trim();
                    }),
                    themes: [...list.querySelectorAll('[data-theme-choice]')].map(function(b){ return b.dataset.themeChoice; }),
                    profile: !!list.querySelector('select, #profileSelect'),
                    above: panel.bottom <= document.querySelector('.tabbar').getBoundingClientRect().top + 1,
                    inside: panel.left >= 0 && panel.top >= 0 && panel.right <= ${PHONE.width} && panel.bottom <= ${PHONE.height},
                    expanded: ${MORE_TRIGGER}.getAttribute('aria-expanded'),
                    popup: ${MORE_TRIGGER}.getAttribute('aria-haspopup'),
                    slotted: ${MORE}.shadowRoot.querySelector('slot[name="trigger"]').assignedElements().length,
                };
            })()`);
        assert.deepEqual(more, {
            pages: ['Top Talkers', 'Health', 'Settings'],
            themes: ['light', 'dark', 'system', 'default'],
            profile: false,
            above: true,
            inside: true,
            expanded: 'true',
            popup: 'dialog',
            slotted: 1,
        });
        assert.deepEqual(await page.evaluate(box(MORE_TRIGGER)), triggerAt, 'opening More leaves its trigger where it was');
        for (const [key, to] of [
            ['ArrowDown', '#/health'],
            ['End', 'default'],
            ['ArrowDown', '#/talkers'],
            ['ArrowUp', 'default'],
            ['Home', '#/talkers'],
        ]) {
            await press(page, key);
            assert.deepEqual(await page.evaluate(MORE_FOCUS), { open: true, focus: to }, `${key} moves to ${to}`);
        }

        // A sync morph keeps More open with the focus on the same page (6, item 1); Escape closes it
        // onto its trigger.
        await press(page, 'ArrowDown');
        await page.syncNow('flows');
        assert.deepEqual(await page.evaluate(MORE_FOCUS), { open: true, focus: '#/health' }, 'a sync keeps More open and the focus');
        await press(page, 'Escape');
        await page.waitFor(`${MORE}.open === false && document.activeElement === ${MORE_TRIGGER}`, {
            label: 'Escape to close More onto its trigger',
        });
        assert.equal(await page.evaluate(`${MORE_TRIGGER}.getAttribute('aria-expanded')`), 'false');
        await press(page, ' ');
        await page.waitFor(`${MORE}.open === true && document.activeElement?.getAttribute('href') === '#/talkers'`, {
            label: 'Space to open More on its first page',
        });
        await press(page, 'Escape');
        await page.waitFor(`${MORE}.open === false && document.activeElement === ${MORE_TRIGGER}`, {
            label: 'Escape after Space to close More onto its trigger',
        });

        // A theme chosen from More by keyboard applies, is marked in both theme lists and closes More
        // onto its trigger; a tap outside closes it too.
        const pressed = `[...document.querySelectorAll('[data-theme-choice][aria-pressed="true"]')].map(function(b){ return b.closest('[id]').id + ':' + b.dataset.themeChoice; })`;
        const chooseFromMore = async (choice) => {
            await page.evaluate(`${MORE_TRIGGER}.focus()`);
            await press(page, 'Enter');
            await page.waitFor(`${MORE}.open === true && document.activeElement?.getAttribute('href') === '#/talkers'`, {
                label: 'Enter to open More',
            });
            await page.evaluate(`document.querySelector('#tabbarMore [data-theme-choice="${choice}"]').focus()`);
            await press(page, 'Enter');
            await page.waitFor(`${MORE}.open === false`, { label: `Enter on ${choice} to close More` });
            assert.equal(await page.evaluate(MORE_FOCUS + '.focus'), 'trigger', `choosing ${choice} put the focus back on More's trigger`);
        };
        await chooseFromMore('dark');
        await page.waitFor(`document.documentElement.dataset.theme === 'dark'`, { label: 'Dark from More' });
        assert.deepEqual(await page.evaluate(pressed), ['themeMenuList:dark', 'tabbarMore:dark'], 'both theme lists mark Dark');
        await chooseFromMore('default');
        await page.waitFor(`localStorage.getItem('nfsen-theme') === null`, { label: 'the instance default from More' });
        assert.deepEqual(await page.evaluate(pressed), ['themeMenuList:default', 'tabbarMore:default']);
        await page.evaluate(`${MORE_TRIGGER}.click()`);
        await page.waitFor(`${MORE}.open === true`, { label: 'a tap to open More again' });
        await tap(page, '[data-page-heading="flows"] h1');
        await page.waitFor(`${MORE}.open === false`, { label: 'a tap outside to close More' });

        // Graph first: the page header, then the traffic graph, then the page content.
        const order = await page.evaluate(`(function(){
                var header = document.querySelector('[data-page-heading="flows"]').getBoundingClientRect();
                var graph = document.getElementById('trafficGraphSection').getBoundingClientRect();
                var content = document.getElementById('page-flows').getBoundingClientRect();
                return [header.top < graph.top, graph.bottom <= content.top + 1, graph.height > 0];
            })()`);
        assert.deepEqual(order, [true, true, true], 'the page header and the traffic graph sit above the page content');

        // "Show filters" folds this page's fields only; the Run row stays visible.
        const fields = (id) => visible(`#page-${id} .query-card .query-fields`);
        assert.equal(await page.evaluate(fields('flows')), false, 'the Flows fields start folded on a phone');
        assert.equal(await page.evaluate(visible('button[data-run="flows"]')), true, 'the Run row stays visible');
        await page.evaluate(`document.querySelector('#page-flows .options-toggle').click()`);
        await page.waitFor(fields('flows'), { label: 'the Flows fields to unfold' });
        assert.equal(await page.evaluate(`document.querySelector('#page-flows .options-toggle').getAttribute('aria-expanded')`), 'true');
        assert.equal(await page.evaluate(visible('button[data-run="flows"]')), true, 'the Run row is still visible');
        assert.deepEqual(
            [await page.signalValue('_optionsOpen_flows'), await page.signalValue('_optionsOpen_talkers')],
            [true, false],
            'only the Flows toggle changed'
        );
        // Top Talkers from More by keyboard: Enter on the link opens the page and closes More, and
        // the page heading takes the focus.
        await page.evaluate(`${MORE_TRIGGER}.focus()`);
        await press(page, 'ArrowDown');
        await page.waitFor(`${MORE}.open === true && document.activeElement?.getAttribute('href') === '#/talkers'`, {
            label: 'ArrowDown to open More on Top Talkers',
        });
        await press(page, 'Enter');
        await page.waitForPage('talkers');
        await page.waitFor(`document.activeElement?.id === 'pageTitle' && !!document.activeElement.closest('[data-page-heading="talkers"]')`, {
            label: 'the focus on the Top Talkers heading',
        });
        assert.equal(await page.evaluate(`${MORE}.open`), false, 'choosing a page closed More');
        assert.equal(await page.evaluate(fields('talkers')), false, 'the Top Talkers fields stay folded');
        // A page from More marks the More tab, in words too, and its link in More.
        assert.deepEqual(await page.evaluate(MORE_STATE), ['page', 'More, current: Top Talkers']);
        assert.deepEqual(
            await page.evaluate(`[...document.querySelectorAll('#tabbarMore a[aria-current="page"]')].map(function(a){ return a.getAttribute('href'); })`),
            ['#/talkers']
        );
        // Forced colors: More keeps its panel edge, the focused link its ring and the current page its
        // check mark (6, item 10).
        await page.withForcedColors(async () => {
            await page.evaluate(`${MORE_TRIGGER}.focus()`);
            await press(page, 'ArrowDown');
            await page.waitFor(`${MORE}.open === true && document.activeElement?.getAttribute('href') === '#/talkers'`, {
                label: 'More open on Top Talkers in forced colors',
            });
            await press(page, 'ArrowDown');
            await sleep(300);
            const forced = await page.evaluate(`(function(){
                var panel = getComputedStyle(${MORE}.shadowRoot.querySelector('.panel'));
                var ring = getComputedStyle(document.activeElement);
                var mark = getComputedStyle(document.querySelector('#tabbarMore a[aria-current="page"]'), '::before');
                return {
                    edge: panel.outlineStyle + ' ' + panel.outlineWidth,
                    focus: document.activeElement.getAttribute('href'),
                    ring: ring.outlineStyle !== 'none' && parseFloat(ring.outlineWidth) >= 2,
                    mark: { content: mark.content, mask: mark.maskImage !== 'none', adjust: mark.forcedColorAdjust, drawn: parseFloat(mark.width) > 0 },
                };
            })()`);
            assert.deepEqual(
                forced,
                { edge: 'solid 1px', focus: '#/health', ring: true, mark: { content: '""', mask: true, adjust: 'none', drawn: true } },
                'More keeps its edge, the focused link its ring and the current page its check mark in forced colors'
            );
            await page.screenshot('/tmp/nfsen-mobile-forced-more.png');
            await press(page, 'Escape');
            await page.waitFor(`${MORE}.open === false`, { label: 'Escape to close More in forced colors' });
        });
        await page.gotoPage('flows');
        assert.deepEqual(await page.evaluate(MORE_STATE), [null, 'More']);
        await page.waitFor(fields('flows'), { label: 'the Flows fields to stay open across a page switch' });
        await page.evaluate(`document.querySelector('#page-flows .options-toggle').click()`);
        await page.waitFor(`!${fields('flows')}`, { label: 'the Flows fields to fold again' });

        // A vertical swipe scrolls the page. On the graph it keeps the range, and with the 1.8
        // touch contract (#brushToggle) it scrolls the page there too.
        const at = (selector) =>
            page.evaluate(`(function(){
                    var r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();
                    return { x: Math.round(r.left + r.width / 2), y: Math.round(Math.max(r.top + 20, Math.min(r.top + r.height / 2, innerHeight - 250))) };
                })()`);
        await page.evaluate(`window.scrollTo(0, 0)`);
        await sleep(300);
        await swipe(page, await at('#page-flows .query-card'), 240);
        const onContent = await page.evaluate('window.scrollY');
        assert.ok(onContent > 50, `a vertical swipe on the page scrolls it (scrollY ${onContent})`);

        await page.evaluate(`window.scrollTo(0, 0)`);
        await sleep(300);
        const range = async () => Object.values(await page.signalValues(['datestart', 'dateend']));
        const [start, end] = await range();
        await swipe(page, await at('#trafficGraph'), 240);
        const [start2, end2] = await range();
        // A live window may advance meanwhile, so the width must hold and the start may only move on.
        assert.equal(end2 - start2, end - start, 'a swipe on the graph keeps the window width');
        assert.ok(start2 - start >= 0 && start2 - start <= 120, `a swipe on the graph does not move the range (${start2 - start} s)`);
        // The 1.8 touch contract: the brush waits for #brushToggle, a swipe scrolls, the graph is 13rem.
        assert.equal(await page.evaluate(visible('#brushToggle')), true, '#brushToggle is visible on a phone');
        const onGraph = await page.evaluate('window.scrollY');
        assert.ok(onGraph > 50, `a vertical swipe on the graph scrolls the page (scrollY ${onGraph})`);
        const graphHeight = await page.evaluate(
            `document.querySelector('#trafficGraph .chart-canvas').getBoundingClientRect().height / parseFloat(getComputedStyle(document.documentElement).fontSize)`
        );
        assert.ok(Math.abs(graphHeight - 13) < 0.5, `the phone graph is about 13rem tall (${graphHeight.toFixed(2)}rem)`);

        // "Select range" arms the brush for one selection on a coarse pointer, through the graph's host
        // API and its brush-armed event; pressed again, it disarms (1.8). By keyboard (V-A11Y).
        await page.setRangePreset('1y');
        assert.equal(await page.evaluate(`matchMedia('(pointer: coarse)').matches && ${GRAPH}.rocketInstanceId !== undefined`), true);
        const year = `JSON.parse(${GRAPH}.dataset.chartConfig || '{}').window`;
        await page.waitFor(`Math.abs(Number((${year} || '').replace('live:', '')) - 31536000) <= 300`, {
            timeout: 15000,
            label: 'the year to reach the phone graph',
        });
        const rows = await page.evaluate(`(function(){
            var d = JSON.parse(${GRAPH}.dataset.chartData || 'null');
            return d && Array.isArray(d.legend) && d.legend.length > 0 && d.data ? Object.keys(d.data).length : 0;
        })()`);
        if (rows > 0) {
            await page.waitFor(`!!${GRAPH}.chart`, { timeout: 15000, label: 'the phone graph to draw its rows' });
            const pressed = `document.getElementById('brushToggle').getAttribute('aria-pressed')`;
            await page.evaluate(`document.getElementById('brushToggle').focus()`);
            await press(page, 'Enter');
            await page.waitFor(`${pressed} === 'true'`, { label: 'Select range to arm the brush' });
            assert.equal(await page.signalValue('_brushArmed'), true);
            await press(page, 'Enter');
            await page.waitFor(`${pressed} === 'false'`, { label: 'a second press to disarm it' });
            const log = await page.requestLog();
            await dragAcrossGraph(page);
            assert.equal(log.count('set-range'), 0, `an unarmed drag selects nothing, got ${log.names().join(', ')}`);

            // Armed, the brush survives a move (6.9, K14): an atomic one, and a remove and insert.
            await page.evaluate(`document.getElementById('brushToggle').focus()`);
            await press(page, 'Enter');
            await page.waitFor(`${pressed} === 'true'`, { label: 'Select range to arm the brush again' });
            await page.evaluate(`void (window.e2eChart = ${GRAPH}.chart)`);
            for (const move of ['moveBefore', 'insertBefore']) {
                await page.evaluate(`(function(){ var el = ${GRAPH}; el.parentNode.${move}(el, el.nextSibling); })()`);
                await sleep(300);
                const kept = await page.evaluate(`({ pressed: ${pressed}, same: ${GRAPH}.chart === window.e2eChart })`);
                kept.armed = await page.signalValue('_brushArmed');
                assert.deepEqual(kept, { pressed: 'true', same: true, armed: true }, `${move} keeps the brush armed`);
            }
            log.clear();
            await dragAcrossGraph(page);
            assert.equal(log.count('set-range'), 1, `the armed drag after the moves posts set-range once, got ${log.names().join(', ')}`);
            await page.waitFor(`${pressed} === 'false'`, { label: 'one selection to disarm the brush' });
            for (let i = 0; i < 40 && (await page.signalValue('range_live')); i++) await sleep(150);
            assert.equal(await page.signalValue('range_live'), false, 'the selected range is fixed');
            await page.setRangePreset('1y');
        } else {
            console.log('  (mobile: no graph data in a year here, the Select range checks did not run)');
        }

        // Nothing is wider than the phone, on any page, with results on the pages that hold them.
        await page.runQuery('flows', { timeout: 30000 });
        await page.gotoPage('talkers');
        await page.runQuery('talkers', { timeout: 30000 });
        const wide = [];
        for (const id of PAGES) {
            await page.gotoPage(id);
            await sleep(400);
            for (const what of await page.evaluate(OVERFLOW)) wide.push(`${id}: ${what}`);
        }
        assert.deepEqual(wide, [], 'nothing is wider than 390 px');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors on a phone, got:\n${errors.join('\n')}`);
    }, PHONE);

    // Before any sync, with the stream blocked: More opens and its tab and links follow a page chosen
    // from it (6, item 4); compact tables move neither its trigger nor its panel (6, item 12).
    await withPage(async (page) => {
        await page.navigate(BASE + '/#/flows');
        await page.waitForBoot();
        await page.send('Network.enable');
        await page.send('Network.setBlockedURLs', { urls: ['*/_sse*'] });
        await page.reload();
        await page.waitForBoot();
        await page.waitFor(`${MORE_TRIGGER}?.getAttribute('aria-haspopup') === 'dialog'`, { label: 'sb-popover to render More' });
        await page.evaluate(`document.getElementById('page-content').setAttribute('data-e2e-unsynced', '')`);
        assert.deepEqual(await page.evaluate(MORE_STATE), [null, 'More']);
        await tap(page, '#tabbarMoreMenu [slot="trigger"]');
        await page.waitFor(`${MORE}.open === true`, { label: 'a tap to open More before any sync' });
        await tap(page, '#tabbarMore a[href="#/health"]');
        await page.waitFor(`${MORE}.open === false && location.hash === '#/health'`, { label: 'a tap on Health to close More' });
        await page.waitFor(`${MORE_TRIGGER}.dataset.current === 'page'`, { label: 'the More tab to follow Health before any sync' });
        assert.deepEqual(
            await page.evaluate(`({
                state: ${MORE_STATE},
                current: [...document.querySelectorAll('#tabbarMore a[aria-current="page"]')].map(function(a){ return a.getAttribute('href'); }),
                unsynced: document.getElementById('page-content').hasAttribute('data-e2e-unsynced'),
            })`),
            { state: ['page', 'More, current: Health'], current: ['#/health'], unsynced: true },
            'More marks Health, in words too, before any sync'
        );

        const boxes = `({ trigger: ${box(MORE_TRIGGER)}, panel: ${box(`${MORE}.shadowRoot.querySelector('.panel')`)} })`;
        const measure = async () => {
            await page.evaluate(`${MORE}.show()`);
            await page.waitFor(`${MORE}.open === true`, { label: 'More to open' });
            await sleep(200);
            const at = await page.evaluate(boxes);
            await page.evaluate(`${MORE}.hide()`);
            await page.waitFor(`${MORE}.open === false`, { label: 'More to close' });
            return at;
        };
        await page.evaluate(`document.documentElement.removeAttribute('data-density')`);
        const comfortable = await measure();
        await page.evaluate(`document.documentElement.setAttribute('data-density', 'compact')`);
        assert.deepEqual(await measure(), comfortable, 'compact tables change nothing about More');
    }, PHONE);

    // Desktop: the fields show whatever the phone toggle says, and the phone chrome is gone.
    // Tablet: the sidebar starts collapsed until someone toggles it, and a visit stores nothing.
    await withPage(
        async (page) => {
            await page.navigate(BASE + '/#/flows');
            await page.waitForBoot();
            await page.waitForPage('flows');
            assert.equal(await page.signalValue('_optionsOpen_flows'), false);
            const desktop = await page.evaluate(`({
                fields: ${visible('#page-flows .query-card .query-fields')},
                toggle: ${visible('#page-flows .options-toggle')},
                collapsed: document.querySelector('#page-flows .query-card').hasAttribute('data-collapsed'),
                tabbar: ${visible('.tabbar')},
                sidebar: ${visible('.sidebar')},
                brand: ${visible('.app-brand')},
            })`);
            assert.deepEqual(desktop, { fields: true, toggle: false, collapsed: true, tabbar: false, sidebar: true, brand: false });
            const errors = page.realErrors();
            assert.deepEqual(errors, [], `expected no console errors at 1280 px, got:\n${errors.join('\n')}`);

            const seen = page.errors.length;
            const KEY = `'nfsen-persist:_sidebarCollapsed'`;
            const sidebar = () => page.evaluate(`[document.body.dataset.sidebar, localStorage.getItem(${KEY})]`);
            assert.deepEqual(await sidebar(), ['expanded', null], 'expanded at 1280 px, and nothing stored');
            await page.send('Emulation.setDeviceMetricsOverride', { width: 900, height: 900, deviceScaleFactor: 1, mobile: false });
            await page.reload();
            await page.waitForBoot();
            assert.deepEqual(await sidebar(), ['collapsed', null], 'collapsed at 900 px when nothing is stored');
            await page.evaluate(`document.querySelector('.sidebar-toggle').click()`);
            await page.waitFor(`document.body.dataset.sidebar === 'expanded'`, { label: 'the sidebar to expand' });
            assert.deepEqual(await sidebar(), ['expanded', 'false'], 'the toggle stores the choice');
            await page.reload();
            await page.waitForBoot();
            assert.deepEqual(await sidebar(), ['expanded', 'false'], 'a stored choice beats the tablet default');
            await page.evaluate(`localStorage.removeItem(${KEY})`);
            const tablet = page.errors.slice(seen).filter((e) => !isBenignError(e));
            assert.deepEqual(tablet, [], `expected no console errors at 900 px, got:\n${tablet.join('\n')}`);
        },
        { width: 1280, height: 900 }
    );
}

if (import.meta.url === `file://${process.argv[1]}`) {
    mobileTest()
        .then(() => console.log('mobile: PASS'))
        .catch((e) => {
            console.error('mobile: FAIL\n', e);
            process.exit(1);
        });
}
