// The Flows list on sb-virtual-scroll: 10,000 rows against the server's own exports, sorts, syncs, new results, tabs,
// themes, densities, the keyboard, the Columns picker, exports and a context revived by a window request. It saves
// compact tables and puts them back, so it is mutating. Screenshots go to OUT.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { withPage } from './lib/cdp.mjs';
import * as L from './lib/vscroll.mjs';
import { SCAN } from './rocket.test.mjs';

export const MUTATING = true;

const OUT = process.env.OUT || '/tmp/vscroll';
const SEED = 1;
const HOST = L.HOST;
const SORTS = [
    ['in_bytes', 'asc'],
    ['in_bytes', 'desc'],
    ['src_port', 'asc'],
    ['src_addr', 'asc'],
];
const KEYS = { Tab: 9, PageDown: 34 };
const SHIFT = 8;
// The instance runs in UTC; the browser in another zone shows that the list writes times in the browser's zone.
const ZONE = process.env.VSCROLL_TZ || 'America/New_York';
// Past php-via's reconnect timeout (60 s after the tab's last action), so the window request finds the context gone.
const REVIVE_WAIT_MS = Number(process.env.VSCROLL_REVIVE_WAIT_MS || 70000);
// The container whose log V14 reads.
const CONTAINER = process.env.VSCROLL_CONTAINER || 'nfsen-adopt';

async function press(page, key, modifiers = 0) {
    const base = { key, code: key, windowsVirtualKeyCode: KEYS[key], nativeVirtualKeyCode: KEYS[key], modifiers };
    await page.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...base });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

/** A real press and release at the element's centre. */
async function clickAt(page, expr) {
    const { x, y } = await page.evaluate(`(function(){
        var el = ${expr};
        el.scrollIntoView({ block: 'center', inline: 'nearest' });
        var r = el.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    })()`);
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
}

/** Resolves once the next server render has morphed #flowResults: the morph drops an attribute the server does not send. */
async function nextSync(page, trigger, label) {
    await page.evaluate(`document.getElementById('flowResults').setAttribute('data-e2e-probe', '')`);
    await trigger();
    await page.waitFor(`!document.getElementById('flowResults').hasAttribute('data-e2e-probe')`, { timeout: 10000, label });
}

async function scrollTo(page, index, block = 'start') {
    await page.evaluate(`${HOST}.scrollToIndex(${index}, { block: ${JSON.stringify(block)} })`);
    await L.waitCovered(page, { label: `the list to cover the view at row ${index}` });
}

/** Cell texts of the list's rows from..to - 1, scrolling as far as needed. */
async function readList(page, from, to) {
    const out = [];
    for (let i = from; i < to; ) {
        await scrollTo(page, i);
        const state = await page.evaluate(L.STATE);
        const end = Math.min(to, state.offset + state.rows);
        assert.ok(end > i, `the window at row ${i} holds it (offset ${state.offset}, ${state.rows} rows)`);
        out.push(...(await page.evaluate(L.listRows(Array.from({ length: end - i }, (_, k) => i + k)))));
        i = end;
    }
    return out;
}

/** The JSON export's rows as arrays in the header's column order. */
async function exported(page, enhanced) {
    const titles = await page.evaluate(L.TITLES);
    const file = await L.exportList(page, 'json', { enhanced });
    return JSON.parse(file.text).map((row) => titles.map((title) => row[title]));
}

/** The row index of the element with the focus, when it is in a list row; else null. */
const FOCUSED_ROW = `(function(){ var a = document.activeElement; var r = ${HOST}.contains(a) && a.closest('[role="row"]:not([slot])'); return r ? Number(r.getAttribute('aria-rowindex')) - 2 : null; })()`;

/**
 * V13: from row `from`'s first IP link, Tab (or Shift+Tab with `back`) until `rows` rows were passed; the first press
 * that skips a row or leaves the list, or null.
 */
