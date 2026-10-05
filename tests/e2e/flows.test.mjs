// Flows (4.3, 5.4): run, the list, tabs, exports from the server and the Export popover, a new result's list, 10,000
// rows in three tabs without a worker crash, keyboard walk and forced-colors screenshots to E2E_SHOTS (V-A11Y).
// vscroll.test.mjs covers the list itself: windows, sorts, columns, the keyboard, revival.
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { BASE, withPage } from './lib/cdp.mjs';

const SHOTS = process.env.E2E_SHOTS || '/tmp';
const HOST = `document.querySelector('sb-virtual-scroll[id^="flowRows-"]')`;
const COLUMNS = `document.getElementById('flowTable-columnsPopover')`;
const EXPORT = `document.getElementById('flowsExport')`;
const EXPORT_TRIGGER = `document.querySelector('#flowsExport [slot="trigger"]')`;
// The Flows page's actions that run nfdump over capture files (no-auto-query.test.mjs's READS_CAPTURE_FILES).
const READS_CAPTURE_FILES = ['flow-actions', 'flows-summary-run', 'build-flows-graph'];
const captureReads = (log) => log.names().filter((name) => READS_CAPTURE_FILES.includes(name));

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** A key press; Enter and Space carry their text, which is what activates a focused button. */
async function press(page, key, code = key, keyCode = 0) {
    const text = { Enter: '\r', ' ': ' ' }[key];
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode, text });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode });
}

/** Bytes the SSE stream delivers from now on. */
function sseMeter(page) {
    let id = null;
    let bytes = 0;
    page.ws.addEventListener('message', (event) => {
        const msg = JSON.parse(event.data);
        if (msg.method === 'Network.requestWillBeSent' && msg.params.request.url.includes('/_sse')) id = msg.params.requestId;
        if (msg.method === 'Network.dataReceived' && msg.params.requestId === id) bytes += msg.params.dataLength;
    });
    return {
        reset: () => {
            bytes = 0;
        },
        bytes: () => bytes,
    };
}

/** From now on the file an export hands to the browser is caught before it is saved. */
async function catchExport(page) {
    await page.evaluate(`(function(){
        window.__exported = null;
        window.__createObjectURL ??= URL.createObjectURL;
        URL.createObjectURL = function (blob) { window.__exported = blob; window.__exportedAt = performance.now(); return 'blob:e2e'; };
        window.__anchorClick ??= HTMLAnchorElement.prototype.click;
        HTMLAnchorElement.prototype.click = function () { window.__download = this.download; };
    })()`);
}

/** The caught file and its name, once the export has made it; the browser's own functions are back afterwards. */
async function caughtExport(page, label) {
    await page.waitFor('!!window.__exported', { label });
    const result = await page.evaluate(
        `window.__exported.text().then(function(text){ return JSON.stringify({ text: text, name: window.__download }); })`
    );
    await page.evaluate(`URL.createObjectURL = window.__createObjectURL; HTMLAnchorElement.prototype.click = window.__anchorClick;`);
    return JSON.parse(result);
}

/** Opens the Export popover, chooses `format` (csv or json) and returns the file; choosing closes the popover. */
async function captureExport(page, format) {
    await catchExport(page);
    await page.evaluate(`${EXPORT}.open || ${EXPORT_TRIGGER}.click()`);
    await page.waitFor(`${EXPORT}.open === true`, { label: `Export to open for ${format}` });
    await page.evaluate(`document.querySelector('#flowsExportMenu [data-export="${format}"]').click()`);
    assert.equal(await page.evaluate(`${EXPORT}.open`), false, `choosing ${format} closes Export`);
    return caughtExport(page, `${format} export`);
}

/** Whether a popover is open, checked against its panel, and the rounded viewport boxes of its trigger and panel. */
const popoverBoxes = (host) => `(function(){
    var host = document.querySelector(${JSON.stringify(host)});
    var pop = host.shadowRoot.querySelector('.pop');
    var round = function(r){ return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right) }; };
    return {
        open: host.open === true && pop.matches(':popover-open'),
        trigger: round(host.querySelector('[slot="trigger"]').getBoundingClientRect()),
        panel: round(pop.getBoundingClientRect()),
        view: { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight },
    };
})()`;

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

/** A real press and release: it focuses a button, and over plain HTTP only user activation lets execCommand copy. */
async function clickAt(page, expr) {
    const { x, y } = await page.evaluate(`(function(){
        var el = ${expr};
        el.scrollIntoView({ block: 'center' });
        var r = el.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    })()`);
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
    return { x, y };
}

