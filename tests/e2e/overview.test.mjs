// Overview (spec 4.1, 5.5): the graph header's mode label, the Options panel closed by default,
// the KPI strip and the top-N card right under the graph, the KPI values after #topnFill, top-N
// tabs by keyboard, TCP in slot 1 while the graph shows protocols, a brush that sets the range,
// the exact nfdump run out of retention with its row count announced, and no stale answer on the
// way back. The graph is a Rocket element whose drag posts set-range once. V-A11Y: keyboard walk
// and a forced-colors screenshot to /tmp.
//
// The dev data has one day of flows about four weeks back, so the checks that need rows use the
// 30 day preset, and the empty 24 hour window checks the states instead.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHART = "document.getElementById('trafficGraph')";
const text = (selector) => `(document.querySelector(${JSON.stringify(selector)})?.textContent ?? '').replace(/\\s+/g, ' ').trim()`;

async function press(page, key) {
    const codes = { Enter: 13, Tab: 9, ArrowRight: 39, ArrowLeft: 37, Escape: 27, Space: 32 };
    const name = key === 'Space' ? ' ' : key;
    const base = { key: name, code: key, windowsVirtualKeyCode: codes[key], nativeVirtualKeyCode: codes[key] };
    await page.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...base });
    if (key === 'Enter') await page.send('Input.dispatchKeyEvent', { type: 'char', text: '\r', ...base });
    if (key === 'Space') await page.send('Input.dispatchKeyEvent', { type: 'char', text: ' ', ...base });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

/** The top-N card has answered for the current inputs: nothing pending, nothing dimmed. */
async function topnSettled(page, label) {
    await page.waitFor(`document.getElementById('ovTopnPanel')?.getAttribute('aria-busy') !== 'true'`, { timeout: 15000, label });
}

