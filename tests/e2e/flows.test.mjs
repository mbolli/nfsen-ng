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

const shownRows = `document.querySelectorAll('${TABLE} tbody tr').length`;
const activeId = 'document.activeElement?.id';
const loaded = (rows) =>
    `(function(){ var t = document.getElementById('flowTable'); return !!t && !!t.rows && t.rows.length ${rows} && !t.loading && !(t.pull && t.pull.failed); })()`;
const rawOutput = `document.querySelector('[id^="flowsRawOutput-"]')`;

// Runs before any page script: each result's rows as the SSE stream sent them, keyed by what nfsen-table never
// rewrites, and what a data-effect applied on first load saw of the chunk helpers.
const BEFORE_BOOT = `(function(){
    var rowKey = window.__e2eRowKey = function(tr){
        return JSON.stringify([...tr.cells].map(function(c){
            var time = c.querySelector('time[data-epoch]');
            return c.dataset.sortValue ?? (time ? time.dataset.epoch : undefined) ?? c.dataset.raw ?? c.textContent.trim();
        }));
    };
    var wire = window.__e2eWire = {};
    var entry = function(id){ return wire[id] || (wire[id] = { first: null, chunks: {} }); };
    var take = function(selector, html){
        if (html.indexOf('table-rows') < 0) return;
        var box = document.createElement('template');
        box.innerHTML = html;
        box.content.querySelectorAll('nfsen-table[data-result]').forEach(function(table){
            var e = entry(table.getAttribute('data-result'));
            e.first = [...(table.querySelector('tbody')?.rows ?? [])].map(rowKey);
            table.querySelectorAll(':scope > template.table-rows').forEach(function(t){
                var rows = [...t.content.children].map(rowKey);
                if (t.dataset.chunk === undefined) e.first = e.first.concat(rows);
                else e.chunks[t.dataset.chunk] ??= rows;
            });
        });
        var m = /^#flowTableHost-(\\S+) > nfsen-table$/.exec(selector || '');
        if (m) [...box.content.children].filter(function(t){ return t.matches('template.table-rows[data-chunk]'); }).forEach(function(t){
            entry(m[1]).chunks[t.dataset.chunk] ??= [...t.content.children].map(rowKey);
        });
    };
    var event = function(block){
        var selector = null, html = [];
        block.split('\\n').forEach(function(line){
            if (line.startsWith('data: selector ')) selector = line.slice(15);
            else if (line.startsWith('data: elements ')) html.push(line.slice(15));
        });
        if (html.length) take(selector, html.join('\\n'));
    };
    var read = function(stream){
        var reader = stream.pipeThrough(new TextDecoderStream()).getReader();
        var buffer = '';
        var pump = function(){
            return reader.read().then(function(r){
                if (r.done) return;
                buffer += r.value.replace(/\\r/g, '');
                for (var at = buffer.indexOf('\\n\\n'); at >= 0; at = buffer.indexOf('\\n\\n')) {
                    event(buffer.slice(0, at));
                    buffer = buffer.slice(at + 2);
                }
                return pump();
            });
        };
        pump().catch(function(){});
    };
    // Every patch, the chunks included, comes over the /_sse stream; the actions answer with an empty body.
    var fetch = window.fetch;
    window.fetch = function(){
        return fetch.apply(this, arguments).then(function(res){
            if (!res.body || (res.headers.get('content-type') || '').indexOf('text/event-stream') < 0) return res;
            var pair = res.body.tee();
            read(pair[1]);
            return new Response(pair[0], { status: res.status, statusText: res.statusText, headers: res.headers });
        });
    };
    new MutationObserver(function(records, observer){
        var root = document.getElementById('client-root');
        if (!root) return;
        observer.disconnect();
        var probe = document.createElement('i');
        probe.hidden = true;
        probe.setAttribute('data-effect', "window.__e2eFirstEffect = { pull: typeof window.nfsenPullChunks, loaded: typeof window.nfsenWhenLoaded, tableModule: performance.getEntriesByType('resource').some((e) => e.name.includes('/nfsen-table.js')) }");
        root.append(probe);
    }).observe(document, { childList: true, subtree: true });
})()`;