/**
 * Records each nfsen-copy and each text #nfsen-announcer says. Over plain HTTP the textarea's
 * execCommand fires a copy event, whose selection is what reached the clipboard.
 */
const WATCH_COPIES = `(function(){
    if (window.__copies) return;
    window.__copies = [];
    window.__said = [];
    var viaExecCommand = null;
    document.addEventListener('copy', function(e){
        var t = e.target;
        viaExecCommand = t instanceof HTMLTextAreaElement ? t.value.slice(t.selectionStart, t.selectionEnd) : null;
    }, true);
    document.addEventListener('nfsen-copy', function(e){
        window.__copies.push({ source: e.target.dataset.copySource, ok: e.detail.ok, text: e.detail.text, viaExecCommand: viaExecCommand });
        viaExecCommand = null;
    });
    new MutationObserver(function(records){
        records.forEach(function(r){ r.addedNodes.forEach(function(n){ if (n.textContent) window.__said.push(n.textContent); }); });
    }).observe(document.getElementById('nfsen-announcer'), { childList: true, subtree: true, characterData: true });
})()`;

/** Clicks a copy button that reads Copy and checks what it copied, its Copied label and the announcement. */
async function copyWith(page, button, expected, label) {
    await page.waitFor(`${button}.textContent === 'Copy'`, { timeout: 4000, label: `${label}: the button to read Copy first` });
    const [copies, said] = await page.evaluate('[window.__copies.length, window.__said.length]');
    await clickAt(page, button);
    await page.waitFor(`window.__copies.length > ${copies}`, { timeout: 15000, label: `${label}: the copy` });
    const copy = await page.evaluate('window.__copies.at(-1)');
    assert.equal(copy.ok, true, `${label}: the copy succeeded`);
    assert.equal(copy.text, expected, `${label}: what is copied`);
    if (!(await page.evaluate('window.isSecureContext'))) {
        assert.equal(copy.viaExecCommand, expected, `${label}: over plain HTTP the textarea fallback copied it`);
    }
    await page.waitFor(`${button}.textContent === 'Copied'`, { label: `${label}: the button to say Copied` });
    await page.waitFor(`window.__said.length > ${said}`, { label: `${label}: #nfsen-announcer` });
    assert.equal(await page.evaluate(`window.__said.at(-1)`), 'Copied.', `${label}: the announcement`);
}

async function shot(page, path, selector) {
    const box = JSON.parse(
        await page.evaluate(
            `JSON.stringify((function(){ var r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect(); return { x: r.left + scrollX, y: r.top + scrollY, width: r.width, height: Math.min(r.height, 2400) }; })())`
        )
    );
    const { data } = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { ...box, scale: 1 } });
    writeFileSync(path, Buffer.from(data, 'base64'));
}

const activeId = 'document.activeElement?.id';
/** The list's row count (its total), once it holds rows; else 0. */
const listTotal = `(${HOST}?.hasAttribute('total') ? Number(${HOST}.getAttribute('total')) : 0)`;
const listRows = `(${HOST} ? [...${HOST}.children].filter(function(el){ return !el.slot; }).length : 0)`;
const rawOutput = `document.querySelector('[id^="flowsRawOutput-"]')`;

async function runLargeTab(page, label) {
    await page.navigate(`${BASE}/`);
    await page.waitForBoot();
    await page.gotoPage('flows');
    await page.setRangePreset('1y');
    await page.setSelectValue('#filterFlowsLimit select', 10000);
    await page.evaluate('window.__e2eTab = true');
    await page.runQuery('flows', { timeout: 120000 });
    await page.waitFor(`${listTotal} === 10000`, { timeout: 60000, label: `${label}: the list of 10,000 rows` });
}

/**
 * POPOVER-SPEC section 6, item 4: the Export host only ever arrives in a run's morph, which is its first load. Export
 * and CSV are chosen in the frames after the trigger first shows, before any later sync, and the file follows.
 */
