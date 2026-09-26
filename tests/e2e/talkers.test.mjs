// Top Talkers (4.2): the statistic picker never runs a query, the direction resolves the element,
// results are kept per statistic, the side panels run on their own with the protocol colours of
// the picker graph, and a brush on that graph only changes the range. Includes the keyboard walk
// and a forced-colors screenshot (V-A11Y). NFDUMP_HAS_NEL=1 is for an nfdump that computes the
// NEL statistics; the dev image's 1.7.8 does not.
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
    if (name === 'Enter') await page.send('Input.dispatchKeyEvent', { type: 'char', text: '\r', key: 'Enter' });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
    await sleep(150);
}

const tab = (page, opts) => key(page, 'Tab', 'Tab', 9, opts);
const FOCUSED = `(() => { const a = document.activeElement; return a?.id || a?.getAttribute('aria-label') || a?.textContent.trim().slice(0, 40) || a?.tagName || ''; })()`;
const EXPORT_TOGGLE = '.talkers-export [aria-haspopup="menu"]';

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

/** Runs a side panel; once more when the dev app restarted under the first click (status reset to ''). */
async function runPanel(page, panel) {
    for (let attempt = 1; ; attempt++) {
        await page.runQuery(`talkers-${panel}`, { timeout: 30000 });
        const ran = await page.evaluate(`!!document.querySelector('#talkersPanel-${panel} :is(.bar-list, .notice[data-level])')`);
        if (ran || attempt === 2 || (await page.signalValue('query_status')) !== '') return;
        await sleep(2000);
    }
}

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

        // nfdump 1.7.8 has no NEL statistics: they are offered disabled, with the reason (D23).
        const nel = await page.evaluate(
            `(function(){ var o = document.querySelector('#statsFilterForSelection option[value="nevent"]'); return [o.disabled, o.title, o.textContent]; })()`
        );
        if (process.env.NFDUMP_HAS_NEL === '1') {
            assert.equal(nel[0], false, 'this nfdump computes the NEL statistics');
        } else {
            assert.equal(nel[0], true, `NAT Event type is disabled on nfdump 1.7.8, got ${JSON.stringify(nel)}`);
            assert.match(nel[1], /\S/, 'a disabled NEL statistic says why');
            assert.match(nel[2], /not supported by this nfdump/);
        }
        assert.deepEqual(queries(log), [], `choosing statistics ran no query, got ${log.names().join(', ')}`);

        // A run is kept per statistic: another statistic shows its empty state, coming back shows the run.
        await page.evaluate(`document.getElementById('talkersTab-talkers').click()`);
        await page.evaluate(`document.getElementById('statsDir-src').click()`);
        await page.waitFor(`${FOR_SELECT} === 'srcip'`, { label: 'Src IP address again' });
        await page.runQuery('talkers', { timeout: 30000 });
        await page.waitFor(visible('#statsTable'), { label: 'the srcip table' });
        const title = await page.evaluate(`document.getElementById('statsResultsTitle').textContent`);
        assert.match(title, /^Src IP address, ordered by \w+/, `results title: ${title}`);
        const announced = await page.evaluate(`document.querySelector('#statsRun [role="status"]').textContent`);
        assert.match(announced, /^[\d,]+ rows? returned\. Done in /, `the Run control announces the row count, got: ${announced}`);
        const ran = log.count('stats-actions');
        assert.equal(ran, 1, 'Run posted stats-actions once');

        await page.evaluate(`document.getElementById('talkersTab-ports').click()`);
        await page.waitFor(`${FOR_SELECT} === 'srcport'`, { label: 'Src port' });
        await page.waitFor(visible('#statsResults .empty-state'), { label: 'the empty state for a statistic without a run' });
        assert.equal(await page.evaluate(visible('#statsTable')), false, 'the srcip table is hidden for Src port');
        assert.equal(await page.evaluate(`document.getElementById('statsResultsTitle').textContent`), 'Results');

        await page.evaluate(`document.getElementById('talkersTab-talkers').click()`);
        await page.waitFor(visible('#statsTable'), { label: 'the srcip table comes back' });
        assert.equal(await page.evaluate(`document.getElementById('statsResultsTitle').textContent`), title);
        assert.equal(log.count('stats-actions'), ran, 'switching back ran nothing');

        // Changing an input the run used marks it stale without posting; undoing it clears that.
        await page.setSelectValue('#statsCount', '20');
        await page.waitFor(visible('#statsResults .notice[data-kind="stale"]'), { label: 'the stale notice' });
        await page.setSelectValue('#statsCount', '10');
        await page.waitFor(`!${visible('#statsResults .notice[data-kind="stale"]')}`, { label: 'the stale notice to go' });

        // The side panels run on their own: TCP in slot 1, rows outside TCP/UDP/ICMP neutral (2.3).
        await runPanel(page, 'proto');
        const proto = await page.evaluate(bars('proto'));
        assert.ok(proto.length > 0, 'the protocol share has bars');
        const tcp = proto.find(([label]) => label === 'TCP');
        assert.ok(tcp, `a TCP bar, got ${JSON.stringify(proto)}`);
        assert.equal(tcp[1], '1', 'TCP takes series slot 1');
        for (const [label, slot] of proto) {
            if (!['TCP', 'UDP', 'ICMP', 'ICMP6'].includes(label)) assert.equal(slot, null, `${label} stays neutral`);
        }
        assert.equal(log.count('stats-actions'), ran, 'a panel run is not a statistics run');
        await runPanel(page, 'as');
        const asn = await page.evaluate(bars('as'));
        const asText = asn.length ? '' : await page.evaluate(`document.querySelector('#talkersPanel-as .card-body').innerText`);
        assert.ok(asn.length > 0, `the ASN panel has bars, it says: ${asText} (status ${await page.signalValue('query_status')})`);
        assert.deepEqual(await page.evaluate(bars('proto')), proto, 'the protocol panel kept its bars');
        assert.equal(await page.evaluate(visible('#statsTable')), true, 'the statistic kept its table');

        // A run's status line wraps below; Run stays on the cost line in both panels.
        await page.waitFor(visible('#talkersPanel-as .talkers-panel-cost'), { label: 'the panel cost line' });
        assert.deepEqual(await page.evaluate(panelRow('as')), { costBeside: true, statusBelow: true }, 'Top ASNs after its run');
        assert.deepEqual(await page.evaluate(panelRow('proto')), { costBeside: true, statusBelow: null }, 'Protocol share');

        // Keyboard: arrows move through the statistic radios, Tab leaves the group for More
        // statistics and then the direction, and walks the query card to the three Run controls
        // and the Export toggle, whose menu items are not tab stops.
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
        assert.equal(await page.evaluate(FOCUSED), 'Copy the nfdump command', 'Tab from Export skips its menu items');
        await tab(page, { shift: true });
        assert.equal(
            await page.evaluate(`document.activeElement === document.querySelector('${EXPORT_TOGGLE}')`),
            true,
            'Shift+Tab is back on Export'
        );

        await key(page, 'Enter', 'Enter', 13);
        await page.waitFor(`document.activeElement?.getAttribute('role') === 'menuitem'`, { label: 'focus in the Export menu' });
        assert.deepEqual(
            await page.evaluate(`[...document.querySelectorAll('#statsExportMenu [role="menuitem"]')].map((b) => b.textContent.trim())`),
            ['CSV', 'JSON', 'Print']
        );
        await key(page, 'ArrowDown', 'ArrowDown', 40);
        assert.equal(await page.evaluate(`document.activeElement.textContent.trim()`), 'JSON');
        await key(page, 'Escape', 'Escape', 27);
        assert.equal(
            await page.evaluate(`document.activeElement === document.querySelector('${EXPORT_TOGGLE}')`),
            true,
            'Escape returns the focus to the Export toggle'
        );

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

        // A brush on the picker graph (dispatched as ECharts reports one) sets the range and runs nothing (1.8).
        const GRAPH = "document.getElementById('trafficGraph')";
        await page.waitFor(`!!${GRAPH}?.chart`, { label: 'the picker graph' });
        log.clear();
        const before = await page.signalValue('datestart');
        const min = (await page.signalValue('data_range_min')) ?? 0;
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
        const from = await page.signalValue('datestart');
        assert.ok(Math.abs(from - brush[0] / 1000) <= 300, `the brush set the range: ${from} for ${brush[0] / 1000}`);
        assert.equal(await page.signalValue('range_live'), false, 'a brushed range is fixed');
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