/** Sets the Flows limit signal; the select only offers some limits, the action takes any up to its maximum. */
async function setLimit(page, rows) {
    await page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        root[Object.keys(root).find(function(k){ return k.startsWith('flows_limit____'); })] = ${rows};
    })()`);
}

/** The rows of result `id` in the order the server sent them, once every chunk is in. */
const wireRows = (id) => `(function(){
    var e = window.__e2eWire[${JSON.stringify(id)}];
    return e.first.concat(...Object.keys(e.chunks).sort(function(x, y){ return x - y; }).map(function(k){ return e.chunks[k]; }));
})()`;
const wireComplete = (id) =>
    `(function(){ var e = window.__e2eWire[${JSON.stringify(id)}]; return !!e && !!e.first && Object.keys(e.chunks).length === Number(document.getElementById('flowTable').dataset.chunks); })()`;

/** The table holds exactly the rows the server sent, in its order (no sort is stored in a fresh profile). */
async function assertRowsAsSent(page, id, label) {
    await page.waitFor(wireComplete(id), { timeout: 15000, label: `${label}: every chunk seen on the wire` });
    const check = await page.evaluate(`(function(){
        var expected = ${wireRows(id)};
        var got = document.getElementById('flowTable').rows.map(window.__e2eRowKey);
        return { got: got.length, expected: expected.length, firstDiff: got.findIndex(function(k, i){ return k !== expected[i]; }) };
    })()`);
    assert.equal(check.got, check.expected, `${label}: as many rows as the server sent`);
    assert.equal(check.firstDiff, -1, `${label}: every row is the one the server sent at that place`);
}

/** Pages to the last page and compares it with the tail of what the server sent. */
async function assertLastPage(page, id, total, label) {
    await page.evaluate(`[...document.querySelectorAll('${TABLE} .table-pager-pages button[data-page]')].pop().click()`);
    await page.waitFor(`document.querySelector('${TABLE} [data-page="next"]').disabled`, { label: `${label}: the last page` });
    const last = await page.evaluate(`(function(){
        var expected = ${wireRows(id)};
        var size = Number(document.querySelector('${TABLE} .table-pager select').value);
        var shown = [...document.querySelectorAll('${TABLE} tbody tr')].map(window.__e2eRowKey);
        return { size: size, shown: shown, tail: expected.slice(expected.length - shown.length), status: document.querySelector('${STATUS}').textContent };
    })()`);
    const count = total % last.size || last.size;
    assert.equal(last.shown.length, count, `${label}: the last page holds the remainder`);
    assert.deepEqual(last.shown, last.tail, `${label}: the last page shows the last rows the server sent`);
    const from = (total - count + 1).toLocaleString('en');
    assert.ok(
        last.status.startsWith(`Showing ${from}-${total.toLocaleString('en')} of ${total.toLocaleString('en')}`),
        `${label}: ${last.status}`
    );
}

/** Pager state a move must keep: the rows array, its first row, the page, the sort and the status. */
const PAGER_STATE = `(function(){
    var t = document.getElementById('flowTable');
    var sorted = t.headers.find(function(th){ return th.hasAttribute('aria-sort'); });
    return {
        rows: t.rows === window.__kept.rows, count: t.rows.length, first: t.rows[0] === window.__kept.first,
        page: document.querySelector('${TABLE} [aria-current="page"]').textContent, status: document.querySelector('${STATUS}').textContent,
        sort: sorted ? sorted.dataset.originalTitle + ' ' + sorted.getAttribute('aria-sort') : null,
        instance: t.rocketInstanceId === window.__kept.instance, focus: document.activeElement === window.__kept.focus,
    };
})()`;

// ROCKET-SPEC 6.8 reuse and morph order: result A (2,500 rows), then result B (5,000 rows) in the same #flowTable,
// which Datastar moves into the new result host and morphs there.
async function reuseCases(page, variant) {
    // Result A: 2,500 rows, the first page and chunk 0 inline, chunks 1 and 2 pulled.
    await setLimit(page, 2500);
    await page.runQuery('flows', { timeout: 120000 });
    await page.waitFor(loaded('=== 2500'), { timeout: 60000, label: `${variant}: result A, every row` });
    const host = await page.evaluate(
        `(function(){ var t = document.getElementById('flowTable'); return { id: t.rocketInstanceId, shadow: [...t.shadowRoot.childNodes].map(function(n){ return n.nodeName; }).join(), result: t.dataset.result, chunks: t.dataset.chunks }; })()`
    );
    assert.ok(typeof host.id === 'string' && host.id !== '', `${variant}: #flowTable is a Rocket host, got ${host.id}`);
    assert.equal(host.shadow, 'SLOT', `${variant}: its shadow root only slots the light DOM (shape A)`);
    assert.equal(host.chunks, '3', `${variant}: result A comes in three chunks`);
    await assertRowsAsSent(page, host.result, `${variant}: result A`);
    await assertLastPage(page, host.result, 2500, `${variant}: result A`);

    // 25 rows a page, so the morph for result B turns 25 shown rows into its 50-row first page.
    await page.setSelectValue(`${TABLE} .table-pager select`, 25);
    await page.waitFor(`${shownRows} === 25`, { label: `${variant}: result A at 25 rows a page` });
    const marked = await page.evaluate(`(function(){
        var t = document.getElementById('flowTable');
        window.__hostA = t;
        window.__batches = [];
        new MutationObserver(function(records){
            window.__batches.push({
                result: records.some(function(r){ return r.type === 'attributes' && r.attributeName === 'data-result'; }),
                table: records.some(function(r){ return r.type === 'childList' && r.target.nodeType === 1 && !!r.target.closest('table'); }),
            });
        }).observe(t, { attributes: true, attributeFilter: ['data-result'], childList: true, subtree: true });
        var off = t.rows.filter(function(r){ return !r.isConnected; });
        off.forEach(function(r){ r.__resultA = true; });
        return off.length;
    })()`);
    assert.equal(marked, 2475, `${variant}: every row of result A but the shown page is only in memory`);

    // Result B, 5,000 rows: Datastar moves #flowTable into the new result host and morphs it there.
    await setLimit(page, 5000);
    await page.runQuery('flows', { timeout: 120000 });
    await page.waitFor(loaded('=== 5000'), { timeout: 60000, label: `${variant}: result B, every row` });
    const b = await page.evaluate(`(function(){
        var t = document.getElementById('flowTable');
        return {
            reused: t === window.__hostA, result: t.dataset.result, chunks: t.dataset.chunks,
            together: window.__batches.some(function(b){ return b.result && b.table; }),
            leftovers: t.rows.filter(function(r){ return r.__resultA; }).length + [...t.querySelectorAll('tbody tr')].filter(function(r){ return !t.rows.includes(r); }).length,
            size: document.querySelector('${TABLE} .table-pager select').value,
        };
    })()`);
    assert.equal(b.reused, true, `${variant}: result B reuses the #flowTable host`);
    assert.notEqual(b.result, host.result, `${variant}: with a new data-result`);
    assert.equal(b.together, true, `${variant}: one patch changed data-result and the <table> together`);
    assert.equal(b.leftovers, 0, `${variant}: no row of result A is left, in memory or on the page`);
    assert.equal(b.size, '25', `${variant}: the rows per page choice carries over`);
    await assertRowsAsSent(page, b.result, `${variant}: result B`);
    await assertLastPage(page, b.result, 5000, `${variant}: result B`);
}

