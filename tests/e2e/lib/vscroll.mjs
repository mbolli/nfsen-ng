// The Flows list for the browser tests: the 10,000-row query, the list host and its coverage, the server's exports
// caught before they are saved, and the instance settings the runs need.
import { BASE } from './cdp.mjs';
import { ddChoose, ddOpen, ddTrigger } from './dropdown.mjs';
import { CLEAR_TOASTS, toasts } from './toasts.mjs';

/** The dev captures' day of flows: exactly 10,000 rows at limit 10,000. */
export const RANGE = { from: 1787875200, to: 1787961600 };
export const LIMIT = 10000;

export const HOST = `document.querySelector('sb-virtual-scroll[id^="flowRows-"]')`;

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** A seeded generator (mulberry32), so every run jumps to the same rows. */
export function seeded(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Sets signals by name in Datastar's store, as filter-validation.test.mjs does. */
export async function setSignals(page, values) {
    await page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        var values = ${JSON.stringify(values)};
        Object.keys(values).forEach(function(name){
            var key = Object.keys(root).find(function(k){ return k === name || k.startsWith(name + '____'); });
            if (key === undefined) throw new Error('no signal ' + name);
            root[key] = values[name];
        });
    })()`);
}

/** The values of signals by name, from Datastar's store. */
export async function signals(page, names) {
    return page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        return Object.fromEntries(${JSON.stringify(names)}.map(function(name){
            var key = Object.keys(root).find(function(k){ return k === name || k.startsWith(name + '____'); });
            return [name, key === undefined ? undefined : root[key]];
        }));
    })()`);
}

export async function openFlows(page) {
    await page.navigate(`${BASE}/#/flows`);
    await page.waitForBoot();
    await page.waitForPage('flows');
}

/** The 10,000-row query: fixed window, no filter, limit 10,000, no aggregation. */
export async function prepare(page, { limit = LIMIT } = {}) {
    await setSignals(page, {
        range_live: false,
        datestart: RANGE.from,
        dateend: RANGE.to,
        flows_limit: limit,
        flows_filter: '',
        flows_lower_limit: '',
        flows_upper_limit: '',
        flows_agg_bidirectional: false,
        flows_agg_proto: false,
        flows_agg_srcport: false,
        flows_agg_dstport: false,
        flows_agg_srcip: 'none',
        flows_agg_dstip: 'none',
        flows_orderByTstart: false,
    });
}

/** The Run button's click through its real handler; resolves once the query finished and the list is there. */
export async function run(page, { timeout = 120000 } = {}) {
    await page.runQuery('flows', { timeout });
    await page.waitFor(`${HOST}?.hasAttribute('total')`, { timeout: 15000, label: 'the list' });
}

/** The list's geometry and window, read in the page. */
export const STATE = `(function(){
    var h = ${HOST};
    if (!h) return null;
    var s = h.shadowRoot && h.shadowRoot.querySelector('.scroller');
    var head = h.shadowRoot && h.shadowRoot.querySelector('.header');
    var rows = [...h.children].filter(function(el){ return el.getAttribute('slot') !== 'header'; });
    return {
        id: h.id,
        offset: Number(h.getAttribute('offset')),
        total: h.hasAttribute('total') ? Number(h.getAttribute('total')) : null,
        itemSize: Number(h.getAttribute('item-size')),
        rows: rows.length,
        scrollTop: s ? s.scrollTop : 0,
        view: s ? s.clientHeight - (head ? head.offsetHeight : 0) : 0,
        loading: h.matches(':state(loading)'),
    };
})()`;

/** Whether the rows in view are all there. */
export const COVERED = `(function(){
    var st = ${STATE};
    if (!st || st.total === null) return false;
    var first = Math.floor(st.scrollTop / st.itemSize);
    var last = Math.ceil((st.scrollTop + st.view) / st.itemSize);
    return st.offset <= first && st.offset + st.rows >= Math.min(last, st.total);
})()`;

export async function waitCovered(page, { timeout = 10000, label = 'the list to cover the view' } = {}) {
    await page.waitFor(COVERED, { timeout, interval: 16, label });
}

/** Cell texts of rows `indices` (positions in the list) of the list's current window; null for a row not in it. */
export function listRows(indices) {
    return `(function(){
        var h = ${HOST};
        var offset = Number(h.getAttribute('offset'));
        var rows = [...h.children].filter(function(el){ return el.getAttribute('slot') !== 'header'; });
        return ${JSON.stringify(indices)}.map(function(i){
            var r = rows[i - offset];
            return r ? [...r.children].map(function(c){ return c.textContent.replace(/\\s+/g, ' ').trim(); }) : null;
        });
    })()`;
}

/** Column titles of the list's header, in order. */
export const TITLES = `[...${HOST}.querySelectorAll('[slot="header"] button[data-sort-key]')].map(function(b){ return b.textContent.trim(); })`;

/** Column keys of the list's header, in order. */
export const KEYS = `[...${HOST}.querySelectorAll('[slot="header"] button[data-sort-key]')].map(function(b){ return b.dataset.sortKey; })`;

