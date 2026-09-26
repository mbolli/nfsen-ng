// The phone and tablet shell (spec 2.6, 4.9, 2.7.11, 5.5): tab bar and More, graph first, the
// phone-only "Show filters", swipes that scroll, nothing wider than 390 px, the tablet default.
import assert from 'node:assert/strict';
import { withPage, BASE, isBenignError } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const PAGES = ['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health', 'settings'];
const PHONE = { width: 390, height: 844, mobile: true };

async function press(page, key) {
    const codes = { Escape: 27, Enter: 13, Tab: 9 };
    const base = { key, code: key, windowsVirtualKeyCode: codes[key], nativeVirtualKeyCode: codes[key] };
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(key === 'Enter' ? { text: '\r' } : {}) });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
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

const visible = (selector) =>
    `(function(){ var e = document.querySelector(${JSON.stringify(selector)}); return !!e && e.getClientRects().length > 0; })()`;

const MORE_STATE = `(function(){
    var t = document.querySelector('.tabbar-more .menu-toggle');
    return [t.dataset.current ?? null, t.textContent.replace(/\\s+/g, ' ').trim()];
})()`;

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
                var items = [...document.querySelectorAll('.tabbar > .tabbar-item, .tabbar > .menu > .tabbar-item')];
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

        // More: the remaining pages and the theme, no profile select; Escape closes it and
        // gives the focus back to its toggle.
        await page.evaluate(`document.querySelector('.tabbar-more .menu-toggle').focus()`);
        await press(page, 'Enter');
        await page.waitFor(`document.getElementById('tabbarMore').matches(':popover-open')`, { label: 'the More menu to open' });
        const more = await page.evaluate(`(function(){
                var list = document.getElementById('tabbarMore');
                var box = list.getBoundingClientRect();
                return {
                    pages: [...list.querySelectorAll('a.menu-item')].map(function(a){
                        return [...a.childNodes].filter(function(n){ return n.nodeType === 3; }).map(function(n){ return n.textContent; }).join('').trim();
                    }),
                    themes: [...list.querySelectorAll('[data-theme-choice]')].map(function(b){ return b.dataset.themeChoice; }),
                    profile: !!list.querySelector('select, #profileSelect'),
                    above: box.bottom <= document.querySelector('.tabbar').getBoundingClientRect().top + 1,
                    expanded: document.querySelector('.tabbar-more .menu-toggle').getAttribute('aria-expanded'),
                };
            })()`);
        assert.deepEqual(more, {
            pages: ['Top Talkers', 'Health', 'Settings'],
            themes: ['light', 'dark', 'system', 'default'],
            profile: false,
            above: true,
            expanded: 'true',
        });
        await press(page, 'Escape');
        await page.waitFor(`!document.getElementById('tabbarMore').matches(':popover-open')`, { label: 'Escape to close More' });
        assert.ok(
            await page.evaluate(`document.activeElement === document.querySelector('.tabbar-more .menu-toggle')`),
            'the focus is back on the More toggle'
        );

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
        await page.gotoPage('talkers');
        assert.equal(await page.evaluate(fields('talkers')), false, 'the Top Talkers fields stay folded');
        // A page from More marks the More tab, in words too.
        assert.deepEqual(await page.evaluate(MORE_STATE), ['page', 'More, current: Top Talkers']);
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

        // Nothing is wider than the phone, on any page, with results on the pages that hold them.
        await page.setRangePreset('1y');
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