async function tabWalk(page, from, rows, back = false) {
    await scrollTo(page, from, back ? 'end' : 'start');
    await L.sleep(300);
    await page.evaluate(`(function(){
        var row = [...${HOST}.children].find(function(r){ return r.getAttribute('aria-rowindex') === '${from + 2}'; });
        var links = row.querySelectorAll('a.ip-link');
        links[${back ? 'links.length - 1' : '0'}].focus();
    })()`);
    let row = await page.evaluate(FOCUSED_ROW);
    const goal = back ? from - rows : from + rows;
    for (let n = 1; row !== null && (back ? row > goal : row < goal) && n <= rows * 6; n++) {
        await press(page, 'Tab', back ? SHIFT : 0);
        // A window that lands puts the focus back a frame later (a key in between starts from the focused row).
        await page.evaluate('new Promise(function(r){ requestAnimationFrame(function(){ requestAnimationFrame(r); }); })');
        const next = await page.evaluate(FOCUSED_ROW);
        const ok = next !== null && (back ? next <= row && next >= row - 1 : next >= row && next <= row + 1);
        if (!ok) return `press ${n}: row ${row} to ${next === null ? 'outside the list' : `row ${next}`}`;
        row = next;
    }
    return row !== null && (back ? row <= goal : row >= goal) ? null : `stopped at row ${row}`;
}

/** Clicks a sort button of the list and waits for its answer: the header says so, the list starts at the top. */
async function sortList(page, key, direction) {
    const aria = direction === 'desc' ? 'descending' : 'ascending';
    const button = `${HOST}.querySelector('button[data-sort-key=${JSON.stringify(key)}]')`;
    for (let i = 0; i < 2; i++) {
        if (await page.evaluate(`!!${HOST}.querySelector('[aria-sort="${aria}"] > button[data-sort-key=${JSON.stringify(key)}]')`)) break;
        await page.evaluate(`${button}.click()`);
        await page.waitFor(
            `${HOST}.querySelector('[aria-sort] > button[data-sort-key=${JSON.stringify(key)}]') && ${HOST}.getAttribute('offset') === '0' && !${HOST}.matches(':state(loading)')`,
            { timeout: 15000, label: `the list sorted by ${key}` }
        );
    }
    assert.ok(
        await page.evaluate(`!!${HOST}.querySelector('[aria-sort="${aria}"] > button[data-sort-key=${JSON.stringify(key)}]')`),
        `the list is sorted by ${key} ${aria}`
    );
}

/** `rows` sorted by column `col` in `dir` as the server sorts: stable, empty keys last in both directions. */
function stableSort(rows, col, dir) {
    const empty = rows.filter((r) => (r[col] ?? '') === '');
    const filled = rows.filter((r) => (r[col] ?? '') !== '');
    const sign = dir === 'desc' ? -1 : 1;
    const sorted = filled
        .map((r, i) => [r, i])
        .sort((a, b) => sign * L.compareRaw(a[0][col], b[0][col]) || a[1] - b[1])
        .map(([r]) => r);
    return [...sorted, ...empty];
}

async function shot(page, name) {
    const box = await page.evaluate(`(function(){
        var r = document.getElementById('flowsPanel-flows').getBoundingClientRect();
        return { x: r.left + scrollX, y: r.top + scrollY, width: r.width, height: Math.min(r.height, 1200) };
    })()`);
    const { data } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { ...box, scale: 1 } });
    writeFileSync(join(OUT, `vscroll-${name}.png`), Buffer.from(data, 'base64'));
}

async function setTheme(page, mode) {
    await page.evaluate(`window.__nfsenTheme.choose(${JSON.stringify(mode)})`);
    await page.waitFor(`document.documentElement.dataset.theme === ${JSON.stringify(mode)}`, { label: `theme ${mode}` });
    await L.sleep(150);
}

