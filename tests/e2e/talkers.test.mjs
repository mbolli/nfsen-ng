// Top Talkers (4.2): picker, direction, per-statistic results, the Export popover, side panels, brush, keyboard walk and forced colors.
// NFDUMP_HAS_NEL=1 is for an nfdump that computes the NEL statistics; the dev image's 1.7.10 does not.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const visible = (selector) =>
    `(function(){ var e = document.querySelector(${JSON.stringify(selector)}); return !!e && e.getClientRects().length > 0; })()`;
const CHECKED = `[...document.querySelectorAll('input[name="statFamily"]')].filter((i) => i.checked).map((i) => i.value)`;
const FOR_SELECT = `document.getElementById('statsFilterForSelection').value`;
const QUERIES = ['stats-actions', 'talkers-panel', 'flow-actions', 'conversations-run', 'run-filtered-graph', 'overview-topn-run'];

async function key(page, name, code = name, keyCode = 0, { shift = false } = {}) {
    const base = { key: name, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode, modifiers: shift ? 8 : 0 };
    await page.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...base });
    if (name === 'Enter' || name === ' ')
        await page.send('Input.dispatchKeyEvent', { type: 'char', text: name === ' ' ? ' ' : '\r', key: name });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
    await sleep(150);
}

const tab = (page, opts) => key(page, 'Tab', 'Tab', 9, opts);
const FOCUSED = `(() => { const a = document.activeElement; return a?.id || a?.getAttribute('aria-label') || a?.textContent.trim().slice(0, 40) || a?.tagName || ''; })()`;
const EXPORT_TOGGLE = '.talkers-export [slot="trigger"]';
const EXPORT = `document.getElementById('statsExport')`;
const TOGGLE = `document.querySelector(${JSON.stringify(EXPORT_TOGGLE)})`;
const exportItem = (kind) => `document.querySelector('#statsExportMenu [data-export="${kind}"]')`;
const ON_TOGGLE = `document.activeElement === ${TOGGLE}`;

/** Whether the Export popover is open; throws when its open property and its top-layer panel disagree. */
const exportOpen = (page) =>
    page.evaluate(`(() => {
        const host = ${EXPORT};
        const shown = host.shadowRoot.querySelector('.pop').matches(':popover-open');
        if (host.open !== shown) throw new Error('#statsExport: open is ' + host.open + ' but the panel is ' + (shown ? 'shown' : 'hidden'));
        return shown;
    })()`);

/** The rounded viewport boxes of the Export trigger and panel. */
const EXPORT_BOXES = `(() => {
    const round = (r) => ({ top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right) });
    return {
        trigger: round(${TOGGLE}.getBoundingClientRect()),
        panel: round(${EXPORT}.shadowRoot.querySelector('.pop').getBoundingClientRect()),
        view: { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight },
    };
})()`;

/** Presses Tab until the focus is on `selector`; returns the stops on the way, the last included. */
async function tabTo(page, selector, max = 30) {
    const path = [];
    for (let i = 0; i < max; i++) {
        await tab(page);
        path.push(await page.evaluate(FOCUSED));
        if (await page.evaluate(`!!document.activeElement?.matches(${JSON.stringify(selector)})`)) return path;
    }
    assert.fail(`Tab never reached ${selector}, went through ${path.join(' > ')}`);
}

const queries = (log) => log.names().filter((n) => QUERIES.includes(n));

/**
 * A real press and release on the element: over plain HTTP only user activation lets execCommand copy.
 * `scroll: false` for an item in an open popover, whose panel follows its trigger.
 */