/** Datastar's morph falls back to insertBefore without moveBefore, so a reused host runs cleanup and setup mid-morph. */
const NO_MOVE = 'delete Element.prototype.moveBefore; delete Document.prototype.moveBefore; delete DocumentFragment.prototype.moveBefore;';

/** ROCKET-SPEC 6.8, in a page with moveBefore and in one without. */
async function tableCases() {
    await withPage(async (page) => {
        // The table's module is held back until Datastar has applied the page, as on a slow link.
        await page.send('Page.addScriptToEvaluateOnNewDocument', { source: BEFORE_BOOT });
        await page.send('Fetch.enable', { patterns: [{ urlPattern: '*/js/components/nfsen-table.js*', requestStage: 'Request' }] });
        page.ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            if (msg.method !== 'Fetch.requestPaused') return;
            const release = () => page.send('Fetch.continueRequest', { requestId: msg.params.requestId }).catch(() => {});
            page.waitFor('!!window.__e2eFirstEffect', { timeout: 15000 }).then(release, release);
        });
        await page.navigate(`${BASE}/`);
        await page.waitForBoot({ timeout: 15000 });
        await page.waitFor('!!window.__e2eFirstEffect', { label: 'the first-load data-effect' });
        assert.deepEqual(
            await page.evaluate('window.__e2eFirstEffect'),
            { pull: 'function', loaded: 'function', tableModule: false },
            'a data-effect applied on first load, before the table module arrived, finds the chunk helpers'
        );
        await page.send('Fetch.disable');

        const instances = await page.evaluate(`(async function(){
            var map = JSON.parse(document.querySelector('script[type=importmap]').textContent).imports;
            var urls = [...new Set(performance.getEntriesByType('resource').map(function(e){ return e.name; }).filter(function(n){ return /\\/js\\/components\\/chunks\\.js(\\?|$)/.test(n); }))];
            var chunks = await import('nfsen/chunks');
            var table = await import(document.querySelector('script[src*="/nfsen-table.js"]').src);
            return {
                urls: urls, mapped: new URL(map['nfsen/chunks'], location.href).href,
                same: chunks.pullChunks === window.nfsenPullChunks && chunks.whenLoaded === window.nfsenWhenLoaded
                    && table.pullChunks === chunks.pullChunks && table.ChunkPull === chunks.ChunkPull && table.whenLoaded === chunks.whenLoaded,
            };
        })()`);
        assert.deepEqual(instances.urls, [instances.mapped], 'chunks.js loads once, from the import-map URL');
        assert.equal(instances.same, true, 'the globals, nfsen/chunks and the table re-exports are one module instance');

        await page.gotoPage('flows');
        await page.setRangePreset('1y');

        await reuseCases(page, 'moveBefore');

        // Move: sorted, on page 3, a pager button focused; moveBefore is atomic, insertBefore runs cleanup and setup again.
        await page.evaluate(`document.querySelector('${TABLE} th[data-original-title="in_bytes"] .sort-button').click()`);
        await page.waitFor(
            `document.querySelector('${TABLE} th[data-original-title="in_bytes"]').getAttribute('aria-sort') === 'ascending'`,
            {
                label: 'sorted by in_bytes',
            }
        );
        await page.evaluate(`document.querySelector('${TABLE} .table-pager-pages button[data-page="2"]').click()`);
        await page.waitFor(`document.querySelector('${STATUS}').textContent.startsWith('Showing 51-75 ')`, { label: 'page 3' });
        await page.evaluate(`(function(){
            var t = document.getElementById('flowTable');
            document.querySelector('${TABLE} [aria-current="page"]').focus();
            window.__kept = { rows: t.rows, first: t.rows[0], instance: t.rocketInstanceId, focus: document.activeElement };
        })()`);
        const before = await page.evaluate(PAGER_STATE);
        assert.deepEqual(
            [before.count, before.page, before.sort, before.focus],
            [5000, '3', 'in_bytes ascending', true],
            'before the move: page 3 of the sorted rows, its button focused'
        );
        for (const how of ['moveBefore', 'insertBefore']) {
            await page.evaluate(`(function(){
                var t = document.getElementById('flowTable');
                if (typeof t.parentNode.${how} !== 'function') throw new Error('no ${how} in this browser');
                t.parentNode.${how}(t, t.nextSibling);
            })()`);
            await sleep(200);
            const after = await page.evaluate(PAGER_STATE);
            // A removal takes the focus away; only the atomic move keeps it.
            assert.deepEqual(after, { ...before, focus: how === 'moveBefore' }, `after ${how}: rows, page, sort and focus`);
        }
        await page.evaluate(`document.querySelector('${TABLE} [data-page="next"]').click()`);
        await page.waitFor(`document.querySelector('${STATUS}').textContent.startsWith('Showing 76-100 ')`, {
            label: 'Next after the moves goes one page on (one listener)',
        });

        // The host's own data-on survived the moves: an address still opens the IP info modal.
        await page.evaluate(`document.querySelector('${TABLE} .ip-link').click()`);
        await page.waitFor(`!!document.querySelector('#modal-root dialog[open]')`, {
            timeout: 15000,
            label: 'the IP info modal after the moves',
        });
        await press(page, 'Escape', 'Escape', 27);

        // 100 and 250 rows a page: the shown rows leave in one record (one each made Rocket's observer rescan the body
        // per row), and the page comes in 50 rows a frame.
        const settled = `!document.querySelector('${TABLE} .table-wrap').style.minBlockSize`;
        for (const size of [100, 250]) {
            await page.evaluate(`(function(){
                window.__fill?.stop();
                var fill = window.__fill = { records: [], frames: [] };
                var rows = new MutationObserver(function(r){ fill.records.push(...r); });
                var frames = new PerformanceObserver(function(l){ l.getEntries().forEach(function(e){ fill.frames.push(Math.round(e.duration)); }); });
                rows.observe(document.querySelector('${TABLE} tbody'), { childList: true });
                frames.observe({ type: 'long-animation-frame' });
                fill.stop = function(){ rows.disconnect(); frames.disconnect(); };
            })()`);
            await page.setSelectValue(`${TABLE} .table-pager select`, size);
            await page.waitFor(`${shownRows} === ${size} && ${settled}`, { label: `${size} rows a page` });
            await sleep(300);
            const fill = await page.evaluate(`(function(){
                var t = document.getElementById('flowTable');
                var records = window.__fill.records;
                return {
                    removals: records.filter(function(r){ return r.removedNodes.length > 0; }).length,
                    batch: Math.max(...records.map(function(r){ return r.addedNodes.length; })),
                    inOrder: [...t.querySelector('tbody').rows].every(function(r, i){ return r === t.rows[i]; }),
                    status: document.querySelector('${STATUS}').textContent,
                    frames: window.__fill.frames,
                };
            })()`);
            console.log(`  (flows: ${size} rows a page, frames over 50 ms ${JSON.stringify(fill.frames)})`);
            assert.deepEqual(
                [fill.removals, fill.batch, fill.inOrder],
                [1, 50, true],
                `${size} rows a page: one removal record, at most 50 rows a frame, the first ${size} rows in order`
            );
            assert.ok(fill.status.startsWith(`Showing 1-${size} of 5,000 `), `${size} rows a page: ${fill.status}`);
        }
        await page.evaluate('window.__fill.stop()');

        // The table keeps its height while a page comes in, so a pressed pager button stays in view.
        const pressed = [
            ['the last page button', '[data-page="19"]', '19'],
            ['page 19', '[data-page="18"]', '18'],
            ['Next onto the last page, which moves the focus off the disabled Next', '[data-page="next"]', '19'],
        ];
        for (const [label, button, to] of pressed) {
            const { y } = await clickAt(page, `document.querySelector('${TABLE} .table-pager ${button}')`);
            await page.waitFor(`document.querySelector('${TABLE} [aria-current="page"]').dataset.page === '${to}' && ${settled}`, {
                label,
            });
            await sleep(300);
            const at = await page.evaluate(`(function(){
                var r = document.activeElement.getBoundingClientRect();
                return { page: document.activeElement.dataset.page, y: Math.round(r.y + r.height / 2), inView: r.top >= 0 && r.bottom <= innerHeight };
            })()`);
            assert.deepEqual(
                [at.page, at.inView],
                [to, true],
                `${label}: the focused page button is in view (pressed at ${Math.round(y)}, now ${at.y})`
            );
        }

        // Another page and back: the table is all there again.
        await page.gotoPage('overview');
        await page.gotoPage('flows');
        await page.waitFor(loaded('=== 5000'), { timeout: 60000, label: 'result B after leaving Flows and coming back' });

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors in the table cases, got:\n${errors.join('\n')}`);
    });

    await withPage(async (page) => {
        await page.send('Page.addScriptToEvaluateOnNewDocument', { source: NO_MOVE + BEFORE_BOOT });
        await page.navigate(`${BASE}/`);
        await page.waitForBoot({ timeout: 15000 });
        assert.equal(await page.evaluate(`'moveBefore' in document.body`), false, 'this page has no moveBefore');
        await page.gotoPage('flows');
        await page.setRangePreset('1y');
        await reuseCases(page, 'insertBefore');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors in the table cases without moveBefore, got:\n${errors.join('\n')}`);
    });
}

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
    await tableCases();
}

if (import.meta.url === `file://${process.argv[1]}`) {
    flowsTest()
        .then(() => console.log('flows: PASS'))
        .catch((e) => {
            console.error('flows: FAIL\n', e);
            process.exit(1);
        });
}