/** V10: the header over scrolled rows, stripes, the sorted column's bar, the focus ring on a sort button and on an IP link. */
async function look(page, name, forced) {
    await page.evaluate(`document.getElementById('flowsPanel-flows').scrollIntoView({ block: 'start' })`);
    await scrollTo(page, 40);
    await page.evaluate(`${HOST}.querySelector('button[data-sort-key]').blur()`);
    const seen = await page.evaluate(`(function(){
        var h = ${HOST};
        var header = h.querySelector('[slot="header"]');
        var r = header.getBoundingClientRect();
        var hit = h.getRootNode().elementFromPoint(r.left + r.width / 4, r.top + r.height / 2);
        var rows = [...h.children].filter(function(el){ return !el.slot; });
        var striped = rows.find(function(el){ return el.hasAttribute('data-stripe'); });
        var plain = rows.find(function(el){ return !el.hasAttribute('data-stripe'); });
        var sorted = h.querySelector('[role="columnheader"][aria-sort]');
        var cs = sorted ? getComputedStyle(sorted) : null;
        return {
            headerBg: getComputedStyle(header).backgroundColor,
            headerOnTop: !!hit && header.contains(hit),
            stripe: getComputedStyle(striped).backgroundColor,
            plain: getComputedStyle(plain).backgroundColor,
            rowBorder: getComputedStyle(plain).borderBlockEndStyle,
            bar: cs ? { shadow: cs.boxShadow, outline: cs.outlineStyle } : null,
        };
    })()`);
    assert.ok(seen.headerOnTop, `${name}: the header stays on top of the scrolled rows`);
    assert.doesNotMatch(seen.headerBg, /rgba\(0, 0, 0, 0\)|transparent/, `${name}: the header is opaque (${seen.headerBg})`);
    if (forced) {
        assert.equal(seen.rowBorder, 'solid', `${name}: rows keep their borders under forced colours`);
        assert.equal(seen.bar?.outline, 'solid', `${name}: the sorted column shows an outline under forced colours`);
    } else {
        assert.notEqual(seen.stripe, seen.plain, `${name}: stripes differ from plain rows (${seen.stripe})`);
        assert.ok(seen.bar && seen.bar.shadow !== 'none', `${name}: the sorted column shows its bar`);
    }

    // Keyboard focus on the first sort button: an outline, inside the cell that clips its overflow.
    await page.evaluate(`document.getElementById('flowsTab-flows').focus()`);
    for (let i = 0; i < 10; i++) {
        await press(page, 'Tab');
        if (await page.evaluate(`document.activeElement?.matches('button[data-sort-key]') ?? false`)) break;
    }
    const ring = await page.evaluate(`(function(){
        var b = document.activeElement;
        if (!b || !b.matches('button[data-sort-key]')) return null;
        var cs = getComputedStyle(b);
        var reach = parseFloat(cs.outlineWidth) + Math.max(0, parseFloat(cs.outlineOffset));
        var br = b.getBoundingClientRect(), cr = b.parentElement.getBoundingClientRect();
        return { style: cs.outlineStyle, width: parseFloat(cs.outlineWidth), inside: br.top - reach >= cr.top - 0.5 && br.bottom + reach <= cr.bottom + 0.5 && br.left - reach >= cr.left - 0.5 };
    })()`);
    assert.ok(ring, `${name}: Tab reaches a sort button`);
    assert.notEqual(ring.style, 'none', `${name}: the focused sort button has an outline`);
    assert.ok(ring.width >= 2 && ring.inside, `${name}: the focus ring shows whole (${JSON.stringify(ring)})`);
    await shot(page, name);

    // An IP link in a row in view, focused after that keyboard focus: its ring and halo inside the cell, which clips.
    const link = await page.evaluate(`(function(){
        var st = ${L.STATE};
        var r = [...${HOST}.children].find(function(el){ return el.getAttribute('aria-rowindex') === String(Math.floor(st.scrollTop / st.itemSize) + 3); });
        var a = r && r.querySelector('a.ip-link');
        if (!a) return null;
        a.focus();
        var cs = getComputedStyle(a);
        var halo = cs.boxShadow === 'none' ? 0 : Math.max.apply(null, (cs.boxShadow.match(/-?[\\d.]+px/g) || ['0px']).map(parseFloat));
        var reach = Math.max(parseFloat(cs.outlineWidth) + Math.max(0, parseFloat(cs.outlineOffset)), halo);
        var ar = a.getBoundingClientRect(), cr = a.closest('[role="cell"]').getBoundingClientRect();
        return {
            visible: a.matches(':focus-visible'),
            style: cs.outlineStyle,
            reach: reach,
            inside: ar.top - reach >= cr.top - 0.5 && ar.bottom + reach <= cr.bottom + 0.5 && ar.left - reach >= cr.left - 0.5 && ar.right + reach <= cr.right + 0.5,
        };
    })()`);
    assert.ok(link, `${name}: a row in view has an IP link`);
    assert.ok(link.visible && link.style !== 'none', `${name}: the focused IP link shows its focus ring (${JSON.stringify(link)})`);
    assert.ok(link.reach >= 4 && link.inside, `${name}: the IP link's focus ring shows whole inside its cell (${JSON.stringify(link)})`);
    await page.evaluate(`document.activeElement?.blur(); ${HOST}.shadowRoot.querySelector('.scroller').scrollLeft = 0`);
}

