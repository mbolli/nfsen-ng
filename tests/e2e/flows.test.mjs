// Flows (4.3, 5.4): run, pages, tabs, exports, result hosts (D26), 10,000 rows in three tabs
// without a worker crash, keyboard walk and forced-colors screenshots to E2E_SHOTS (V-A11Y).
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { BASE, withPage } from './lib/cdp.mjs';

const SHOTS = process.env.E2E_SHOTS || '/tmp';
const TABLE = '#flowTable';
const STATUS = `${TABLE} .table-pager-status`;

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** A key press; Enter carries its text, which is what activates a focused button. */
async function press(page, key, code = key, keyCode = 0) {
    const text = key === 'Enter' ? '\r' : undefined;
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

/** The file an export hands to the browser, caught before it is saved. */
async function captureExport(page, item) {
    await page.evaluate(`(function(){
        window.__exported = null;
        window.__createObjectURL ??= URL.createObjectURL;
        URL.createObjectURL = function (blob) { window.__exported = blob; return 'blob:e2e'; };
        window.__anchorClick ??= HTMLAnchorElement.prototype.click;
        HTMLAnchorElement.prototype.click = function () { window.__download = this.download; };
    })()`);
    await page.evaluate(
        `[...document.querySelectorAll('#flowsExportMenu [role=menuitem]')].find(function(b){ return b.textContent.trim() === ${JSON.stringify(item)}; }).click()`
    );
    await page.waitFor('!!window.__exported', { label: `${item} export` });
    const result = await page.evaluate(
        `window.__exported.text().then(function(text){ return JSON.stringify({ text: text, name: window.__download }); })`
    );
    await page.evaluate(`URL.createObjectURL = window.__createObjectURL; HTMLAnchorElement.prototype.click = window.__anchorClick;`);
    return JSON.parse(result);
}

/** A real press and release on the element: over plain HTTP only user activation lets execCommand copy. */
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

const shownRows = `document.querySelectorAll('${TABLE} tbody tr').length`;
const activeId = 'document.activeElement?.id';
const loaded = (rows) =>
    `(function(){ var t = document.getElementById('flowTable'); return !!t && !!t.rows && t.rows.length ${rows} && !t.loading && !(t.pull && t.pull.failed); })()`;
const rawOutput = `document.querySelector('[id^="flowsRawOutput-"]')`;

/** A tab of its own that runs 10,000 rows and waits for every chunk; a marker shows it was never reloaded. */
async function runLargeTab(page, label) {
    await page.navigate(`${BASE}/`);
    await page.waitForBoot();
    await page.gotoPage('flows');
    await page.setRangePreset('1y');
    await page.setSelectValue('#filterFlowsLimit select', 10000);
    await page.evaluate('window.__e2eTab = true');
    await page.runQuery('flows', { timeout: 120000 });
    await page.waitFor(loaded('=== 10000'), { timeout: 60000, label: `${label}: every row` });
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
        await page.waitFor(loaded('> 0'), { timeout: 15000, label: 'the flow rows' });
        await page.waitFor(`/^[\\d,]+ (flows|rows) returned\\./.test(document.querySelector('#flowsRun [role="status"]').textContent)`, {
            label: 'the Run control to announce the count',
        });
        const notice = await page.evaluate(`document.getElementById('flowMessage').textContent`);
        assert.match(notice, /nfdump:/, `the notice quotes the command: ${notice}`);
        assert.doesNotMatch(notice, /error/i, `no error: ${notice}`);

        // One page of rows in the document; the pager says how many came back and why no more.
        await page.waitFor(`${shownRows} > 0`, { timeout: 15000, label: 'the flow table' });
        const total = await page.evaluate(`document.getElementById('flowTable').rows.length`);
        assert.ok(total > 50, `a year of dev captures returns more than a page (${total} rows)`);
        assert.equal(await page.evaluate(shownRows), 50, 'the first page shows 50 rows');
        const status = await page.evaluate(`document.querySelector('${STATUS}').textContent`);
        assert.match(status, /^Showing 1-50 of [\d,]+ returned \(limit 100\)\./, `pager text: ${status}`);
        assert.equal(/nfdump cannot skip rows/.test(status), total === 100, `the limit sentence only when it was reached: ${status}`);
        assert.equal(await page.evaluate(`document.getElementById('flowsTab-flows').textContent.trim()`), `Flows (${total})`);

        // Pages by keyboard: Next moves on and keeps the focus in the pager.
        await page.evaluate(`document.querySelector('${TABLE} [data-page="next"]').focus()`);
        await press(page, 'Enter', 'Enter', 13);
        await page.waitFor(`document.querySelector('${STATUS}').textContent.startsWith('Showing 51-')`, { label: 'page 2' });
        assert.ok(
            await page.evaluate(`document.querySelector('${TABLE} .table-pager').contains(document.activeElement)`),
            'focus stays in the pager'
        );
        assert.equal(await page.evaluate(`document.querySelector('${TABLE} [aria-current="page"]').textContent`), '2');
        await page.setSelectValue(`${TABLE} .table-pager select`, 25);
        await page.waitFor(`${shownRows} === 25`, { label: '25 rows per page' });
        assert.match(
            await page.evaluate(`document.querySelector('${STATUS}').textContent`),
            /^Showing 51-75 /,
            'the page keeps its first row'
        );
        await page.setSelectValue(`${TABLE} .table-pager select`, 50);

        // Sorting covers every row, not just the page.
        await page.evaluate(`document.querySelector('${TABLE} th[data-original-title="in_bytes"] .sort-button').click()`);
        const sorted = JSON.parse(
            await page.evaluate(`JSON.stringify((function(){
                var t = document.getElementById('flowTable'); var i = t.keys.indexOf('in_bytes');
                var all = t.rows.map(function(r){ var c = r.cells[i]; return Number(c.dataset.sortValue ?? c.dataset.raw ?? c.textContent); });
                return { aria: t.headers[i].getAttribute('aria-sort'), all: all, first: document.querySelector('${STATUS}').textContent };
            })())`)
        );
        assert.equal(sorted.aria, 'ascending');
        assert.deepEqual(
            sorted.all,
            [...sorted.all].sort((a, b) => a - b),
            'every row is in order'
        );
        assert.match(sorted.first, /^Showing 1-50 /, 'a sort goes back to the first page');

        // Exports hold the shown columns and every row.
        await page.evaluate(`document.querySelector('${TABLE} .column-selector .menu-toggle').click()`);
        await page.evaluate(`document.querySelector('${TABLE} .column-checkbox[data-column-key="received"]').click()`);
        await page.evaluate(`document.querySelector('${TABLE} .column-selector .menu-toggle').click()`);
        const titles = await page.evaluate(
            `[...document.querySelectorAll('${TABLE} thead th:not([hidden])')].map(function(th){ return th.textContent.trim(); })`
        );
        assert.ok(!titles.includes('Received'), 'the hidden column is gone from the header');
        await page.evaluate(`document.querySelector('.flows-export .menu-toggle').click()`);
        const csv = await captureExport(page, 'CSV');
        const lines = csv.text.trimEnd().split('\n');
        assert.deepEqual(lines[0].split(','), titles, 'the CSV header is the shown columns');
        assert.equal(lines.length, total + 1, 'the CSV holds every row');
        assert.match(csv.name, /^flows-\d{12}-\d{12}\.csv$/, `export file name: ${csv.name}`);
        await page.evaluate(`document.querySelector('.flows-export .menu-toggle').click()`);
        const json = JSON.parse((await captureExport(page, 'JSON')).text);
        assert.equal(json.length, total);
        assert.deepEqual(Object.keys(json[0]), titles, 'the JSON keys are the shown columns');
        await page.evaluate(`document.querySelector('${TABLE} .column-selector .menu-toggle').click()`);
        await page.evaluate(`document.querySelector('${TABLE} .column-checkbox[data-column-key="received"]').click()`);
        await page.evaluate(`document.querySelector('${TABLE} .column-selector .menu-toggle').click()`);

        // The Export menu by keyboard: ArrowDown opens it on the first item, Escape returns.
        await page.evaluate(`document.querySelector('.flows-export .menu-toggle').focus()`);
        await press(page, 'ArrowDown', 'ArrowDown', 40);
        await page.waitFor(`document.activeElement?.getAttribute('role') === 'menuitem'`, { label: 'the first Export item' });
        await press(page, 'Escape', 'Escape', 27);
        await page.waitFor(
            `document.activeElement?.classList.contains('menu-toggle') && !document.getElementById('flowsExportMenu').hasAttribute('data-open')`,
            {
                label: 'Escape to close Export',
            }
        );

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
        });
        await page.evaluate(`document.getElementById('flowsTab-flows').click()`);

        // D26: a second run with other rows replaces the host; a later sync does not resend it.
        const firstHost = await page.evaluate(`document.querySelector('.result-host[id^="flowTableHost-"]').id`);
        await page.evaluate('window.__e2eTab = true');
        await page.setSelectValue('#filterFlowsLimit select', 10000);
        await page.runQuery('flows', { timeout: 120000 });
        await page.waitFor(loaded(`> ${total}`), { timeout: 60000, label: 'the 10,000 row table, every chunk' });
        const big = await page.evaluate(
            `JSON.stringify({ id: document.querySelector('.result-host[id^="flowTableHost-"]').id, rows: document.getElementById('flowTable').rows.length, dom: ${shownRows} })`
        );
        const bigRun = JSON.parse(big);
        assert.notEqual(bigRun.id, firstHost, 'a new result gets a new host');
        assert.equal(bigRun.dom, 50, 'only one page is in the document');
        console.log(`  (flows: ${total} rows, then ${bigRun.rows})`);
        await page.evaluate(`document.querySelector('.flows-export .menu-toggle').click()`);
        const bigCsv = await captureExport(page, 'CSV');
        assert.equal(bigCsv.text.trimEnd().split('\n').length, bigRun.rows + 1, 'the CSV of the chunked table holds every row');

        await page.evaluate(`document.querySelector('.result-host[id^="flowTableHost-"]').__e2e = true`);
        await page.evaluate(`document.querySelector('#flowsGraph .flows-disclosure').click()`);
        await sleep(300);
        sse.reset();
        log.clear();
        await page.evaluate(`document.getElementById('flowsGraphUnit_packets').click()`);
        await page.waitFor(`document.getElementById('flowsGraphUnit_packets').checked`, { label: 'the unit change' });
        await sleep(2500);
        assert.ok(log.count('touch-flows-graph') >= 1, 'the unit change posted and synced');
        assert.ok(sse.bytes() < 1_000_000, `the sync after a 10,000 row run is small (${sse.bytes()} bytes)`);
        assert.equal(
            await page.evaluate(`document.querySelector('.result-host[id^="flowTableHost-"]').__e2e === true`),
            true,
            'the table host was not replaced'
        );
        await page.evaluate(`document.getElementById('flowsGraphUnit_bytes').click()`);
        await page.evaluate(`document.querySelector('#flowsGraph .flows-disclosure').click()`);

        // An address opens the IP info modal through the table's one click handler.
        await page.evaluate(`document.querySelector('${TABLE} .ip-link').click()`);
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
            assert.equal(await second.evaluate(`document.getElementById('flowTable').rows.length`), 10000);
        });
        assert.equal(await page.evaluate('window.__e2eTab === true'), true, 'the first tab was not reloaded');
        assert.equal(await page.evaluate(`document.getElementById('flowTable').rows.length`), bigRun.rows, 'the first tab keeps its rows');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Flows test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    flowsTest()
        .then(() => console.log('flows: PASS'))
        .catch((e) => {
            console.error('flows: FAIL\n', e);
            process.exit(1);
        });
}