const EXPORT_TRIGGER = ddTrigger('flowsExport');
const EXPORT_OPEN = ddOpen('flowsExport');
const EXPORT_LABELS = { csv: 'CSV', json: 'JSON', print: 'Print' };

/**
 * Exports the list through the Export menu in `format` (csv, json or print) with Enhanced data on or off.
 * CSV and JSON: the file as the browser would save it, caught before it is; Print: the rows of the print frame.
 */
export async function exportList(page, format, { enhanced = true, timeout = 60000 } = {}) {
    await page.evaluate(`(function(){
        var box = document.getElementById('flowTable-enhanced');
        if (box.checked !== ${enhanced}) box.click();
        window.__exported = null;
        window.__printed = null;
        window.__createObjectURL ??= URL.createObjectURL;
        URL.createObjectURL = function (blob) { window.__exported = blob; return 'blob:e2e'; };
        window.__anchorClick ??= HTMLAnchorElement.prototype.click;
        HTMLAnchorElement.prototype.click = function () { window.__download = this.download; };
        document.querySelector('iframe[title="Flows to print"]')?.remove();
    })()`);
    await page.evaluate(`${EXPORT_OPEN} || ${EXPORT_TRIGGER}.click()`);
    await page.waitFor(EXPORT_OPEN, { label: `Export to open for ${format}` });
    await page.evaluate(ddChoose('flowsExport', EXPORT_LABELS[format]));
    try {
        if (format === 'print') {
            await page.waitFor(`document.querySelector('iframe[title="Flows to print"]')?.contentDocument?.querySelector('tbody tr')`, {
                timeout,
                label: 'the print frame',
            });
            return page.evaluate(`(function(){
                var doc = document.querySelector('iframe[title="Flows to print"]').contentDocument;
                var rows = [...doc.querySelectorAll('tr')].map(function(tr){ return [...tr.children].map(function(c){ return c.textContent; }); });
                return { rows: rows, title: doc.querySelector('h1').textContent };
            })()`);
        }
        await page.waitFor('!!window.__exported', { timeout, label: `the ${format} export` });
        return JSON.parse(
            await page.evaluate(
                `window.__exported.text().then(function(text){ return JSON.stringify({ text: text, name: window.__download }); })`
            )
        );
    } finally {
        await page.evaluate(`URL.createObjectURL = window.__createObjectURL; HTMLAnchorElement.prototype.click = window.__anchorClick;`);
    }
}

/**
 * Saves General settings through the Settings page's real Save: compact tables, display time zone
 * ('browser' or 'server') and the log level. Values left undefined stay. Reloads the page afterwards.
 */
export async function saveSettings(page, { compact, displayTz, logLevel } = {}) {
    await page.navigate(`${BASE}/#/settings`);
    await page.waitForBoot();
    await page.waitForPage('settings');
    await page.evaluate(`document.getElementById('settingsTab-general')?.click()`);
    if (compact !== undefined) {
        await page.evaluate(
            `(function(){ var b = document.getElementById('settingsCompactTables'); if (b.checked !== ${compact}) b.click(); })()`
        );
    }
    if (displayTz !== undefined) {
        await page.evaluate(`document.getElementById('settingsDisplayTz-${displayTz}').click()`);
    }
    if (logLevel !== undefined) {
        await page.setSelectValue('#settingsLogPriority', logLevel);
    }
    await page.evaluate(CLEAR_TOASTS);
    await page.evaluate(`document.getElementById('settingsSave').click()`);
    await page.waitFor(
        `${toasts('#alerts-toast-container')}.some(function(t){ return t.variant === 'danger' || t.text === 'Settings saved.'; })`,
        { timeout: 15000, label: 'the settings to save' }
    );
    const failed = await page.evaluate(
        `${toasts('#alerts-toast-container')}.some(function(t){ return t.variant === 'danger'; })`
    );
    if (failed) throw new Error('Settings could not be saved');
    // data-density comes with the document.
    await page.reload();
    await page.waitForBoot();
}

/** nfsen-table's compareValues on raw values: numbers as numbers, IPv4 as 32-bit numbers, else text; empty last. */
export function compareRaw(a, b) {
    const empty = (v) => v === '' || v === null || v === undefined;
    if (empty(a) || empty(b)) return empty(a) === empty(b) ? 0 : empty(a) ? 1 : -1;
    const ip = (v) => (/^\d+\.\d+\.\d+\.\d+$/.test(v) ? v.split('.').reduce((n, o) => n * 256 + Number(o), 0) : null);
    const [ia, ib] = [ip(a), ip(b)];
    if (ia !== null && ib !== null) return ia - ib;
    const [na, nb] = [Number(a), Number(b)];
    if (a.trim() !== '' && b.trim() !== '' && !Number.isNaN(na) && !Number.isNaN(nb)) return na - nb;
    return a < b ? -1 : a > b ? 1 : 0;
}