async function exportRightAway() {
    await withPage(async (page) => {
        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        await page.gotoPage('flows');
        await page.setRangePreset('1y');
        await page.setSelectValue('#filterFlowsLimit select', 20);
        await catchExport(page);
        await page.evaluate(`(function(){
            var ready = function(){ var t = ${EXPORT_TRIGGER}; return !!t && !t.disabled; };
            // Once the next frame is painted: the earliest a hand can press what that frame shows.
            var painted = function(fn){ requestAnimationFrame(function(){ setTimeout(fn, 0); }); };
            new MutationObserver(function(records, observer){
                if (!ready()) return;
                observer.disconnect();
                var content = document.getElementById('page-content');
                content.setAttribute('data-e2e-unsynced', '');
                window.__e2eEarly = { at: performance.now() };
                painted(function(){
                    ${EXPORT_TRIGGER}.click();
                    painted(function(){
                        var open = ${EXPORT}.open === true;
                        var unsynced = content.isConnected && content.hasAttribute('data-e2e-unsynced');
                        document.querySelector('#flowsExportMenu [data-export="csv"]').click();
                        var closed = ${EXPORT}.open === false;
                        Object.assign(window.__e2eEarly, { open: open, unsynced: unsynced, closed: closed, chosen: true });
                    });
                });
            }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
        })()`);
        await page.runQuery('flows', { timeout: 60000 });
        await page.waitFor('window.__e2eEarly?.chosen === true', {
            timeout: 15000,
            label: 'Export and CSV chosen once the result is there',
        });
        const csv = await caughtExport(page, 'the CSV chosen right away');
        const early = await page.evaluate('Object.assign({ made: window.__exportedAt }, window.__e2eEarly)');
        assert.deepEqual(
            { open: early.open, unsynced: early.unsynced, closed: early.closed },
            { open: true, unsynced: true, closed: true },
            'before any sync after the result, the trigger opened Export and choosing CSV closed it'
        );
        const took = Math.round(early.made - early.at);
        assert.ok(took >= 0 && took < 1000, `the CSV was made within a second of the trigger showing (${took} ms)`);
        const rows = await page.evaluate(listTotal);
        assert.ok(rows > 0, 'the result has rows');
        assert.equal(csv.text.trimEnd().split('\n').length, rows + 1, 'the CSV chosen right away holds every row');
        assert.match(csv.name, /^flows-\d{12}-\d{12}\.csv$/, `export file name: ${csv.name}`);
        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors using Export right away, got:\n${errors.join('\n')}`);
    });
}

/** POPOVER-SPEC section 6, item 11: at 390 x 844 Export and Columns open inside the viewport and their triggers stay put. */
async function popoversOnPhone() {
    await withPage(
        async (page) => {
            await page.navigate(`${BASE}/`);
            await page.waitForBoot();
            await page.gotoPage('flows');
            await page.setRangePreset('1y');
            await page.setSelectValue('#filterFlowsLimit select', 20);
            await page.runQuery('flows', { timeout: 60000 });
            await page.waitFor(`${listTotal} > 0`, { timeout: 15000, label: 'the flow rows on a phone' });
            const log = await page.requestLog();
            for (const [label, host] of [
                ['Export', '#flowsExport'],
                ['Columns', '#flowTable-columnsPopover'],
            ]) {
                const trigger = `document.querySelector(${JSON.stringify(`${host} [slot="trigger"]`)})`;
                await page.waitFor(`!!${trigger}`, { label: `${label}: the trigger on a phone` });
                await page.evaluate(`${trigger}.scrollIntoView({ block: 'center' })`);
                await sleep(300);
                const before = await page.evaluate(popoverBoxes(host));
                await tapAt(page, trigger);
                const { open, trigger: moved, panel, view } = await page.evaluate(popoverBoxes(host));
                assert.equal(open, true, `a tap opens ${label}`);
                assert.equal(view.width, 390);
                assert.deepEqual(moved, before.trigger, `${label}: the trigger stays where it was`);
                const inside = panel.left >= 0 && panel.top >= 0 && panel.right <= view.width && panel.bottom <= view.height;
                assert.ok(
                    inside && panel.bottom > panel.top,
                    `${label}: the panel lies inside the viewport: ${JSON.stringify({ panel, view })}`
                );
                await press(page, 'Escape', 'Escape', 27);
                await page.waitFor(`!${popoverBoxes(host)}.open`, { label: `${label}: Escape to close it` });
            }
            assert.deepEqual(captureReads(log), [], 'tapping Export and Columns ran no capture-file query');
            const errors = page.realErrors();
            assert.deepEqual(errors, [], `expected no console errors on a phone, got:\n${errors.join('\n')}`);
        },
        { width: 390, height: 844, mobile: true }
    );
}

export default async function flowsTest() {
    await withPage(async (page) => {
        const log = await page.requestLog();
        const sse = sseMeter(page);
        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        await page.gotoPage('flows');
        await page.setRangePreset('1y');

        // The cost shows before anything runs, and opening the page ran nothing.
        await page.waitFor(`document.querySelector('[data-estimate="flows"]')?.dataset.state === 'ready'`, {
            timeout: 15000,
            label: 'the Flows estimate',
        });
        const estimate = await page.evaluate(`document.querySelector('[data-estimate="flows"] .estimate-figures').innerText`);
        assert.match(estimate, /[\d,]+ files?/, `the estimate names the files: ${estimate}`);
        assert.equal(log.count('flow-actions'), 0, 'opening Flows ran no query');
        assert.match(await page.evaluate(`document.getElementById('flowResults').textContent`), /Set a filter and press Run\./);

        await page.setSelectValue('#filterFlowsLimit select', 100);
        await page.runQuery('flows', { timeout: 60000 });
        await page.waitFor(`${listTotal} > 0`, { timeout: 15000, label: 'the flow rows' });
        await page.waitFor(`/^[\\d,]+ (flows|rows) returned\\./.test(document.querySelector('#flowsRun [role="status"]').textContent)`, {
            label: 'the Run control to announce the count',
        });
        const notice = await page.evaluate(`document.getElementById('flowMessage').textContent`);
        assert.match(notice, /nfdump:/, `the notice quotes the command: ${notice}`);
        assert.doesNotMatch(notice, /error/i, `no error: ${notice}`);

        // The list: every row returned, a window of them in the document, no paged table.
        const total = await page.evaluate(listTotal);
        assert.ok(total > 50, `a year of dev captures returns more than 50 rows (${total})`);
        assert.equal(await page.evaluate(`!!document.getElementById('flowTable')`), false, 'no paged table');
        assert.ok((await page.evaluate(listRows)) > 0, 'the list holds rows');
        assert.equal(await page.evaluate(`${HOST}.getAttribute('aria-rowcount')`), String(total + 1), 'the list says how many rows');
        assert.equal(await page.evaluate(`document.getElementById('flowsTab-flows').textContent.trim()`), `Flows (${total})`);

        // A sort orders every row, which the export shows.
        await page.evaluate(`${HOST}.querySelector('button[data-sort-key="in_bytes"]').click()`);
        await page.waitFor(
            `!!${HOST}.querySelector('[aria-sort="ascending"] > button[data-sort-key="in_bytes"]') && !${HOST}.matches(':state(loading)')`,
            {
                label: 'the list sorted by bytes',
            }
        );
        await page.evaluate(
            `document.getElementById('flowTable-enhanced').checked || document.getElementById('flowTable-enhanced').click()`
        );
        await page.evaluate(`document.getElementById('flowTable-enhanced').click()`);
        const byBytes = JSON.parse((await captureExport(page, 'json')).text).map((row) => Number(row['In Bytes'] ?? row.Bytes));
        await page.evaluate(`document.getElementById('flowTable-enhanced').click()`);
        assert.equal(byBytes.length, total, 'the raw JSON holds every row');
        assert.deepEqual(
            byBytes,
            [...byBytes].sort((a, b) => a - b),
            'every row is in order'
        );

        // Exports hold the shown columns and every row. From here the popovers run no capture-file query.
        log.clear();
        await page.evaluate(`${COLUMNS}.querySelector('[slot="trigger"]').click()`);
        await page.evaluate(`document.getElementById('flowTable-col-received').click()`);
        await page.waitFor(`!${HOST}.querySelector('button[data-sort-key="received"]')`, { label: 'Received to leave the header' });
        await page.evaluate(`${COLUMNS}.hide()`);
        const titles = await page.evaluate(
            `[...${HOST}.querySelectorAll('[slot="header"] button[data-sort-key]')].map(function(b){ return b.textContent.trim(); })`
        );
        assert.ok(!titles.includes('Received'), 'the hidden column is gone from the header');
        const csv = await captureExport(page, 'csv');
        const lines = csv.text.trimEnd().split('\n');
        assert.deepEqual(lines[0].split(','), titles, 'the CSV header is the shown columns');
        assert.equal(lines.length, total + 1, 'the CSV holds every row');
        assert.match(csv.name, /^flows-\d{12}-\d{12}\.csv$/, `export file name: ${csv.name}`);
        const json = JSON.parse((await captureExport(page, 'json')).text);
        assert.equal(json.length, total);
        assert.deepEqual(Object.keys(json[0]), titles, 'the JSON keys are the shown columns');
        await page.evaluate(`${COLUMNS}.querySelector('[slot="trigger"]').click()`);
        await page.evaluate(`document.getElementById('flowTable-col-received').click()`);
        await page.waitFor(`!!${HOST}.querySelector('button[data-sort-key="received"]')`, { label: 'Received back in the header' });
        await page.evaluate(`${COLUMNS}.hide()`);

        // The Export popover by keyboard (POPOVER-SPEC 4.2): ArrowDown on the trigger opens it on CSV, Escape returns.
        const exportFocus = `({ open: ${EXPORT}.open, focus: document.activeElement === ${EXPORT_TRIGGER} ? 'trigger' : document.activeElement?.dataset.export ?? document.activeElement?.tagName })`;
        assert.deepEqual(
            await page.evaluate(
                `[${EXPORT}.shadowRoot.querySelector('slot[name="trigger"]').assignedElements().length, ${EXPORT_TRIGGER}.getAttribute('aria-haspopup')]`
            ),
            [1, 'dialog'],
            'Export has its own trigger, which sb-popover marks as opening a dialog'
        );
        assert.deepEqual(
            await page.evaluate(
                `[...document.querySelectorAll('#flowsExportMenu [data-export]')].map(function(b){ return b.textContent.trim(); })`
            ),
            ['CSV', 'JSON', 'Print']
        );
        await page.evaluate(`${EXPORT_TRIGGER}.focus()`);
        await press(page, 'ArrowDown', 'ArrowDown', 40);
        await page.waitFor(`${EXPORT}.open === true && document.activeElement?.dataset.export === 'csv'`, {
            label: 'ArrowDown to open Export on CSV',
        });
        assert.equal(await page.evaluate(`${EXPORT_TRIGGER}.getAttribute('aria-expanded')`), 'true');
        for (const [key, code, keyCode, to] of [
            ['ArrowDown', 'ArrowDown', 40, 'json'],
            ['End', 'End', 35, 'print'],
            ['ArrowDown', 'ArrowDown', 40, 'csv'],
            ['ArrowUp', 'ArrowUp', 38, 'print'],
            ['Home', 'Home', 36, 'csv'],
        ]) {
            await press(page, key, code, keyCode);
            assert.deepEqual(await page.evaluate(exportFocus), { open: true, focus: to }, `${key} moves to ${to} and wraps`);
        }
        await press(page, 'Escape', 'Escape', 27);
        await page.waitFor(`document.activeElement === ${EXPORT_TRIGGER} && ${EXPORT}.open === false`, {
            label: 'Escape to close Export onto its trigger',
        });
        assert.equal(await page.evaluate(`${EXPORT_TRIGGER}.getAttribute('aria-expanded')`), 'false');
        for (const [key, code, keyCode] of [
            ['Enter', 'Enter', 13],
            [' ', 'Space', 32],
        ]) {
            await press(page, key, code, keyCode);
            await page.waitFor(`${EXPORT}.open === true && document.activeElement?.dataset.export === 'csv'`, {
                label: `${code} on the trigger to open Export on CSV`,
            });
            await press(page, 'Escape', 'Escape', 27);
            await page.waitFor(`document.activeElement === ${EXPORT_TRIGGER} && ${EXPORT}.open === false`, {
                label: `Escape after ${code}`,
            });
        }

        // Enter on an item exports, closes the popover and gives the focus back to the trigger.
        await catchExport(page);
        await press(page, 'ArrowDown', 'ArrowDown', 40);
        await press(page, 'ArrowDown', 'ArrowDown', 40);
        assert.deepEqual(await page.evaluate(exportFocus), { open: true, focus: 'json' });
        await press(page, 'Enter', 'Enter', 13);
        const byKey = await caughtExport(page, 'the JSON export by Enter');
        assert.equal(JSON.parse(byKey.text).length, total, 'Enter on JSON exported every row');
        assert.match(byKey.name, /^flows-\d{12}-\d{12}\.json$/, `export file name: ${byKey.name}`);
        assert.deepEqual(await page.evaluate(exportFocus), { open: false, focus: 'trigger' }, 'choosing closed Export onto its trigger');

        // A sync morph keeps it open with the focus on the same item; an outside press closes it.
        await press(page, 'ArrowDown', 'ArrowDown', 40);
        await press(page, 'ArrowDown', 'ArrowDown', 40);
        await page.evaluate('window.__e2eExportItem = document.activeElement');
        await page.syncNow('flows');
        await sleep(300);
        assert.deepEqual(
            await page.evaluate(
                `({ state: ${popoverBoxes('#flowsExport')}.open, same: document.activeElement === window.__e2eExportItem && window.__e2eExportItem.isConnected, focus: document.activeElement?.dataset.export, expanded: ${EXPORT_TRIGGER}.getAttribute('aria-expanded') })`
            ),
            { state: true, same: true, focus: 'json', expanded: 'true' },
            'a sync keeps Export open with the focus on JSON'
        );
        await clickAt(page, `document.querySelector('#flowResults > header > .card-meta')`);
        await page.waitFor(`${EXPORT}.open === false`, { label: 'an outside press to close Export' });
        assert.equal(await page.evaluate(`${EXPORT_TRIGGER}.getAttribute('aria-expanded')`), 'false');

        // Compact tables change nothing about the popover (POPOVER-SPEC section 6, item 12).
        const exportLook = `(function(){
            var b = ${popoverBoxes('#flowsExport')};
            var size = function(r){ return [r.right - r.left, r.bottom - r.top]; };
            return {
                open: b.open,
                slotted: ${EXPORT}.shadowRoot.querySelector('slot[name="trigger"]').assignedElements().length,
                trigger: size(b.trigger),
                panel: size(b.panel),
                items: [...document.querySelectorAll('#flowsExportMenu [data-export]')].map(function(i){ return Math.round(i.getBoundingClientRect().height); }),
                inside: b.panel.left >= 0 && b.panel.top >= 0 && b.panel.right <= b.view.width && b.panel.bottom <= b.view.height,
            };
        })()`;
        const density = await page.evaluate('document.documentElement.dataset.density');
        const looks = {};
        await page.evaluate(`${EXPORT_TRIGGER}.scrollIntoView({ block: 'center' })`);
        for (const d of ['comfortable', 'compact']) {
            await page.evaluate(`document.documentElement.dataset.density = '${d}'`);
            await page.evaluate(`${EXPORT_TRIGGER}.click()`);
            await page.waitFor(`${EXPORT}.open === true`, { label: `Export open with ${d} tables` });
            await sleep(200);
            looks[d] = await page.evaluate(exportLook);
            await press(page, 'Escape', 'Escape', 27);
            await page.waitFor(`${EXPORT}.open === false`, { label: `Escape to close Export with ${d} tables` });
        }
        await page.evaluate(`document.documentElement.dataset.density = ${JSON.stringify(density)}`);
        assert.deepEqual(
            [looks.compact.open, looks.compact.slotted, looks.compact.inside],
            [true, 1, true],
            'Export opens with compact tables'
        );
        assert.deepEqual(looks.compact, looks.comfortable, 'compact tables leave the Export trigger, panel and items as they were');
        assert.deepEqual(captureReads(log), [], 'using Export and Columns ran no capture-file query (POPOVER-SPEC section 6, item 6)');

        // Result tabs: arrow keys move and select (automatic activation).
        await page.evaluate(`document.getElementById('flowsTab-flows').focus()`);
        await press(page, 'ArrowRight', 'ArrowRight', 39);
        await page.waitFor(`${activeId} === 'flowsTab-raw' && !document.getElementById('flowsPanel-raw').hidden`, {
            label: 'the Raw output tab',
        });
        await page.waitFor(`!!${rawOutput} && !${rawOutput}.hasAttribute('aria-busy')`, {
            timeout: 15000,
            label: 'the raw output to arrive',
        });
        assert.match(await page.evaluate(`document.getElementById('flowsRawCommand').textContent`), /nfdump -M /, 'the clean command');
        assert.ok((await page.evaluate(`${rawOutput}.textContent.length`)) > 0, 'nfdump stdout');
        assert.equal(await page.evaluate(`document.getElementById('flowsPanel-flows').hidden`), true);

        // Each of the three Copy buttons (nfsen/clipboard) copies twice over plain HTTP; the second copy,
        // once the label reads Copy again, also says Copied and is announced again.
        await page.evaluate(WATCH_COPIES);
        const copyCommand = `document.querySelector('#flowsPanel-raw button[data-copy-source="flowsRawCommand"]')`;
        const command = await page.evaluate(`document.getElementById('flowsRawCommand').textContent`);
        await copyWith(page, copyCommand, command, 'Raw command');
        await copyWith(page, copyCommand, command, 'Raw command again');
        assert.deepEqual(await page.evaluate('window.__said.slice(-2)'), ['Copied.', 'Copied.'], 'each copy is announced');
        const rawId = await page.evaluate(`${rawOutput}.id`);
        const copyOutput = `document.querySelector('#flowsPanel-raw button[data-copy-source="${rawId}"][data-copy-mode="loaded"]')`;
        const output = await page.evaluate(`${rawOutput}.textContent`);
        await copyWith(page, copyOutput, output, 'Raw output');
        await copyWith(page, copyOutput, output, 'Raw output again');
        const noticeCode = `document.querySelector('#flowMessage code[id^="flowsNotice-"]')`;
        const copyNotice = `document.querySelector('#flowMessage button[data-copy-source="' + ${noticeCode}.id + '"]')`;
        const noticeCommand = await page.evaluate(`${noticeCode}.textContent`);
        await copyWith(page, copyNotice, noticeCommand, 'Notice command');
        await copyWith(page, copyNotice, noticeCommand, 'Notice command again');
        await page.evaluate(`document.getElementById('flowsTab-raw').focus()`);

        await press(page, 'ArrowRight', 'ArrowRight', 39);
        await page.waitFor(`${activeId} === 'flowsTab-summary' && !document.getElementById('flowsPanel-summary').hidden`, {
            label: 'the Summary tab',
        });
        const summary = await page.evaluate(`document.getElementById('flowsPanel-summary').innerText`);
        assert.match(summary, /Returned rows/);
        assert.match(summary, /Range totals/);
        assert.ok(
            await page.evaluate(
                `[...document.querySelectorAll('#flowsPanel-summary .flows-protocols th[scope=row]')].some(function(th){ return th.textContent.trim() === 'All protocols'; })`
            ),
            'range totals from the stored series, with the protocol split'
        );
        await page.waitFor(`document.querySelector('[data-estimate="flows-summary"]')?.dataset.state === 'ready'`, {
            timeout: 15000,
            label: 'the filtered totals estimate',
        });
        await page.runQuery('flows-summary', { timeout: 60000 });
        await page.waitFor(
            `/Flows\\s*[\\d,]+/.test(document.getElementById('flowsFiltered').innerText) && !!document.querySelector('#flowsFiltered .flows-protocols')`,
            {
                timeout: 15000,
                label: 'the filtered totals',
            }
        );

        // V-A11Y: the Summary and the results in forced colors.
        await page.withForcedColors(async () => {
            await sleep(300);
            await shot(page, `${SHOTS}/flows-summary-forced-colors.png`, '#flowResults');
            await page.evaluate(`document.getElementById('flowsTab-flows').click()`);
            await sleep(300);
            await shot(page, `${SHOTS}/flows-results-forced-colors.png`, '#flowResults');
            await shot(page, `${SHOTS}/flows-query-forced-colors.png`, '.flows-query');
            // The open Export keeps its panel edge and the focused item's ring (POPOVER-SPEC section 6, item 10).
            await page.evaluate(`${EXPORT_TRIGGER}.scrollIntoView({ block: 'center' }); ${EXPORT_TRIGGER}.focus()`);
            await press(page, 'ArrowDown', 'ArrowDown', 40);
            await page.waitFor(`${EXPORT}.open === true && document.activeElement?.dataset.export === 'csv'`, {
                label: 'Export open in forced colors',
            });
            await sleep(300);
            const edges = await page.evaluate(`(function(){
                var panel = getComputedStyle(${EXPORT}.shadowRoot.querySelector('.panel'));
                var item = getComputedStyle(document.activeElement);
                return { panel: panel.outlineStyle + ' ' + panel.outlineWidth, ring: item.outlineStyle + ' ' + item.outlineWidth };
            })()`);
            assert.equal(edges.panel, 'solid 1px', `the Export panel keeps its edge in forced colors: ${edges.panel}`);
            assert.ok(
                !edges.ring.startsWith('none') && parseFloat(edges.ring.split(' ')[1]) >= 2,
                `the focused item shows a ring: ${edges.ring}`
            );
            await page.screenshot(`${SHOTS}/flows-export-forced-colors.png`);
            await press(page, 'Escape', 'Escape', 27);
            await page.waitFor(`${EXPORT}.open === false`, { label: 'Escape to close Export in forced colors' });
        });
        await page.evaluate(`document.getElementById('flowsTab-flows').click()`);

        // D26: a second run with other rows replaces the list; a later sync does not resend it.
        const firstHost = await page.evaluate(`${HOST}.id`);
        await page.evaluate('window.__e2eTab = true');
        await page.setSelectValue('#filterFlowsLimit select', 10000);
        await page.runQuery('flows', { timeout: 120000 });
        await page.waitFor(`${HOST}?.id !== ${JSON.stringify(firstHost)} && ${listTotal} > ${total}`, {
            timeout: 60000,
            label: 'the list of 10,000 rows',
        });
        const bigRun = await page.evaluate(`({ id: ${HOST}.id, rows: ${listTotal}, dom: ${listRows} })`);
        assert.notEqual(bigRun.id, firstHost, 'a new result gets a new list');
        assert.ok(bigRun.dom < 1000, `only a window of the rows is in the document (${bigRun.dom})`);
        console.log(`  (flows: ${total} rows, then ${bigRun.rows})`);
        const bigCsv = await captureExport(page, 'csv');
        assert.equal(bigCsv.text.trimEnd().split('\n').length, bigRun.rows + 1, 'the CSV from the server holds every row');

        await page.evaluate(`${HOST}.__e2e = true`);
        await page.evaluate(`document.querySelector('#flowsGraph .flows-disclosure').click()`);
        await sleep(300);
        sse.reset();
        log.clear();
        await page.evaluate(`document.getElementById('flowsGraphUnit_packets').click()`);
        await page.waitFor(`document.getElementById('flowsGraphUnit_packets').checked`, { label: 'the unit change' });
        await sleep(2500);
        assert.ok(log.count('touch-flows-graph') >= 1, 'the unit change posted and synced');
        assert.ok(sse.bytes() < 1_000_000, `the sync after a 10,000 row run is small (${sse.bytes()} bytes)`);
        assert.equal(await page.evaluate(`${HOST}.__e2e === true`), true, 'the list was not replaced');
        await page.evaluate(`document.getElementById('flowsGraphUnit_bytes').click()`);
        await page.evaluate(`document.querySelector('#flowsGraph .flows-disclosure').click()`);

        // An address opens the IP info modal through the list's one click handler.
        await page.evaluate(`${HOST}.querySelector('.ip-link').click()`);
        await page.waitFor(`!!document.querySelector('#modal-root dialog[open]')`, { timeout: 15000, label: 'the IP info modal' });
        await press(page, 'Escape', 'Escape', 27);

        // The Raw output of the 10,000 row result comes in pieces; the worker survives it.
        await page.evaluate(`document.getElementById('flowsTab-raw').click()`);
        await page.waitFor(`!!${rawOutput} && !${rawOutput}.hasAttribute('aria-busy')`, { timeout: 60000, label: 'the large raw output' });
        const raw = JSON.parse(
            await page.evaluate(
                `JSON.stringify({ length: ${rawOutput}.textContent.length, pieces: ${rawOutput}.children.length, note: document.getElementById('flowsPanel-raw').innerText })`
            )
        );
        assert.ok(
            raw.length > 4_000_000 && raw.pieces > 1,
            `the output arrived in pieces (${raw.length} characters, ${raw.pieces} pieces)`
        );
        assert.match(raw.note, /The page keeps the first 5(\.0)? MiB of nfdump/, 'the truncation note');
        await page.evaluate(`document.getElementById('flowsTab-flows').click()`);

        // Two more tabs run 10,000 rows each while this one keeps its result: no worker crash
        // (a crash reloads every tab), and every tab still has all of its rows.
        await withPage(async (second) => {
            await runLargeTab(second, 'second tab');
            await withPage(async (third) => {
                await runLargeTab(third, 'third tab');
                await third.evaluate(`document.getElementById('flowsTab-raw').click()`);
                await third.waitFor(`!!${rawOutput} && !${rawOutput}.hasAttribute('aria-busy')`, {
                    timeout: 60000,
                    label: 'third tab: raw output',
                });
                assert.equal(await third.evaluate('window.__e2eTab === true'), true, 'the third tab was not reloaded');
            });
            assert.equal(await second.evaluate('window.__e2eTab === true'), true, 'the second tab was not reloaded');
            assert.equal(await second.evaluate(listTotal), 10000);
        });
        assert.equal(await page.evaluate('window.__e2eTab === true'), true, 'the first tab was not reloaded');
        assert.equal(await page.evaluate(listTotal), bigRun.rows, 'the first tab keeps its rows');
        await page.evaluate(`${HOST}.scrollToIndex(9000)`);
        await page.waitFor(`${HOST}.querySelector('[aria-rowindex="9002"]')`, {
            timeout: 15000,
            label: 'the first tab still answers windows',
        });

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Flows test, got:\n${errors.join('\n')}`);
    });
    await exportRightAway();
    await popoversOnPhone();
}

if (import.meta.url === `file://${process.argv[1]}`) {
    flowsTest()
        .then(() => console.log('flows: PASS'))
        .catch((e) => {
            console.error('flows: FAIL\n', e);
            process.exit(1);
        });
}