/** V10 geometry: rows as high as item-size, the header's columns over the rows', an IPv4 address whole. */
async function geometry(page, density) {
    await scrollTo(page, 0);
    const g = await page.evaluate(`(function(){
        var h = ${HOST};
        var size = Number(h.getAttribute('item-size'));
        var rows = [...h.children].filter(function(el){ return !el.slot; });
        var head = [...h.querySelector('[slot="header"]').children];
        var first = [...rows[0].children];
        var off = head.map(function(c, i){
            var a = c.getBoundingClientRect(), b = first[i].getBoundingClientRect();
            return Math.max(Math.abs(a.left - b.left), Math.abs(a.width - b.width));
        });
        var v4 = rows.map(function(r){ return r.querySelector('[data-kind="address"]'); }).find(function(c){ return c && /^\\d+\\.\\d+\\.\\d+\\.\\d+$/.test(c.textContent.trim()); });
        return {
            density: document.documentElement.dataset.density,
            size: size,
            heights: [...new Set(rows.map(function(r){ return r.offsetHeight; }))],
            columns: head.length === first.length,
            off: Math.max.apply(null, off),
            clipped: v4 ? v4.scrollWidth > v4.clientWidth : null,
        };
    })()`);
    assert.equal(g.density, density, `the page is in ${density} density`);
    assert.deepEqual(g.heights, [g.size], `${density}: every row is item-size (${g.size} px) high`);
    assert.ok(g.columns, `${density}: the header has a cell per row cell`);
    assert.ok(g.off <= 1, `${density}: header cells line up with the first row's within 1 px (${g.off})`);
    assert.equal(g.clipped, false, `${density}: an IPv4 address is not clipped`);
    return g;
}

/** JS event listeners on the page after garbage collection. */
async function listeners(page) {
    for (let i = 0; i < 3; i++) await page.send('HeapProfiler.collectGarbage');
    return (await page.send('Memory.getDOMCounters')).jsEventListeners;
}

/** A small CSV reader: quoted fields with doubled quotes and line breaks. */
function parseCsv(text) {
    const rows = [];
    let row = [];
    let field = '';
    let quoted = false;
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (quoted) {
            if (ch === '"' && text[i + 1] === '"') {
                field += '"';
                i++;
            } else if (ch === '"') quoted = false;
            else field += ch;
        } else if (ch === '"') quoted = true;
        else if (ch === ',') {
            row.push(field);
            field = '';
        } else if (ch === '\n') {
            row.push(field);
            rows.push(row);
            row = [];
            field = '';
        } else field += ch;
    }
    return rows;
}

/**
 * V14: the tab's stream ends while new streams stay blocked; a navigate POST makes the server write to the dead stream
 * and drop the context; a scroll's window POST, which carries only via_ctx, revives it; the next stream seeds it.
 */
export async function revival(page) {
    const before = await L.signals(page, ['flows_limit', 'datestart', 'dateend', 'page', 'via_ctx']);
    assert.equal(before.flows_limit, L.LIMIT, 'V14: the result has the limit 10,000');
    const since = Math.floor(Date.now() / 1000) - 1;
    await page.send('Network.enable');
    await page.send('Network.setBlockedURLs', { urls: ['*/_sse*'] });
    // Datastar ends its stream while the page is hidden and opens a new one, blocked here, once it is visible again.
    const visibility = (hidden) => `(function(){
        Object.defineProperty(document, 'hidden', { value: ${hidden}, configurable: true });
        Object.defineProperty(document, 'visibilityState', { value: ${hidden} ? 'hidden' : 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
    })()`;
    await page.evaluate(visibility(true));
    await L.sleep(1500);
    await page.evaluate(visibility(false));
    // The server notices a closed stream only when it writes to it.
    const status = await page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        var body = {};
        Object.keys(root).forEach(function(k){ if (!/^_/.test(k)) body[k] = root[k]; });
        var r = await fetch('/_action/navigate', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Datastar-Request': 'true' }, body: JSON.stringify(body) });
        return r.status;
    })()`);
    assert.ok(status < 300, `V14: the navigate POST was taken (${status})`);
    // php-via keeps a context an action reached without a stream for its reconnect timeout (60 s).
    await L.sleep(REVIVE_WAIT_MS);
    // A scroll asks for a window with a body of only via_ctx; its answer waits for the stream.
    await page.evaluate(`${HOST}.scrollToIndex(6000, { block: 'start' })`);
    await L.sleep(2000);
    await page.send('Network.setBlockedURLs', { urls: [] });
    await L.waitCovered(page, { timeout: 60000, label: 'V14: the window after the stream came back' });
    const after = await L.signals(page, ['flows_limit', 'datestart', 'dateend', 'page', 'via_ctx']);
    assert.deepEqual(
        [after.flows_limit, after.datestart, after.dateend, after.page],
        [before.flows_limit, before.datestart, before.dateend, 'flows'],
        'V14: the revived tab keeps its limit, its range and its page'
    );
    const time = await page.evaluate(`(function(){
        var t = [...${HOST}.children].find(function(r){ return !r.slot; }).querySelector('time[data-epoch]');
        var f = function(tz){ return new Intl.DateTimeFormat('sv-SE', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(Number(t.dataset.epoch) * 1000); };
        return { text: t.textContent.trim(), zone: f(${JSON.stringify(ZONE)}) };
    })()`);
    assert.equal(time.text, time.zone, `V14: the revived window writes its times in ${ZONE}`);
    let log = '';
    try {
        log = execFileSync('docker', ['logs', '--since', String(since), CONTAINER], {
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (e) {
        log = `${e.stdout ?? ''}${e.stderr ?? ''}`;
    }
    const revived = `Revived context ${before.via_ctx} without client signals`;
    assert.ok(log.includes(revived), `V14: the server revived the tab's context from the window: "${revived}" in the log of ${CONTAINER}`);
}