async function clickAt(page, expr, { scroll = true } = {}) {
    const { x, y } = await page.evaluate(`(function(){
        var el = ${expr};
        if (${scroll}) el.scrollIntoView({ block: 'center' });
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
        window.__copies.push({ ok: e.detail.ok, text: e.detail.text, viaExecCommand: viaExecCommand });
        viaExecCommand = null;
    });
    new MutationObserver(function(records){
        records.forEach(function(r){ r.addedNodes.forEach(function(n){ if (n.textContent) window.__said.push(n.textContent); }); });
    }).observe(document.getElementById('nfsen-announcer'), { childList: true, subtree: true, characterData: true });
})()`;

/** Where a panel's Run sits against its cost line and its status line, after a run. */
const panelRow = (panel) => `(function(){
    var p = document.getElementById('talkersPanel-${panel}');
    var box = function(e){ return e && e.getClientRects().length ? e.getBoundingClientRect() : null; };
    var run = box(p.querySelector('button[data-variant="primary"]'));
    var cost = box(p.querySelector('.talkers-panel-cost'));
    var status = box(p.querySelector('.query-progress'));
    return {
        costBeside: !!cost && cost.top < run.bottom && cost.bottom > run.top,
        statusBelow: status ? status.top >= run.bottom - 1 : null,
    };
})()`;

/** Rows of a side panel: [label, series slot or null, value]. */
const bars = (panel) =>
    `[...document.querySelectorAll('#talkersPanel-${panel} .bar-list .bar-row')].map((r) => [r.querySelector('.bar-label').textContent.trim(), r.querySelector('.bar').dataset.series ?? null, r.querySelector('.bar-value').textContent.trim()])`;

export default async function talkersTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/#/talkers');
        await page.waitForBoot();
        await page.waitForPage('talkers');
        await page.setRangePreset('1y');
        const log = await page.requestLog();

        // Choosing a statistic selects it and runs nothing (D12).
        await page.evaluate(`document.getElementById('talkersTab-talkers').click()`);
        await page.waitFor(`${FOR_SELECT} === 'ip' || ${FOR_SELECT} === 'srcip' || ${FOR_SELECT} === 'dstip'`, {
            label: 'the Talkers statistic',
        });
        await page.evaluate(`document.getElementById('statsDir-any').click()`);
        await page.waitFor(`${FOR_SELECT} === 'ip'`, { label: 'Any IP address' });
        assert.deepEqual(await page.evaluate(CHECKED), ['talkers']);

        // The direction resolves the element of the family.
        await page.evaluate(`document.getElementById('statsDir-src').click()`);
        await page.waitFor(`${FOR_SELECT} === 'srcip'`, { label: 'Src IP address' });
        await page.evaluate(`document.getElementById('statsDir-dst').click()`);
        await page.waitFor(`${FOR_SELECT} === 'dstip'`, { label: 'Dst IP address' });
        await page.evaluate(`document.getElementById('talkersTab-ports').click()`);
        await page.waitFor(`${FOR_SELECT} === 'dstport'`, { label: 'the Ports tab keeps the direction' });

        // Protocols has no direction; its radios are disabled.
        await page.evaluate(`document.getElementById('talkersTab-protocols').click()`);
        await page.waitFor(`${FOR_SELECT} === 'proto'`, { label: 'Proto' });
        assert.equal(await page.evaluate(`document.getElementById('statsDir-src').disabled`), true, 'no direction for Proto');

        // A More item leaves every radio unchecked and still sets the direction it has.
        await page.setSelectValue('#statsFilterForSelection', 'srctos');
        await page.waitFor(`${CHECKED}.length === 0`, { label: 'no statistic radio checked for a More item' });
        await page.waitFor(`document.getElementById('statsDir-src').checked`, { label: 'the More item’s direction' });
        assert.equal(await page.evaluate(`document.getElementById('statsDir-src').disabled`), false);

        // nfdump 1.7.10 has no NEL statistics: they are offered disabled, with the reason (D23).
        const nel = await page.evaluate(
            `(function(){ var o = document.querySelector('#statsFilterForSelection option[value="nevent"]'); return [o.disabled, o.title, o.textContent]; })()`
        );
        if (process.env.NFDUMP_HAS_NEL === '1') {
            assert.equal(nel[0], false, 'this nfdump computes the NEL statistics');
        } else {
            assert.equal(nel[0], true, `NAT Event type is disabled on a release nfdump such as 1.7.10, got ${JSON.stringify(nel)}`);
            assert.match(nel[1], /\S/, 'a disabled NEL statistic says why');
            assert.match(nel[2], /not supported by this nfdump/);
        }
        assert.deepEqual(queries(log), [], `choosing statistics ran no query, got ${log.names().join(', ')}`);

        // A run is kept per statistic: another statistic shows its empty state, coming back shows the run.
        await page.evaluate(`document.getElementById('talkersTab-talkers').click()`);
        await page.evaluate(`document.getElementById('statsDir-src').click()`);
        await page.waitFor(`${FOR_SELECT} === 'srcip'`, { label: 'Src IP address again' });
        // Export and CSV are chosen 250 ms after the run brought the trigger, before any sync (6, item 4).
        assert.equal(await page.evaluate(`!!${EXPORT}`), false, 'no Export before the first run');
        await page.evaluate(`(function(){
            window.__exported = null;
            window.__createObjectURL ??= URL.createObjectURL;
            URL.createObjectURL = function (blob) { window.__exported = blob; return 'blob:e2e'; };
            window.__anchorClick ??= HTMLAnchorElement.prototype.click;
            HTMLAnchorElement.prototype.click = function () { window.__download = this.download; };
            window.__e2eRightAway = new Promise(function(resolve){
                new MutationObserver(function(records, observer){
                    if (!${TOGGLE}?.checkVisibility()) return;
                    observer.disconnect();
                    var at = performance.now();
                    setTimeout(function(){
                        ${TOGGLE}.click();
                        setTimeout(function(){
                            var open = ${EXPORT}.open === true;
                            ${exportItem('csv')}.click();
                            resolve({ open: open, closed: ${EXPORT}.open === false, after: Math.round(performance.now() - at) });
                        }, 100);
                    }, 250);
                }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
            });
        })()`);
        await page.runQuery('talkers', { timeout: 30000 });
        await page.waitFor(visible('#statsTable'), { label: 'the srcip table' });
        const early = await page.evaluate('window.__e2eRightAway');
        assert.ok(early.after < 1000, `CSV chosen within the first second of the result (${early.after} ms)`);
        assert.deepEqual([early.open, early.closed], [true, true], 'the trigger opened Export and choosing CSV closed it');
        await page.waitFor('!!window.__exported', { label: 'the CSV chosen right after the run' });
        const csv = JSON.parse(
            await page.evaluate(
                `window.__exported.text().then(function(text){ return JSON.stringify({ text: text, name: window.__download }); })`
            )
        );
        await page.evaluate(`URL.createObjectURL = window.__createObjectURL; HTMLAnchorElement.prototype.click = window.__anchorClick;`);
        assert.match(csv.name, /^top-talkers-\w+\.csv$/, `export file name: ${csv.name}`);
        const tableRows = await page.evaluate(`document.getElementById('statsTable').rows.length`);
        assert.ok(tableRows > 0, 'the result has rows');
        assert.equal(csv.text.trimEnd().split('\n').length, tableRows + 1, 'the CSV chosen right away holds every row');
        await page.waitFor(ON_TOGGLE, { label: 'choosing CSV right away returns the focus to the trigger' });
        const statsHost = await page.evaluate(`(function(){
            var t = document.getElementById('statsTable');
            return { id: t.rocketInstanceId, shadow: t.shadowRoot ? [...t.shadowRoot.childNodes].map(function(n){ return n.nodeName; }).join() : null };
        })()`);
        assert.ok(typeof statsHost.id === 'string' && statsHost.id !== '', `#statsTable is a Rocket host, got ${statsHost.id}`);
        assert.equal(statsHost.shadow, 'SLOT', "#statsTable's shadow root holds only its slot (shape A)");
        const title = await page.evaluate(`document.getElementById('statsResultsTitle').textContent`);
        assert.match(title, /^Src IP address, ordered by \w+/, `results title: ${title}`);
        const announced = await page.evaluate(`document.querySelector('#statsRun [role="status"]').textContent`);
        assert.match(announced, /^[\d,]+ rows? returned\. Done in /, `the Run control announces the row count, got: ${announced}`);
        // A large read runs as time slices in parallel nfdump processes (PERF-SPEC P4), and says how many.
        const done = await page.evaluate(`document.querySelector('#statsRun .query-progress-line > span').textContent.trim()`);
        assert.match(
            done,
            /^Done in [\d.]+s(?: with \d+ nfdump processes)?\.$/,
            `the outcome names the processes of a split, got: ${done}`
        );
        const ran = log.count('stats-actions');
        assert.equal(ran, 1, 'Run posted stats-actions once');

        // Copy (nfsen/clipboard) takes the command over plain HTTP too; the $_stats_copied label
        // says Copied and #nfsen-announcer says it for each of two copies.
        await page.evaluate(WATCH_COPIES);
        const COPY = `document.querySelector('#statsMessage button[data-copy-source="statsCommand"]')`;
        const command = await page.evaluate(`document.getElementById('statsCommand').textContent`);
        const plain = !(await page.evaluate('window.isSecureContext'));
        for (const round of [1, 2]) {
            await page.waitFor(`${COPY}.textContent.trim() === 'Copy'`, {
                timeout: 5000,
                label: `copy ${round}: the label reads Copy first`,
            });
            await clickAt(page, COPY);
            await page.waitFor(`window.__copies.length === ${round} && window.__said.length === ${round}`, {
                label: `copy ${round} and its announcement`,
            });
            const copy = await page.evaluate('window.__copies.at(-1)');
            assert.equal(copy.ok, true, `copy ${round} succeeded`);
            assert.equal(copy.text, command, `copy ${round} holds the nfdump command`);
            if (plain) assert.equal(copy.viaExecCommand, command, `copy ${round}: over plain HTTP the textarea fallback copied it`);
            await page.waitFor(`${COPY}.textContent.trim() === 'Copied'`, { label: `copy ${round}: the label says Copied` });
        }
        assert.deepEqual(await page.evaluate('window.__said'), ['Copied.', 'Copied.'], 'both copies are announced');
        await page.waitFor(`${COPY}.textContent.trim() === 'Copy'`, { timeout: 5000, label: 'the label reads Copy again' });

        // Export is open when another statistic is picked: the press closes it, the pick hides its host.
        await clickAt(page, TOGGLE);
        await page.waitFor(`${EXPORT}.open === true`, { label: 'a click opens Export' });
        await clickAt(page, `document.querySelector('label[for="talkersTab-ports"]')`);
        await page.waitFor(`${FOR_SELECT} === 'srcport'`, { label: 'Src port' });
        await page.waitFor(visible('#statsResults .empty-state'), { label: 'the empty state for a statistic without a run' });
        assert.equal(await page.evaluate(visible('#statsTable')), false, 'the srcip table is hidden for Src port');
        assert.equal(await page.evaluate(`document.getElementById('statsResultsTitle').textContent`), 'Results');
        assert.equal(await exportOpen(page), false, 'the press on another statistic closed Export');
        assert.deepEqual(
            await page.evaluate(`[${EXPORT}.hidden, ${TOGGLE}.checkVisibility()]`),
            [true, false],
            'another statistic hides the Export popover'
        );

        await page.evaluate(`document.getElementById('talkersTab-talkers').click()`);
        await page.waitFor(visible('#statsTable'), { label: 'the srcip table comes back' });
        assert.equal(await page.evaluate(`document.getElementById('statsResultsTitle').textContent`), title);
        assert.equal(log.count('stats-actions'), ran, 'switching back ran nothing');
        assert.deepEqual(await page.evaluate(`[${EXPORT}.hidden, ${TOGGLE}.checkVisibility()]`), [false, true], 'Export is back');
        assert.equal(await exportOpen(page), false, 'and closed');

        // Changing an input the run used marks it stale without posting; undoing it clears that.
        await page.setSelectValue('#statsCount', '20');
        await page.waitFor(visible('#statsResults .notice[data-kind="stale"]'), { label: 'the stale notice' });
        await page.setSelectValue('#statsCount', '10');
        await page.waitFor(`!${visible('#statsResults .notice[data-kind="stale"]')}`, { label: 'the stale notice to go' });

        // The side panels run on their own: TCP in slot 1, rows outside TCP/UDP/ICMP neutral (2.3).
        await page.runQuery('talkers-proto', { timeout: 30000 });
        const proto = await page.evaluate(bars('proto'));
        assert.ok(proto.length > 0, 'the protocol share has bars');
        const tcp = proto.find(([label]) => label === 'TCP');
        assert.ok(tcp, `a TCP bar, got ${JSON.stringify(proto)}`);
        assert.equal(tcp[1], '1', 'TCP takes series slot 1');
        for (const [label, slot] of proto) {
            if (!['TCP', 'UDP', 'ICMP', 'ICMP6'].includes(label)) assert.equal(slot, null, `${label} stays neutral`);
        }
        assert.equal(log.count('stats-actions'), ran, 'a panel run is not a statistics run');
        await page.runQuery('talkers-as', { timeout: 30000 });
        const asn = await page.evaluate(bars('as'));
        const asText = asn.length ? '' : await page.evaluate(`document.querySelector('#talkersPanel-as .card-body').innerText`);
        assert.ok(asn.length > 0, `the ASN panel has bars, it says: ${asText} (status ${await page.signalValue('query_status')})`);
        assert.deepEqual(await page.evaluate(bars('proto')), proto, 'the protocol panel kept its bars');
        assert.equal(await page.evaluate(visible('#statsTable')), true, 'the statistic kept its table');

        // A run's status line wraps below; Run stays on the cost line in both panels.
        await page.waitFor(visible('#talkersPanel-as .talkers-panel-cost'), { label: 'the panel cost line' });
        assert.deepEqual(await page.evaluate(panelRow('as')), { costBeside: true, statusBelow: true }, 'Top ASNs after its run');
        assert.deepEqual(await page.evaluate(panelRow('proto')), { costBeside: true, statusBelow: null }, 'Protocol share');

        // Keyboard: arrows move through the statistic radios; Tab walks More statistics, the direction, the three
        // Run controls and the Export trigger, whose closed popover's items are not tab stops.
        await page.evaluate(`document.getElementById('talkersTab-talkers').focus()`);
        await key(page, 'ArrowRight', 'ArrowRight', 39);
        assert.equal(await page.evaluate(`document.activeElement.id`), 'talkersTab-ports', 'ArrowRight moves to Ports');
        await page.waitFor(`${FOR_SELECT} === 'srcport'`, { label: 'the arrow chose Ports' });
        await key(page, 'ArrowLeft', 'ArrowLeft', 37);
        await page.waitFor(visible('#statsTable'), { label: 'back on Talkers by keyboard' });
        assert.equal(await page.evaluate(`document.activeElement.id`), 'talkersTab-talkers');

        await tab(page);
        assert.equal(await page.evaluate(FOCUSED), 'statsFilterForSelection', 'Tab leaves the statistic radios for More statistics');
        await tab(page);
        assert.equal(await page.evaluate(FOCUSED), 'statsDir-src', 'then the checked direction');
        await tab(page);
        assert.equal(await page.evaluate(FOCUSED), 'statsCount', 'then Top records');
        await tab(page, { shift: true });
        await tab(page, { shift: true });
        await tab(page, { shift: true });
        assert.equal(await page.evaluate(FOCUSED), 'talkersTab-talkers', 'Shift+Tab walks back to the checked statistic');

        await page.evaluate(`document.getElementById('statsCount').focus()`);
        const walk = await tabTo(page, EXPORT_TOGGLE);
        const runs = ['statsRunSubmit', 'talkersPanelRun-protoSubmit', 'talkersPanelRun-asSubmit'].map((id) => walk.indexOf(id));
        assert.ok(
            runs.every((at, i) => at >= 0 && (i === 0 || at > runs[i - 1])),
            `the Run controls in page order, went through ${walk.join(' > ')}`
        );
        assert.equal(walk.at(-2), 'talkersPanelRun-asSubmit', `nothing between the last Run and Export, went through ${walk.join(' > ')}`);
        assert.ok(!walk.some((id) => id.startsWith('filterStats')), `the hidden aggregation block has no tab stops: ${walk.join(' > ')}`);
        await tab(page);
        assert.equal(await page.evaluate(FOCUSED), 'Copy the nfdump command', 'Tab from Export skips its closed items');
        await tab(page, { shift: true });
        assert.equal(await page.evaluate(ON_TOGGLE), true, 'Shift+Tab is back on Export');

        // Enter opens the popover on CSV; the component names it a dialog and never draws its own trigger.
        const queriesBefore = queries(log).length;
        await key(page, 'Enter', 'Enter', 13);
        await page.waitFor(`document.activeElement?.dataset.export === 'csv'`, { label: 'Enter opens Export with the focus on CSV' });
        assert.equal(await exportOpen(page), true, 'Enter opens Export');
        assert.deepEqual(
            await page.evaluate(`(() => {
                const t = ${TOGGLE};
                return [t.getAttribute('aria-haspopup'), t.getAttribute('aria-expanded'), ${EXPORT}.shadowRoot.querySelector('slot[name=trigger]').assignedElements().length];
            })()`),
            ['dialog', 'true', 1],
            'the slotted trigger says dialog and expanded'
        );
        const PANEL_NAME = `(() => { const pop = ${EXPORT}.shadowRoot.querySelector('.pop'); return [pop.getAttribute('role'), pop.getAttribute('aria-label')]; })()`;
        const caption = title.split(' · ')[0];
        assert.deepEqual(await page.evaluate(PANEL_NAME), ['dialog', `Export ${caption}`], 'the panel is a dialog named after the result');
        assert.deepEqual(
            await page.evaluate(`[...document.querySelectorAll('#statsExportMenu [data-export]')].map((b) => b.textContent.trim())`),
            ['CSV', 'JSON', 'Print']
        );
        await key(page, 'ArrowDown', 'ArrowDown', 40);
        assert.equal(await page.evaluate(`document.activeElement.dataset.export`), 'json', 'ArrowDown moves to JSON');

        // A sync morphs the page around the open popover: it stays open, the focus on JSON.
        await page.syncNow('talkers');
        assert.equal(await exportOpen(page), true, 'Export stays open across a sync');
        assert.equal(await page.evaluate(`document.activeElement.dataset.export`), 'json', 'the focus stays on JSON across a sync');
        assert.equal(await page.evaluate(`${TOGGLE}.getAttribute('aria-expanded')`), 'true', 'the trigger still says expanded');
        assert.deepEqual(await page.evaluate(PANEL_NAME), ['dialog', `Export ${caption}`], 'the panel keeps its name across a sync');

        await key(page, 'Escape', 'Escape', 27);
        assert.equal(await page.evaluate(ON_TOGGLE), true, 'Escape returns the focus to the Export trigger');
        assert.equal(await exportOpen(page), false, 'Escape closes Export');

        // Space opens on CSV as Enter does; ArrowDown on the trigger opens on the first item (N3).
        await key(page, ' ', 'Space', 32);
        await page.waitFor(`document.activeElement?.dataset.export === 'csv'`, { label: 'Space opens Export with the focus on CSV' });
        assert.equal(await exportOpen(page), true, 'Space opens Export');
        await key(page, 'Escape', 'Escape', 27);
        assert.equal(await page.evaluate(ON_TOGGLE), true, 'Escape after Space returns the focus to the trigger');
        assert.equal(await exportOpen(page), false, 'Escape after Space closes Export');
        await key(page, 'ArrowDown', 'ArrowDown', 40);
        await page.waitFor(`document.activeElement?.dataset.export === 'csv'`, { label: 'ArrowDown on the trigger opens Export on CSV' });
        assert.equal(await exportOpen(page), true, 'ArrowDown on the trigger opens Export');
        await key(page, 'Escape', 'Escape', 27);
        assert.equal(await page.evaluate(ON_TOGGLE), true, 'Escape after ArrowDown returns the focus to the trigger');
        assert.equal(await exportOpen(page), false, 'Escape after ArrowDown closes Export');

        // Choosing an item exports, closes the popover and returns the focus to its trigger.
        await page.evaluate(`(function(){
            window.__downloads = [];
            HTMLAnchorElement.prototype.click = function(){ window.__downloads.push(this.download); };
        })()`);
        for (const [n, kind] of ['csv', 'json'].entries()) {
            await clickAt(page, TOGGLE);
            await page.waitFor(`${EXPORT}.open === true && ${exportItem(kind)}.checkVisibility()`, { label: `Export open for ${kind}` });
            await clickAt(page, exportItem(kind), { scroll: false });
            await page.waitFor(`window.__downloads.length === ${n + 1}`, { label: `the ${kind} download` });
            await page.waitFor(`${EXPORT}.open === false`, { label: `choosing ${kind} closes Export` });
            assert.equal(await page.evaluate(ON_TOGGLE), true, `choosing ${kind} returns the focus to the trigger`);
        }
        const files = await page.evaluate('window.__downloads');
        assert.match(files[0], /^top-talkers-\w+\.csv$/, 'CSV exports the table');
        assert.match(files[1], /^top-talkers-\w+\.json$/, 'JSON exports the table');
        assert.deepEqual(queries(log).slice(queriesBefore), [], 'opening Export and exporting ran no query');

        // At 390 x 844 the panel lies inside the viewport and the trigger does not move.
        await page.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        try {
            // The page reflows for a while after the resize: scroll until two looks agree.
            await page.waitFor(
                `(() => {
                    const t = ${TOGGLE};
                    t.scrollIntoView({ block: 'center' });
                    const box = JSON.stringify(t.getBoundingClientRect());
                    const steady = window.__exportBox === box;
                    window.__exportBox = box;
                    return steady && document.documentElement.clientWidth === 390;
                })()`,
                { interval: 250, label: 'the Export trigger to settle in view at 390 px' }
            );
            const before = await page.evaluate(EXPORT_BOXES);
            await clickAt(page, TOGGLE, { scroll: false });
            await page.waitFor(`${EXPORT}.open === true`, { label: 'Export opens at 390 px' });
            const { trigger, panel, view } = await page.evaluate(EXPORT_BOXES);
            assert.equal(view.width, 390, 'a 390 px viewport');
            assert.deepEqual(trigger, before.trigger, 'the Export trigger stays where it was');
            const inside = panel.left >= 0 && panel.top >= 0 && panel.right <= view.width && panel.bottom <= view.height;
            assert.ok(inside && panel.bottom > panel.top, `the Export panel lies inside the viewport: ${JSON.stringify({ panel, view })}`);
            await key(page, 'Escape', 'Escape', 27);
            assert.equal(await exportOpen(page), false, 'Escape closes Export at 390 px');
        } finally {
            await page.send('Emulation.clearDeviceMetricsOverride');
        }

        // A statistic without a direction takes the direction radios out of the tab order.
        await page.evaluate(`document.getElementById('talkersTab-talkers').focus()`);
        await key(page, 'ArrowRight', 'ArrowRight', 39);
        await key(page, 'ArrowRight', 'ArrowRight', 39);
        await page.waitFor(`${FOR_SELECT} === 'proto'`, { label: 'the arrows chose Protocols' });
        await page.waitFor(`document.getElementById('statsDir-src').disabled`, { label: 'the direction disabled for Proto' });
        await tab(page);
        await tab(page);
        assert.equal(await page.evaluate(FOCUSED), 'statsCount', 'Tab skips the disabled direction');
        await page.evaluate(`document.getElementById('talkersTab-talkers').click()`);
        await page.waitFor(visible('#statsTable'), { label: 'the srcip table once more' });
        assert.equal(log.count('stats-actions'), ran, 'the keyboard walk ran nothing');

        await page.evaluate(`document.activeElement?.blur()`);
        await page.evaluate(`window.scrollTo(0, document.querySelector('.stat-bar').getBoundingClientRect().top + window.scrollY - 16)`);
        await page.withForcedColors(() => page.screenshot('/tmp/nfsen-talkers-forced-colors.png'));

        // Forced colours keep the open panel's edge and the focused item's ring.
        await page.evaluate(`${TOGGLE}.scrollIntoView({ block: 'center' }); ${TOGGLE}.focus()`);
        const forced = await page.withForcedColors(async () => {
            await key(page, 'Enter', 'Enter', 13);
            await page.waitFor(`document.activeElement?.dataset.export === 'csv'`, { label: 'Export open on CSV in forced colours' });
            await page.screenshot('/tmp/nfsen-talkers-export-forced-colors.png');
            return page.evaluate(`(() => {
                const item = ${exportItem('csv')};
                return {
                    edge: getComputedStyle(${EXPORT}.shadowRoot.querySelector('.panel')).outlineStyle,
                    ring: item.matches(':focus-visible') ? getComputedStyle(item).outlineStyle : 'no :focus-visible',
                };
            })()`);
        });
        await key(page, 'Escape', 'Escape', 27);
        assert.notEqual(forced.edge, 'none', 'the Export panel keeps an edge in forced colours');
        assert.ok(!['none', 'no :focus-visible'].includes(forced.ring), `CSV shows its focus ring in forced colours, got ${forced.ring}`);
        assert.equal(await exportOpen(page), false, 'Escape closes Export');

        // A brush on the picker graph (dispatched as ECharts reports one) sets the range and runs nothing (1.8).
        const GRAPH = "document.getElementById('trafficGraph')";
        await page.waitFor(`!!${GRAPH}?.chart`, { label: 'the picker graph' });
        log.clear();
        const { datestart: before, data_range_min: dataMin } = await page.signalValues(['datestart', 'data_range_min']);
        const min = dataMin ?? 0;
        const brush = await page.evaluate(`(function(){
            var src = ${GRAPH}.chart.getOption().dataset[0].source;
            var first = Math.max(src[0][0], ${min} * 1000), last = src[src.length - 1][0];
            return [first + (last - first) * 0.6, first + (last - first) * 0.8];
        })()`);
        for (const type of ['brush', 'brushEnd']) {
            await page.evaluate(
                `${GRAPH}.chart.dispatchAction({ type: '${type}', areas: [{ brushType: 'lineX', xAxisIndex: 0, coordRange: ${JSON.stringify(brush)} }] })`
            );
        }
        const start = Date.now();
        while ((await page.signalValue('datestart')) === before && Date.now() - start < 8000) await sleep(200);
        const { datestart: from, range_live: live } = await page.signalValues(['datestart', 'range_live']);
        assert.ok(Math.abs(from - brush[0] / 1000) <= 300, `the brush set the range: ${from} for ${brush[0] / 1000}`);
        assert.equal(live, false, 'a brushed range is fixed');
        await sleep(800);
        assert.deepEqual(queries(log), [], `the brush ran no query, got ${log.names().join(', ')}`);

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    talkersTest()
        .then(() => console.log('talkers: PASS'))
        .catch((e) => {
            console.error('talkers: FAIL\n', e);
            process.exit(1);
        });
}