export default async function overviewTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/#/overview');
        await page.waitForBoot();
        await page.waitForPage('overview');
        await page.setRangePreset('24h');
        await page.waitFor(`!!${CHART}.dataset.chartData`, { label: 'the graph data' });

        // 4.1.2: the data-mode label. 24 hours at 500 points is the 5 minute RRA.
        await page.waitFor(`${text('#trafficGraphMode')} === 'Stored data · 5 min resolution'`, {
            label: 'the mode label "Stored data · 5 min resolution"',
        });
        assert.match(await page.evaluate(text('#trafficGraphTitle')), /^(Traffic|Packets|Flows) by (source|protocol|port)$/);

        // The Options panel is closed by default; its toggle opens it, by keyboard too.
        await page.evaluate(`localStorage.removeItem('nfsen-persist:_ov_optionsOpen')`);
        assert.equal(await page.evaluate(`document.getElementById('graphOptions').hidden`), true, 'the options start closed');
        assert.equal(await page.evaluate(`document.getElementById('graphOptionsToggle').getAttribute('aria-expanded')`), 'false');
        await page.evaluate(`document.getElementById('graphOptionsToggle').focus()`);
        await press(page, 'Enter');
        await page.waitFor(`!document.getElementById('graphOptions').hidden`, { label: 'Enter on the Options toggle to open the panel' });
        assert.equal(await page.evaluate(`document.getElementById('graphOptionsToggle').getAttribute('aria-expanded')`), 'true');
        await press(page, 'Enter');
        await page.waitFor(`document.getElementById('graphOptions').hidden`, { label: 'Enter again to close it' });

        // The KPI strip and the top-N card come right after the graph (4.1.4, mockup A); the
        // graph options live inside the graph section, collapsed, not between them.
        const order = await page.evaluate(`(function(){
            var graph = document.getElementById('trafficGraphSection');
            var kpis = document.getElementById('overviewKpis');
            var card = document.getElementById('overviewTopn');
            var options = document.getElementById('graphOptions');
            var page = document.getElementById('page-overview');
            var follows = function(a, b){ return !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING); };
            var between = [...page.querySelectorAll('*')].filter(function(el){ return follows(el, kpis) && el.matches('select, input, .card'); });
            return {
                optionsInGraph: graph.contains(options),
                kpisAfterGraph: follows(graph, kpis),
                cardAfterKpis: follows(kpis, card),
                nothingBefore: between.length,
                firstCard: page.querySelector('.card')?.id,
            };
        })()`);
        assert.deepEqual(order, { optionsInGraph: true, kpisAfterGraph: true, cardAfterKpis: true, nothingBefore: 0, firstCard: 'overviewTopn' });

        // #topnFill (Health) fills the per-interval top 50; then the 30 day window has rows.
        await page.gotoPage('health');
        await page.waitFor(`!!document.getElementById('topnFill')`, { label: '#topnFill on Health' });
        if (await page.evaluate(`!document.getElementById('topnFill').disabled`)) {
            await page.evaluate(`document.getElementById('topnFill').click()`);
            await sleep(3000);
        } else {
            console.log('  (overview: #topnFill is disabled here, the top-N is read as it is)');
        }
        await page.gotoPage('overview');
        await page.setRangePreset('30d');
        await topnSettled(page, 'the top-N for 30 days');

        const kpis = await page.evaluate(`[...document.querySelectorAll('#overviewKpis .kpi')].map(function(k){
            return { label: k.querySelector('.kpi-label').textContent.trim(), value: k.querySelector('.kpi-value').textContent.replace(/\\s+/g, ' ').trim(),
                     busy: k.getAttribute('aria-busy'), ip: !!k.querySelector('.ip-link'), approx: !!k.querySelector('.badge[data-kind="approx"]') };
        })`);
        assert.deepEqual(
            kpis.map((k) => k.label),
            ['Total traffic', 'Top source', 'Top destination', 'Top protocol']
        );
        for (const k of kpis) {
            assert.ok(k.value !== '' && k.busy !== 'true', `KPI ${k.label} has a value and is not busy: ${JSON.stringify(k)}`);
        }
        assert.match(kpis[0].value, /^\d+(\.\d+)? [kMGTP]?(b|B|iB)$|^Not available$/, `total traffic in the global unit: ${kpis[0].value}`);
        // The KPI cards and the table read the same stored lists: rows in one mean rows in the other.
        const hasRows = kpis[1].ip;
        if (hasRows) {
            assert.ok(kpis[1].approx && kpis[2].ip && kpis[3].approx, 'addresses link to IP info and carry the approx badge');
            assert.match(kpis[3].value, /^[A-Z0-9-]+$/, `the top protocol by name: ${kpis[3].value}`);
            assert.ok(await page.evaluate(`!!document.querySelector('#ovTopnTable tbody tr')`), 'the Talkers table has rows too');
        } else {
            // Only a stack without precomputed data may skip the row checks, and it says so.
            assert.match(kpis[1].value, /^(No flows in the precomputed lists|Collecting|Top-N disabled)/, `the card says why it has no rows: ${kpis[1].value}`);
            console.log(`  (overview: no precomputed rows in 30 days here (${kpis[1].value}); the Port column and TCP chip checks are skipped)`);
        }

        // Tabs by keyboard (manual activation, 2.5): arrows move focus, Enter selects and requeries.
        await page.evaluate(`document.getElementById('ovTab-talkers').focus()`);
        await press(page, 'ArrowRight');
        assert.equal(await page.evaluate(`document.activeElement?.id`), 'ovTab-ports', 'ArrowRight moves to Ports');
        assert.equal(await page.evaluate(`document.getElementById('ovTab-ports').getAttribute('aria-selected')`), 'false', 'moving does not select');
        await press(page, 'Enter');
        await page.waitFor(`document.getElementById('ovTab-ports').getAttribute('aria-selected') === 'true'`, { label: 'Enter selects Ports' });
        await page.waitFor(`document.getElementById('ovTopnPanel').getAttribute('aria-labelledby') === 'ovTab-ports'`, { label: 'the panel labelled by Ports' });
        await topnSettled(page, 'the Ports answer');
        if (hasRows) {
            await page.waitFor(`${text('#ovTopnTable thead th:nth-child(2)')} === 'Port'`, { label: 'the Port column' });
            assert.match(await page.evaluate(text('#ovTopnTable tbody tr td:nth-child(2)')), /^\d+\/(tcp|udp)/, 'port keys read 443/tcp');
        }

        // Display Protocols + the Protocols tab: TCP is series slot 1 in both (2.3).
        await page.evaluate(`document.getElementById('graphOptionsToggle').click()`);
        await page.setSelectValue('#filterDisplaySelect', 'protocols');
        await page.waitFor(`${CHART}.dataset.chartConfig?.includes('"display":"protocols"')`, { label: 'the graph to show protocols' });
        await page.evaluate(`document.getElementById('ovTab-protocols').click()`);
        await topnSettled(page, 'the Protocols answer');
        if (hasRows) {
            await page.waitFor(`[...document.querySelectorAll('#ovTopnTable tbody tr')].some(function(tr){ return tr.textContent.includes('TCP (6)'); })`, {
                label: 'the TCP row',
            });
            const chips = await page.evaluate(`[...document.querySelectorAll('#ovTopnTable tbody tr')].map(function(tr){
                return [tr.children[1].textContent.trim(), tr.querySelector('.rank').dataset.series ?? null];
            })`);
            assert.deepEqual(chips.find(([key]) => key === 'TCP (6)'), ['TCP (6)', '1'], 'TCP carries slot 1');
            for (const [key, slot] of chips) {
                if (!/^(TCP|UDP|ICMP|ICMPV6) \(/.test(key)) assert.equal(slot, null, `${key} stays neutral`);
            }
        }
        // Back to the defaults for whatever runs next.
        await page.setSelectValue('#filterDisplaySelect', 'sources');
        await page.evaluate(`document.getElementById('ovTab-talkers').click(); document.getElementById('graphOptionsToggle').click()`);
        await page.waitFor(`${CHART}.dataset.chartConfig?.includes('"display":"sources"')`, { label: 'the graph back on sources' });

        // A brush across the plot sets the range (1.8) with one post (6.9); Previous range brings the preset back.
        await page.waitFor(`!!${CHART}.chart`, { label: 'the chart' });
        assert.equal(await page.evaluate(`${CHART}.rocketInstanceId !== undefined`), true, 'the traffic graph is a Rocket host');
        await page.evaluate(`${CHART}.scrollIntoView({ block: 'center' })`);
        await sleep(300);
        const log = await page.requestLog();
        const before = await page.evaluate(`[${CHART}.getAttribute('aria-label')]`);
        const grid = await page.evaluate(`(function(){
            var el = ${CHART};
            var r = el.querySelector('.chart-canvas').getBoundingClientRect();
            var g = el.chart.getModel().getComponent('grid').coordinateSystem.getRect();
            return { x: r.left + g.x, y: r.top + g.y, w: g.width, h: g.height };
        })()`);
        const y = grid.y + grid.h / 2;
        const [x1, x2] = [grid.x + grid.w * 0.4, grid.x + grid.w * 0.7];
        await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: x1, y });
        await page.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: x1, y, button: 'left', clickCount: 1 });
        for (let i = 1; i <= 10; i++) {
            await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: x1 + ((x2 - x1) * i) / 10, y, button: 'left', buttons: 1 });
            await sleep(20);
        }
        await page.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: x2, y, button: 'left', clickCount: 1 });
        const window = `(async function(){
            var root = (await import('datastar')).root;
            var get = function(n){ return root[Object.keys(root).find(function(k){ return k.startsWith(n + '____'); })]; };
            return { from: get('datestart'), to: get('dateend'), live: get('range_live') };
        })()`;
        let brushed = await page.evaluate(window);
        for (let i = 0; i < 50 && brushed.live; i++) {
            await sleep(150);
            brushed = await page.evaluate(window);
        }
        assert.equal(brushed.live, false, 'a brushed range is fixed');
        await sleep(1000);
        assert.equal(log.count('set-range'), 1, `one drag posts set-range once, got ${log.names().join(', ')}`);
        assert.ok(brushed.to - brushed.from < 30 * 86400 * 0.5, `the brush narrowed the 30 day window (${brushed.to - brushed.from} s)`);
        assert.equal(brushed.from % 300, 0, 'the brushed start is on a 5 minute boundary');
        assert.ok(before[0], 'the chart carries an accessible name');
        assert.match(await page.evaluate(`${CHART}.getAttribute('aria-label')`), / to .*(peak|no data)/, 'the name gives the range and the peak');

        // Out of retention: the empty state, the estimate and the exact run, whose table replaces it.
        await page.setRangePreset('1y');
        await page.waitFor(`!!document.querySelector('button[data-run="overview-topn"]')`, { timeout: 15000, label: 'Run exact query out of retention' });
        assert.ok(await page.evaluate(`!!document.querySelector('#ovTopnPanel .empty-state') && !!document.querySelector('[data-estimate="overview-topn"]')`));
        assert.match(await page.evaluate(text('#kpi-src .kpi-value')), /^Outside the \d+ day window$/);
        await page.runQuery('overview-topn', { timeout: 120000 });
        await page.waitFor(`!!document.querySelector('#ovTopnPanel .topn-exact-label') || document.getElementById('overviewMessage')`, {
            timeout: 10000,
            label: 'the exact result or its notice',
        });
        if (await page.evaluate(`!!document.querySelector('#ovTopnPanel .topn-exact-label')`)) {
            assert.match(await page.evaluate(text('#ovTopnPanel .topn-exact-label')), /^Exact \(nfdump\) .*nfdump.* -s /, 'labelled with the command line');
            const rows = await page.evaluate(`document.querySelectorAll('#ovTopnTable tbody tr').length`);
            if (rows > 0) assert.equal(await page.evaluate(`!!document.querySelector('#ovTopnPanel .empty-state')`), false, 'the table replaces the empty state');
            // The share comes from the same nfdump run as the rows, not from a year of stored totals.
            const shares = await page.evaluate(
                `[...document.querySelectorAll('#ovTopnTable tbody tr')].map(function(r){ return parseFloat(r.querySelector('td[data-kind=share]').textContent); })`
            );
            if (rows > 0) assert.ok(shares.some((v) => v > 0), `a row has a share above 0%: ${shares.join(', ')}`);
            // 2.5: the run announces its result count.
            await page.waitFor(`/rows? returned\\. Done in /.test(document.querySelector('#overviewTopnRun [role=status]')?.textContent ?? '')`, {
                label: 'the "N rows returned" announcement',
            });
            assert.match(await page.evaluate(text('#overviewTopnRun [role=status]')), new RegExp(`^${rows} rows? returned\\. Done in `));
        } else {
            console.log(`  (overview: the exact run reported: ${await page.evaluate(text('#overviewMessage'))})`);
        }

        // Back inside the retention, the answer for 1 year is not shown as an empty table or as
        // "no flows" while the new one is computed.
        await page.evaluate(`(function(){
            window.__ovStale = [];
            var check = function(){
                var panel = document.getElementById('ovTopnPanel');
                var kpi = document.getElementById('kpi-src');
                if (panel && panel.dataset.state === 'table' && !panel.querySelector('#ovTopnTable')) window.__ovStale.push('a table state without rows');
                if (kpi && kpi.getAttribute('aria-busy') === 'true' && /No flows in the precomputed/.test(kpi.textContent)) window.__ovStale.push('a busy KPI that says no flows');
            };
            window.__ovStaleObserver = new MutationObserver(check);
            window.__ovStaleObserver.observe(document.getElementById('page-overview'), { subtree: true, childList: true, attributes: true, characterData: true });
        })()`);

        // V-A11Y: the page in forced colors, for a look.
        await page.setRangePreset('30d');
        await topnSettled(page, 'the top-N back on 30 days');
        const stale = await page.evaluate(`(function(){ window.__ovStaleObserver.disconnect(); return [...new Set(window.__ovStale)]; })()`);
        assert.deepEqual(stale, [], 'the 1 year answer is not shown for 30 days');
        await page.withForcedColors(async () => {
            await sleep(500);
            await page.screenshot('/tmp/nfsen-overview-forced-colors.png');
        });

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors on Overview, got:\n${errors.join('\n')}`);
    });

    // 4.1.4: on a 900 px laptop the first top-N row starts above the fold.
    await withPage(
        async (page) => {
            await page.navigate(BASE + '/#/overview');
            await page.waitForBoot();
            await page.waitForPage('overview');
            await page.setRangePreset('30d');
            await topnSettled(page, 'the top-N for 30 days at 1440x900');
            const fold = await page.evaluate(`(function(){
                var row = document.querySelector('#ovTopnTable tbody tr');
                return { top: row ? row.getBoundingClientRect().top : null, height: innerHeight };
            })()`);
            if (fold.top === null) console.log('  (overview: no top-N rows for 30 days, the fold check did not run)');
            else assert.ok(fold.top < fold.height, `the first top-N row starts at ${fold.top} px, inside ${fold.height} px`);
        },
        { width: 1440, height: 900 }
    );
}

if (import.meta.url === `file://${process.argv[1]}`) {
    overviewTest()
        .then(() => console.log('overview: PASS'))
        .catch((e) => {
            console.error('overview: FAIL\n', e);
            process.exit(1);
        });
}