export default async function vscrollTest() {
    mkdirSync(OUT, { recursive: true });
    await withPage(async (page) => {
        await page.send('Emulation.setTimezoneOverride', { timezoneId: ZONE });
        await L.saveSettings(page, { compact: false, displayTz: 'browser' });
        assert.equal(await page.evaluate(`Intl.DateTimeFormat().resolvedOptions().timeZone`), ZONE, `the browser runs in ${ZONE}`);
        await L.openFlows(page);
        await L.prepare(page);
        await L.run(page);
        const listenersAfterRun = await listeners(page);
        const random = L.seeded(SEED);
        const picks = Array.from({ length: 10 }, () => Math.floor(random() * L.LIMIT));

        // ── V1 the host, the first rows against the export, times in the browser's zone ──
        const attrs = await page.evaluate(
            `(function(){ var h = ${HOST}; return ['role', 'aria-label', 'aria-rowcount', 'total', 'offset'].map(function(a){ return h.getAttribute(a); }); })()`
        );
        assert.deepEqual(attrs, ['table', 'Flows', '10001', '10000', '0'], 'V1: the host');
        assert.equal(await page.evaluate(`!!document.getElementById('flowTable')`), false, 'V1: no paged table');
        const shown = await exported(page, true);
        const raw = await exported(page, false);
        assert.equal(shown.length, L.LIMIT, 'V1: the export holds every row');
        assert.deepEqual(await readList(page, 0, 50), shown.slice(0, 50), "V1: the first 50 rows are the export's, time cells included");
        const times = await page.evaluate(`(function(){
            var f = function(epoch, tz){ return new Intl.DateTimeFormat('sv-SE', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(epoch * 1000); };
            return [...${HOST}.querySelectorAll('[role="row"]:not([slot]) time[data-epoch]')].map(function(t){
                return { text: t.textContent.trim(), zone: f(Number(t.dataset.epoch), ${JSON.stringify(ZONE)}), utc: f(Number(t.dataset.epoch), 'UTC') };
            });
        })()`);
        assert.ok(times.length > 0, 'V1: the rows hold time cells');
        assert.ok(
            times.some((t) => t.zone !== t.utc),
            `V1: ${ZONE} differs from UTC at these times`
        );
        assert.deepEqual(
            times.filter((t) => t.text !== t.zone),
            [],
            `V1: every time cell is written in the browser's zone, ${ZONE}`
        );

        // ── V9 rules on the page with the list ─────────────────────────────────
        const scan = await page.evaluate(SCAN);
        assert.ok(scan.hosts.includes('sb-virtual-scroll'), 'V9: the list is a Rocket host');
        assert.deepEqual(scan.problems, [], 'V9: no K1 or K2 problem');
        assert.deepEqual(scan.undefinedTags, [], 'V9: every sb- and nfsen- tag is defined');
        assert.deepEqual(scan.unnamed, [], 'V9: every host with a role has a name');

        // ── V2 the end, V3 seeded rows ──────────────────────────────────────────
        await scrollTo(page, L.LIMIT - 1, 'end');
        await page.waitFor(`${HOST}.querySelector('[aria-rowindex="10001"]')`, { label: 'V2: the last row' });
        assert.deepEqual(await page.evaluate(L.listRows([L.LIMIT - 1])), [shown[L.LIMIT - 1]], "V2: the last row is the export's");
        for (const i of picks) {
            await scrollTo(page, i);
            assert.deepEqual(await page.evaluate(L.listRows([i])), [shown[i]], `V3: row ${i} is the export's`);
        }

        // ── V9 listeners after browsing 1,000 rows ──────────────────────────────
        for (let i = 0; i <= 1000; i += 150) await scrollTo(page, i);
        const listenersAfter = await listeners(page);
        assert.ok(
            listenersAfter - listenersAfterRun <= 10,
            `V9: browsing 1,000 rows leaves the JS event listeners as they were (${listenersAfterRun} to ${listenersAfter})`
        );

        // ── V6 other syncs leave the list alone ─────────────────────────────────
        await scrollTo(page, 3000);
        await L.sleep(300);
        const at = `(function(){ var st = ${L.STATE}; var h = ${HOST}; var r = [...h.children].find(function(el){ return el.getAttribute('aria-rowindex') === String(Math.floor(st.scrollTop / st.itemSize) + 2); }); return { id: st.id, offset: st.offset, scrollTop: st.scrollTop, text: r ? r.textContent : null }; })()`;
        const before = await page.evaluate(at);
        assert.ok(before.text, 'V6: the first visible row is there');
        if (await page.evaluate(`!!document.querySelector('#flowMessage .notice button[data-variant="close"]')`)) {
            await nextSync(
                page,
                () => page.evaluate(`document.querySelector('#flowMessage .notice button[data-variant="close"]').click()`),
                'V6: the dismissal to sync'
            );
        }
        await nextSync(
            page,
            () => page.evaluate(`document.getElementById('flowsGraphUnit_packets').click()`),
            'V6: touch-flows-graph to sync'
        );
        await L.sleep(300);
        assert.deepEqual(await page.evaluate(at), before, 'V6: offset, scroll position and the first visible row stay');

        // ── V11 another result tab and back ─────────────────────────────────────
        await page.evaluate(`document.getElementById('flowsTab-raw').click()`);
        await page.waitFor(`document.getElementById('flowsPanel-flows').hidden`, { label: 'V11: the Raw output tab' });
        await page.evaluate(`document.getElementById('flowsTab-flows').click()`);
        await page.waitFor(`!document.getElementById('flowsPanel-flows').hidden`, { label: 'V11: the Flows tab' });
        const back = await page.evaluate(L.STATE);
        assert.equal(back.id, before.id, 'V11: the same list');
        assert.ok(back.rows > 0, 'V11: its rows are still there');
        await scrollTo(page, 7000);
        assert.notEqual((await page.evaluate(L.STATE)).offset, back.offset, 'V11: a scroll afterwards gets a window');

        // ── V12 keyboard ────────────────────────────────────────────────────────
        await scrollTo(page, 0);
        await page.evaluate(`document.getElementById('flowsTab-flows').focus()`);
        let steps = 0;
        while (steps < 12 && !(await page.evaluate(`document.activeElement?.matches('button[data-sort-key]') ?? false`))) {
            await press(page, 'Tab');
            steps++;
        }
        assert.equal(
            await page.evaluate(`document.activeElement === ${HOST}.querySelector('button[data-sort-key]')`),
            true,
            'V12: Tab from the Flows tab reaches the first sort button'
        );
        let link = false;
        for (let i = 0; i < 60 && !link; i++) {
            await press(page, 'Tab');
            link = await page.evaluate(`!!document.activeElement?.matches('a.ip-link') && ${HOST}.contains(document.activeElement)`);
        }
        assert.ok(link, 'V12: Tab then reaches an IP link in the list');
        await page.evaluate(`${HOST}.querySelector('button[data-sort-key]').focus()`);
        const top = await page.evaluate(L.STATE);
        await press(page, 'PageDown');
        await page.waitFor(`${L.STATE}.scrollTop > ${top.scrollTop}`, { label: 'V12: PageDown scrolls the list' });
        await L.sleep(200);
        await L.waitCovered(page, { label: 'V12: the window after PageDown' });
        const paged = await page.evaluate(L.STATE);
        // Chromium's page step is 87.5 % of the view below the sticky header (scroll-padding), leaving an overlap.
        assert.ok(
            paged.scrollTop - top.scrollTop >= Math.floor(top.view * 0.875) - 1,
            `V12: PageDown scrolls the list by a page of its view (${paged.scrollTop - top.scrollTop} px of ${top.view} px)`
        );
        assert.equal(await page.evaluate(`${HOST}.contains(document.activeElement)`), true, 'V12: the focus stays in the list');

        // ── V13 Tab through 200 rows and Shift+Tab back through 50, from row 5,000 and from row 0 ──
        for (const from of [5000, 0]) {
            assert.equal(
                await tabWalk(page, from, 200),
                null,
                `V13: Tab walks 200 rows from row ${from} one at a time and stays in the list`
            );
            assert.equal(
                await tabWalk(page, from + 200, 50, true),
                null,
                `V13: Shift+Tab walks 50 rows back from row ${from + 200} one at a time and stays in the list`
            );
        }

        // ── V15 Tab across the header leaves the rows where they are ────────────
        await scrollTo(page, 5000);
        await L.sleep(300);
        const firstInView = `(function(){ var st = ${L.STATE}; return { top: st.scrollTop, row: Math.floor(st.scrollTop / st.itemSize) }; })()`;
        const header0 = await page.evaluate(firstInView);
        await page.evaluate(`${HOST}.querySelector('button[data-sort-key]').focus({ preventScroll: true })`);
        for (let i = 0; i < 12; i++) await press(page, 'Tab');
        await L.sleep(300);
        assert.deepEqual(await page.evaluate(firstInView), header0, 'V15: 12 Tab presses across the header leave the first row in view');

        // ── V5 an IP link ───────────────────────────────────────────────────────
        await clickAt(page, `${HOST}.querySelector('[role="row"]:not([slot]) a.ip-link')`);
        await page.waitFor(`document.getElementById('ip-modal-inner')?.isOpen`, { timeout: 15000, label: 'V5: the IP info dialog' });
        await page.evaluate(`document.getElementById('ip-modal-inner').close()`);

        // ── V4 sorts: stable, empty keys last, in the export and in the list ────
        const keys = await page.evaluate(L.KEYS);
        let previous = raw;
        for (const [key, dir] of SORTS) {
            await sortList(page, key, dir);
            const sortedRaw = await exported(page, false);
            const col = keys.indexOf(key);
            assert.deepEqual(sortedRaw, stableSort(previous, col, dir), `V4: ${key} ${dir} is a stable sort of the order before it`);
            const sortedShown = await exported(page, true);
            const rows = [...(await readList(page, 0, 100)), ...(await readList(page, 5000, 5100))];
            assert.deepEqual(
                rows,
                [...sortedShown.slice(0, 100), ...sortedShown.slice(5000, 5100)],
                `V4: rows 0 to 99 and 5,000 to 5,099 after ${key} ${dir}`
            );
            previous = sortedRaw;
        }

        // ── V16 the Columns picker, kept across a Run and a reload ──────────────
        const POPOVER = `document.getElementById('flowTable-columnsPopover')`;
        await page.evaluate(`${POPOVER}.open || ${POPOVER}.querySelector('[slot="trigger"]').click()`);
        await page.waitFor(`${POPOVER}.open === true`, { label: 'V16: Columns to open' });
        await page.evaluate(`document.getElementById('flowTable-col-src_port').click()`);
        await page.waitFor(`!${HOST}.querySelector('button[data-sort-key="src_port"]')`, {
            timeout: 15000,
            label: 'V16: Source Port to leave the header',
        });
        await page.evaluate(`${POPOVER}.hide?.()`);
        await geometry(page, 'comfortable');
        const withoutPort = await page.evaluate(L.KEYS);
        assert.ok(!withoutPort.includes('src_port') && withoutPort.length === keys.length - 1, 'V16: the header lost Source Port only');
        await L.run(page);
        assert.deepEqual(await page.evaluate(L.KEYS), withoutPort, 'V16: another Run keeps Source Port hidden');
        await page.reload();
        await page.waitForBoot();
        await page.waitForPage('flows');
        await L.prepare(page);
        await L.run(page);
        assert.deepEqual(await page.evaluate(L.KEYS), withoutPort, 'V16: a reload and a Run keep Source Port hidden');
        const [lastKey, lastDir] = SORTS.at(-1);
        assert.ok(
            await page.evaluate(
                `!!${HOST}.querySelector('[aria-sort="${lastDir === 'desc' ? 'descending' : 'ascending'}"] > button[data-sort-key="${lastKey}"]')`
            ),
            'V16: a reload and a Run keep the sort'
        );

        // ── V17 exports of the 10,000 rows in the list's order and columns ──────
        const reference = await exported(page, true);
        assert.equal(reference.length, L.LIMIT, 'V17: JSON holds every row');
        assert.deepEqual(await readList(page, 0, 20), reference.slice(0, 20), "V17: JSON starts with the list's first rows");
        const csv = await L.exportList(page, 'csv', { enhanced: true });
        const parsed = parseCsv(csv.text);
        assert.equal(parsed.length, L.LIMIT + 1, 'V17: CSV holds the title line and every row');
        assert.deepEqual(parsed[0], await page.evaluate(L.TITLES), 'V17: CSV has the shown columns, Source Port not among them');
        assert.deepEqual([parsed[1], parsed.at(-1)], [reference[0], reference.at(-1)], "V17: CSV's first and last rows are the list's");
        assert.match(csv.name, /^flows-\d{12}-\d{12}\.csv$/, 'V17: the CSV file name');
        const printed = await L.exportList(page, 'print');
        assert.equal(printed.rows.length, L.LIMIT + 1, 'V17: Print shows the title row and every row');
        assert.deepEqual(printed.rows[0], parsed[0], 'V17: Print has the shown columns');
        assert.deepEqual(printed.rows[1], reference[0], "V17: Print starts with the list's first row");

        // ── V8 and V7 a new result ──────────────────────────────────────────────
        await scrollTo(page, 2000);
        await page.evaluate(`(function(){
            var ids = ['flowsQueryFields', 'flowsGraph', 'flowsRun'];
            window.__v8 = ids.map(function(id){ return [id, new WeakRef(document.getElementById(id))]; });
            document.getElementById('filterFlowAggregation').open = true;
            var old = ${HOST};
            window.__v7 = { id: old.id, ref: new WeakRef(old) };
        })()`);
        await L.run(page);
        await page.waitFor(`${HOST} && ${HOST}.id !== window.__v7.id`, { label: 'V7: the new list' });
        assert.equal((await page.evaluate(L.STATE)).scrollTop, 0, 'V7: the new list starts at the top');
        for (let i = 0; i < 3; i++) await page.send('HeapProfiler.collectGarbage');
        assert.equal(await page.evaluate(`window.__v7.ref.deref() === undefined`), true, 'V7: the old list is collected');
        const v8 = await page.evaluate(
            `window.__v8.map(function(e){ return [e[0], e[1].deref() === document.getElementById(e[0])]; }).concat([['aggregation open', document.getElementById('filterFlowAggregation').open]])`
        );
        assert.deepEqual(
            v8,
            [
                ['flowsQueryFields', true],
                ['flowsGraph', true],
                ['flowsRun', true],
                ['aggregation open', true],
            ],
            'V8: the elements around the list are the same ones, and the open details stays open'
        );
        await page.evaluate(`document.getElementById('filterFlowAggregation').open = false`);

        // ── V10 look and geometry ───────────────────────────────────────────────
        for (const theme of ['light', 'dark']) {
            await setTheme(page, theme);
            await look(page, theme, false);
        }
        await page.withForcedColors(() => look(page, 'forced', true));
        await setTheme(page, 'light');
        try {
            await L.saveSettings(page, { compact: true });
            await L.openFlows(page);
            await L.prepare(page);
            await L.run(page);
            await geometry(page, 'compact');
            await look(page, 'compact', false);
        } finally {
            await L.saveSettings(page, { compact: false });
        }

        // ── V14 a context revived by a window request ───────────────────────────
        await L.openFlows(page);
        await L.prepare(page);
        await L.run(page);
        await revival(page);

        const again = await page.evaluate(SCAN);
        assert.deepEqual([again.problems, again.undefinedTags, again.unnamed], [[], [], []], 'V9: the rules hold at the end');
        assert.deepEqual(page.realErrors(), [], 'V9: no console error during the file');
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    vscrollTest()
        .then(() => console.log('vscroll: PASS'))
        .catch((e) => {
            console.error('vscroll: FAIL\n', e);
            process.exit(1);
        });
}
