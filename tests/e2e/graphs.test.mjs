// Overview's traffic graph (spec 1.8, 4.1.3, 5.4): the chart mounts at the real container size,
// a Ctrl + wheel zoom shows #zoomPreview whose Apply sets the global window, Sync now and Follow
// graph zoom do the same from the Live menu, a plain wheel scrolls the page and leaves the zoom
// alone, a zoomed preview survives the live tick and new data for the same window, a move keeps
// hidden series and the zoom, a brush sets the range with one post and Previous range restores
// the one before, the style toggles in #graphOptions apply and survive a sync, the picker's
// legend keeps a hidden protocol across new data, the header controls work from the keyboard,
// and dark mode re-themes. The Flows chart keeps its configuration and its name across a sync.
//
// Data-dependent assertions only run if the widest range has data in some datatype: a fresh
// environment has none, and the test fails on wrong behaviour, not on missing data.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const CHART = "document.getElementById('trafficGraph')";
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function press(page, key) {
    const codes = { Enter: 13, Tab: 9, Escape: 27, Space: 32 };
    const name = key === 'Space' ? ' ' : key;
    const base = { key: name, code: key, windowsVirtualKeyCode: codes[key], nativeVirtualKeyCode: codes[key] };
    await page.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...base });
    if (key === 'Enter') await page.send('Input.dispatchKeyEvent', { type: 'char', text: '\r', ...base });
    if (key === 'Space') await page.send('Input.dispatchKeyEvent', { type: 'char', text: ' ', ...base });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

async function selectMostLikelyToHaveData(page) {
    await page.setRangePreset('1y');
    for (const label of ['Flows', 'Packets', 'Traffic']) {
        await page.evaluate(`(function(){
            var lbl = [...document.querySelectorAll('#filterTypes label')].find(function(l){ return l.textContent.trim() === ${JSON.stringify(label)}; });
            if (lbl) lbl.click();
        })()`);
        try {
            await page.waitFor(`${CHART}.dataset.chartConfig?.includes('"type":"${label.toLowerCase()}"') && !!${CHART}.chart`, {
                timeout: 5000,
                label: `${label} drawn`,
            });
            return label;
        } catch {
            // no data in this datatype
        }
    }
    return null;
}

/** The global window, read in one go so a patch cannot tear it. */
async function currentWindow(page) {
    return page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        var get = function(n){ return root[Object.keys(root).find(function(k){ return k.startsWith(n + '____'); })]; };
        return { from: get('datestart'), to: get('dateend'), live: get('range_live'), min: get('data_range_min') };
    })()`);
}

export default async function graphsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.gotoPage('overview');
        await page.waitFor(CHART, { label: 'chart element to exist' });
        const log = await page.requestLog();

        // The style toggles and the datatype live in #graphOptions, opened by #graphOptionsToggle.
        await page.evaluate(`document.getElementById('graph_linestacked_line').click(); document.getElementById('graph_linlog_linear').click()`);
        if (await page.evaluate(`document.getElementById('graphOptions').hidden`)) {
            await page.evaluate(`document.getElementById('graphOptionsToggle').click()`);
        }
        await page.waitFor(`!document.getElementById('graphOptions').hidden`, { label: '#graphOptions to open' });

        // More than eight series (2.3), on a chart of its own so it needs no stored data: stacked
        // sums series 9 and later into Others, line repeats the slots dashed and dotted and names
        // the first eight at the right edge, and the Series panel lists every series.
        const many = await page.evaluate(`(async function(){
            var host = document.createElement('div');
            host.innerHTML = '<nfsen-chart id="e2eMany" data-external-prefix="e2eMany" data-mode="overview"><div class="chart-container"><div class="chart-canvas"></div></div></nfsen-chart><div id="e2eMany-series"></div><div id="e2eMany-legend"></div>';
            host.querySelector('.chart-canvas').style.blockSize = '300px';
            document.getElementById('page-overview').append(host);
            var el = host.querySelector('nfsen-chart');
            var n = 26, legend = [], data = {};
            for (var i = 0; i < n; i++) legend.push((i + 1) + '_bits');
            for (var t = 0; t < 20; t++) data[1790000000 + t * 300] = legend.map(function(_, i){ return i + 1; });
            el.setAttribute('data-chart-style', JSON.stringify({ stacked: true }));
            el.setAttribute('data-chart-config', JSON.stringify({ mode: 'overview', display: 'ports', unit: 'bits', seriesSlots: legend.map(function(_, i){ return i + 1; }), seriesNames: legend.map(function(_, i){ return String(i + 1); }) }));
            el.setAttribute('data-chart-data', JSON.stringify({ start: 1790000000, end: 1790006000, step: 300, legend: legend, data: data }));
            for (var k = 0; k < 50 && !el.chart; k++) await new Promise(function(r){ setTimeout(r, 100); });
            var wait = function(){ return new Promise(function(r){ setTimeout(r, 300); }); };
            await wait();
            var o = el.chart.getOption();
            var out = { stackedSeries: o.series.length, last: o.series[o.series.length - 1].name, others: o.dataset[0].source[0][n + 1],
                listed: document.querySelectorAll('#e2eMany-series li').length, neutral: document.querySelectorAll('#e2eMany-series .series-swatch[data-series="others"]').length };
            document.querySelectorAll('#e2eMany-series input')[9].click();
            await wait();
            out.othersWithoutTen = el.chart.getOption().dataset[0].source[0][n + 1];
            el.setAttribute('data-chart-style', JSON.stringify({ stacked: false }));
            await wait();
            o = el.chart.getOption();
            out.lineSeries = o.series.length;
            out.patterns = [o.series[0].lineStyle.type, o.series[8].lineStyle.type, o.series[16].lineStyle.type];
            out.endLabels = o.series.filter(function(s){ return s.endLabel && s.endLabel.show; }).length;
            out.swatch = document.querySelectorAll('#e2eMany-series .series-swatch')[8].dataset.pattern;

            // A move keeps the state (K14): an atomic move, and a remove and insert, which runs
            // cleanup and setup again.
            out.rocket = el.rocketInstanceId !== undefined;
            var instance = el.chart;
            el.setVisibility(0, false);
            el.chart.dispatchAction({ type: 'dataZoom', startValue: 1790000000000 + 5 * 300000, endValue: 1790000000000 + 12 * 300000 });
            await wait();
            var kept = function(){
                return { hidden: el.chart.getOption().legend[0].selected['1'] === false, zoomed: el.isZoomed(), range: el.getCurrentRange(), same: el.chart === instance };
            };
            out.before = kept();
            el.parentNode.moveBefore(el, el.nextSibling);
            await wait();
            out.moved = kept();
            el.parentNode.insertBefore(el, el.nextSibling);
            await wait();
            out.reinserted = kept();
            host.remove();
            await wait();
            out.gone = { chart: el.chart, children: el.childElementCount };
            return out;
        })()`);
        const { rocket, before, moved, reinserted, gone, ...counts } = many;
        assert.equal(rocket, true, 'nfsen-chart is a Rocket host');
        assert.deepEqual(before, { hidden: true, zoomed: true, range: { from: 1790001500000, to: 1790003600000 }, same: true });
        assert.deepEqual(moved, before, 'moveBefore keeps the hidden series, the zoom and the instance');
        assert.deepEqual(reinserted, before, 'a remove and insert keeps the hidden series, the zoom and the instance');
        assert.deepEqual(gone, { chart: null, children: 0 }, 'a removed chart lets its instance go and empties itself');
        assert.deepEqual(counts, {
            stackedSeries: 9,
            last: 'Others',
            others: 315,
            listed: 26,
            neutral: 18,
            othersWithoutTen: 305,
            lineSeries: 26,
            patterns: ['solid', 'dashed', 'dotted'],
            endLabels: 8,
            swatch: 'dashed',
        });

        const dataType = await selectMostLikelyToHaveData(page);
        if (!dataType) {
            console.log('  (graphs: no data in any datatype across the widest available range -- verifying empty state only)');
            const emptyText = await page.evaluate(`${CHART}.querySelector('.chart-canvas').textContent.trim()`);
            assert.equal(emptyText, 'No data available for the selected range.');
            assert.deepEqual(page.realErrors(), []);
            return;
        }
        console.log(`  (graphs: using '${dataType}' datatype, which has data in this environment)`);

        // Container-sizing regression check: the chart is measured at its real rendered width.
        const sizes = await page.evaluate(`(function(){
            var el = document.getElementById('trafficGraph');
            var rect = el.querySelector('.chart-canvas').getBoundingClientRect();
            return { chartWidth: el.chart.getWidth(), containerWidth: rect.width };
        })()`);
        assert.ok(sizes.containerWidth > 200, `expected a real container width, got ${sizes.containerWidth}`);
        assert.equal(sizes.chartWidth, Math.round(sizes.containerWidth), `chart width ${sizes.chartWidth} vs container ${sizes.containerWidth}`);
        // No slider under the plot any more, and a transparent background on the card (2.4).
        const option = await page.evaluate(`(function(){ var o = ${CHART}.chart.getOption(); return { zooms: o.dataZoom.map(function(z){ return z.type; }), bg: o.backgroundColor, aria: [].concat(o.aria)[0].enabled }; })()`);
        assert.deepEqual(option, { zooms: ['inside'], bg: 'transparent', aria: true });

        // Zoom the chart the way Ctrl + wheel does: the preview names the range, Apply makes it the window.
        const zoomTo = async (at = [1 / 4, 1 / 2]) => {
            const { min } = await currentWindow(page);
            await page.evaluate(`(function(){
                var el = document.getElementById('trafficGraph');
                var src = el.chart.getOption().dataset[0].source;
                var first = Math.max(src[0][0], ${min} * 1000), last = src[src.length - 1][0];
                el.chart.dispatchAction({ type: 'dataZoom', startValue: first + (last - first) * ${at[0]}, endValue: first + (last - first) * ${at[1]} });
            })()`);
            await page.waitFor(`!document.getElementById('zoomPreview').hidden`, { label: '#zoomPreview after a zoom' });
            const range = await page.evaluate(`${CHART}.getCurrentRange()`);
            assert.ok(range && range.from < range.to, `expected a valid {from,to} range, got ${JSON.stringify(range)}`);
            return range;
        };
        const windowMatches = async (range) => {
            const start = Date.now();
            for (;;) {
                const w = await currentWindow(page);
                if (Math.abs(w.from - range.from / 1000) <= 300 && Math.abs(w.to - range.to / 1000) <= 300) return;
                if (Date.now() - start > 8000) {
                    throw new Error(`window ${w.from}..${w.to} does not match the zoom ${range.from / 1000}..${range.to / 1000}`);
                }
                await sleep(150);
            }
        };

        const preset = await currentWindow(page);
        const zoomed = await zoomTo();
        assert.match(await page.evaluate(`document.getElementById('zoomPreview').textContent`), /Previewing .+ to /);
        await page.evaluate(`document.getElementById('zoomApply').click()`);
        await windowMatches(zoomed);
        assert.equal((await currentWindow(page)).live, false, 'an applied zoom in the past is a fixed window');
        await page.waitFor(`document.getElementById('zoomPreview').hidden`, { label: 'the preview to go once applied' });

        // Previous range brings the live 1y preset back.
        await page.waitFor(`!document.getElementById('rangeUndo').disabled`, { label: '#rangeUndo to enable' });
        await page.evaluate(`document.getElementById('rangeUndo').click()`);
        await page.waitFor(`document.querySelector('#rangeMenu .menu-toggle').textContent.includes('Last year')`, { label: 'the 1y preset to return' });
        const undone = await currentWindow(page);
        assert.ok(Math.abs(undone.to - undone.from - (preset.to - preset.from)) <= 300, 'Previous range restores the width');
        assert.equal(undone.live, true, 'Previous range restores a live window as live');
        await page.waitFor(`!!${CHART}.chart`, { label: 'the chart to redraw' });

        // The live tick (15 s) advances the window and fetches again; the preview stays (D6, 1.8).
        const kept = await zoomTo();
        const tickedFrom = (await currentWindow(page)).to;
        for (let i = 0; i < 100 && (await currentWindow(page)).to === tickedFrom; i++) await sleep(250);
        assert.ok((await currentWindow(page)).to > tickedFrom, 'the live tick advanced the window');
        await sleep(1000);
        assert.equal(await page.evaluate(`document.getElementById('zoomPreview').hidden`), false, 'the preview survives the live tick');
        assert.deepEqual(await page.evaluate(`${CHART}.getCurrentRange()`), kept, 'the zoom survives the live tick');
        // New data for the same window keeps a zoom that lies inside it, and reports it again.
        const reported = await page.evaluate(`(async function(){
            var el = ${CHART};
            var seen = null;
            var on = function(e){ seen = { zoomed: el.isZoomed(), from: e.detail.from, to: e.detail.to }; };
            window.addEventListener('graph-zoom', on);
            var d = JSON.parse(el.dataset.chartData);
            var ts = Object.keys(d.data).map(Number).sort(function(a, b){ return a - b; });
            d.data[ts[ts.length - 1] + (d.step || 300)] = d.data[ts[ts.length - 1]];
            el.setAttribute('data-chart-data', JSON.stringify(d));
            for (var i = 0; i < 40 && !seen; i++) await new Promise(function(r){ setTimeout(r, 50); });
            window.removeEventListener('graph-zoom', on);
            return seen;
        })()`);
        assert.deepEqual(reported, { zoomed: true, ...kept }, 'new data for the same window keeps the zoom and reports it');
        assert.equal(await page.evaluate(`document.getElementById('zoomPreview').hidden`), false, 'the preview survives new data');

        // Reset puts the view back without touching the window.
        await page.evaluate(`document.getElementById('zoomReset').click()`);
        await page.waitFor(`document.getElementById('zoomPreview').hidden`, { label: 'Reset to hide the preview' });
        assert.equal((await currentWindow(page)).live, true, 'Reset leaves the window alone');

        // A plain wheel over the chart scrolls the page, shows the hint and leaves the zoom alone (D6).
        await page.evaluate(`document.body.style.paddingBlockEnd = '200vh'; ${CHART}.scrollIntoView({ block: 'start' })`);
        await sleep(300);
        const scrollBefore = await page.evaluate('window.scrollY');
        const center = await page.evaluate(`(function(){ var r = ${CHART}.querySelector('.chart-canvas').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`);
        await page.send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: center.x, y: center.y, deltaX: 0, deltaY: 240 });
        await sleep(500);
        assert.ok((await page.evaluate('window.scrollY')) > scrollBefore, 'a plain wheel over the chart scrolls the page');
        assert.equal(await page.evaluate(`${CHART}.isZoomed()`), false, 'a plain wheel does not zoom');
        assert.equal(await page.evaluate(`!document.querySelector('#trafficGraph .chart-hint').hidden`), true, 'the "Hold Ctrl to zoom" hint shows');
        await page.evaluate(`document.body.style.paddingBlockEnd = ''; window.scrollTo(0, 0)`);
        await sleep(300);

        // A move keeps a hidden series and the zoom (6.9, K14): an atomic move, then a remove and insert,
        // which runs cleanup and setup again. A lone series stays visible, or there is nothing to zoom.
        const names = await page.evaluate(`${CHART}.chart.getOption().series.map(function(s){ return s.name; })`);
        const hide = names.length > 1 ? names.length - 1 : -1;
        if (hide >= 0) await page.evaluate(`${CHART}.setVisibility(${hide}, false)`);
        const moveZoom = await zoomTo();
        const keptView = `(function(){
            var el = ${CHART};
            return { hidden: ${hide} < 0 || el.chart.getOption().legend[0].selected[${JSON.stringify(names[hide] ?? '')}] === false, zoomed: el.isZoomed(), range: el.getCurrentRange() };
        })()`;
        for (const move of ['moveBefore', 'insertBefore']) {
            await page.evaluate(`(function(){ var el = ${CHART}; el.parentNode.${move}(el, el.nextSibling); })()`);
            await sleep(300);
            assert.deepEqual(await page.evaluate(keptView), { hidden: true, zoomed: true, range: moveZoom }, `${move} keeps the hidden series and the zoom`);
        }
        if (hide >= 0) await page.evaluate(`${CHART}.setVisibility(${hide}, true)`);
        await page.evaluate(`document.getElementById('zoomReset').click()`);
        await page.waitFor(`document.getElementById('zoomPreview').hidden`, { label: 'Reset after the move' });

        // A brush (dispatched as ECharts reports one) sets the range on 5 minute boundaries, with
        // one post: the host's range-select handler is bound once, also after the move above.
        const brushTarget = await page.evaluate(`(function(){
            var src = ${CHART}.chart.getOption().dataset[0].source;
            var first = Math.max(src[0][0], ${(await currentWindow(page)).min} * 1000), last = src[src.length - 1][0];
            return [first + (last - first) * 0.6, first + (last - first) * 0.8];
        })()`);
        log.clear();
        await page.evaluate(`${CHART}.chart.dispatchAction({ type: 'brush', areas: [{ brushType: 'lineX', xAxisIndex: 0, coordRange: ${JSON.stringify(brushTarget)} }] })`);
        await page.evaluate(`${CHART}.chart.dispatchAction({ type: 'brushEnd', areas: [{ brushType: 'lineX', xAxisIndex: 0, coordRange: ${JSON.stringify(brushTarget)} }] })`);
        await windowMatches({ from: Math.floor(brushTarget[0] / 300000) * 300000, to: Math.ceil(brushTarget[1] / 300000) * 300000 });
        await sleep(1000);
        assert.equal(log.count('set-range'), 1, `one brush posts set-range once, got ${log.names().join(', ')}`);
        const brushed = await currentWindow(page);
        assert.equal(brushed.live, false, 'a brushed range is fixed');
        assert.equal(brushed.from % 300, 0, 'the brushed start is on a 5 minute boundary');
        await page.evaluate(`document.getElementById('rangeUndo').click()`);
        await page.waitFor(`document.querySelector('#rangeMenu .menu-toggle').textContent.includes('Last year')`, { label: 'Previous range after a brush' });
        await page.waitFor(`!!${CHART}.chart`, { label: 'the chart to redraw' });

        // Sync zoom to range now, from the Live menu.
        const synced = await zoomTo();
        await page.evaluate(`document.querySelector('#liveMenu .menu-toggle').click()`);
        await page.waitFor(`!document.getElementById('syncZoom').disabled`, { label: '#syncZoom to enable' });
        await page.evaluate(`document.getElementById('syncZoom').click()`);
        await windowMatches(synced);

        // Follow graph zoom applies a zoom by itself, and the preview stays away.
        await page.setRangePreset('1y');
        await page.waitFor(`!!${CHART}.chart`, { label: 'the chart to redraw' });
        await page.evaluate(`(function(){
            var toggle = document.querySelector('#liveMenu .menu-toggle');
            if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
            document.getElementById('followZoom').click();
        })()`);
        assert.equal(await page.signalValue('_autoSyncGraph'), true, 'Follow graph zoom is on');
        const { min } = await currentWindow(page);
        const followed = await page.evaluate(`(function(){
            var el = document.getElementById('trafficGraph');
            var src = el.chart.getOption().dataset[0].source;
            var first = Math.max(src[0][0], ${min} * 1000), last = src[src.length - 1][0];
            el.chart.dispatchAction({ type: 'dataZoom', startValue: first + (last - first) / 3, endValue: first + (last - first) / 2 });
            return el.getCurrentRange();
        })()`);
        assert.equal(await page.evaluate(`document.getElementById('zoomPreview').hidden`), true, 'no preview while following');
        await windowMatches(followed);
        await page.evaluate(`(function(){
            var toggle = document.querySelector('#liveMenu .menu-toggle');
            if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
            document.getElementById('followZoom').click();
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        })()`);
        assert.equal(await page.signalValue('_autoSyncGraph'), false, 'Follow graph zoom is off again');
        await page.setRangePreset('1y');
        await page.waitFor(`!!${CHART}.chart`, { label: 'the chart to redraw' });

        // V-A11Y: the Live menu by keyboard. Enter opens it, Tab reaches Follow live data, Space
        // pins the window, Escape closes and returns focus, Tab leaves the closed menu behind.
        await page.evaluate(`document.querySelector('#liveMenu .menu-toggle').focus()`);
        await press(page, 'Enter');
        await page.waitFor(`document.querySelector('#liveMenu .menu-toggle').getAttribute('aria-expanded') === 'true'`, { label: 'Enter to open the Live menu' });
        await press(page, 'Tab');
        assert.equal(await page.evaluate(`document.activeElement?.id`), 'followLive', 'Tab moves into the Live menu');
        await press(page, 'Space');
        await page.waitFor(`document.querySelector('#trafficGraphSection .badge')?.textContent.includes('HISTORICAL')`, { label: 'the HISTORICAL badge once pinned' });
        assert.equal(await page.signalValue('range_live'), false, 'Follow live data off pins the window');
        await page.withForcedColors(async () => {
            await sleep(300);
            await page.screenshot('/tmp/nfsen-graph-header-forced-colors.png');
        });
        await press(page, 'Escape');
        await page.waitFor(`document.activeElement === document.querySelector('#liveMenu .menu-toggle')`, { label: 'Escape to return focus to the Live toggle' });
        assert.equal(await page.evaluate(`document.querySelector('#liveMenu .menu-toggle').getAttribute('aria-expanded')`), 'false');
        // Zoom out is disabled while the window already holds all the data; then Tab goes on to the Options.
        const next = (await page.evaluate(`document.getElementById('zoomOut').disabled`)) ? 'filterDisplaySelect' : 'zoomOut';
        await press(page, 'Tab');
        assert.equal(await page.evaluate(`document.activeElement?.id`), next, `Tab goes on to ${next}, not into the closed menu`);

        // Style toggles apply without throwing. Each redraws through a view transition, so the
        // option is awaited rather than read at once.
        await page.evaluate(`document.getElementById('graph_linestacked_stacked').click()`);
        await page.waitFor(`${CHART}.chart.getOption().series[0].stack === 'total'`, { label: 'stack:"total" after clicking Stacked' });
        assert.ok(await page.evaluate(`!!${CHART}.chart.getOption().series[0].areaStyle`), 'expected areaStyle after clicking Stacked');
        await page.evaluate(`document.getElementById('graph_linlog_log').click()`);
        await page.waitFor(`${CHART}.chart.getOption().yAxis[0].type === 'log'`, { label: 'a log-scale y-axis after clicking Log' });
        await page.evaluate(`document.getElementById('graph_lineplot_curve').click()`);
        await page.waitFor(`${CHART}.chart.getOption().series[0].step === false`, { label: 'no step after clicking Curve' });

        // A sync keeps them (K4): the host preserves data-chart-style, which data-attr set, so the
        // morph neither drops it nor draws the chart linear again. The marker goes with the morph.
        await page.evaluate(`document.getElementById('trafficGraphTitle').setAttribute('data-e2e-sync', '')`);
        log.clear();
        await page.evaluate(`document.getElementById('filterDisplaySelect').dispatchEvent(new Event('change', { bubbles: true }))`);
        await page.waitFor(`!document.getElementById('trafficGraphTitle').hasAttribute('data-e2e-sync')`, { timeout: 10000, label: 'a sync of the graph' });
        assert.ok(log.count('refresh-graphs') >= 1, 'the sync came from refresh-graphs');
        await sleep(500);
        const afterSync = await page.evaluate(`(function(){
            var el = ${CHART}, o = el.chart.getOption();
            return { style: JSON.parse(el.getAttribute('data-chart-style') || 'null'), y: o.yAxis[0].type, stack: o.series[0].stack, step: o.series[0].step,
                     named: / to .*(peak|no data)/.test(el.getAttribute('aria-label') || '') };
        })()`);
        assert.deepEqual(afterSync, { style: { logscale: true, stacked: true, stepplot: false }, y: 'log', stack: 'total', step: false, named: true });

        // The Series panel toggles a series with a listener of its own (Datastar skips the panel), by
        // keyboard too (V-A11Y).
        const firstName = await page.evaluate(`document.querySelector('#trafficGraph-series .series-name')?.textContent`);
        const selectedFirst = `${CHART}.chart.getOption().legend[0].selected[${JSON.stringify(firstName)}]`;
        await page.evaluate(`document.querySelector('#trafficGraph-series input[type=checkbox]').focus()`);
        await press(page, 'Space');
        await page.waitFor(`${selectedFirst} === false`, { label: 'Space in the panel to hide the series' });
        await press(page, 'Space');
        await page.waitFor(`${selectedFirst} === true`, { label: 'Space again to show it' });

        // Back to the defaults for whatever runs next.
        await page.evaluate(
            `document.getElementById('graph_linestacked_line').click(); document.getElementById('graph_linlog_linear').click(); document.getElementById('graph_lineplot_step').click(); document.getElementById('dataTypeTraffic').click(); document.getElementById('graphOptionsToggle').click()`
        );
        await page.evaluate(`document.getElementById('followLive').click()`);

        // The picker (Top Talkers): a protocol hidden from the built-in legend stays hidden when
        // new data is drawn, and the accessible peak leaves it out.
        await page.gotoPage('talkers');
        await page.waitFor(`${CHART}.dataset.mode === 'picker' && ${CHART}.chart?.getOption().legend[0].show === true`, { label: 'the picker legend on Top Talkers' });
        const picker = await page.evaluate(`(async function(){
            var el = ${CHART};
            var wait = function(){ return new Promise(function(r){ setTimeout(r, 300); }); };
            var peak = function(){ return (el.getAttribute('aria-label').match(/peak (.*) at/) || [])[1] || null; };
            var before = peak();
            el.chart.dispatchAction({ type: 'legendToggleSelect', name: 'TCP' });
            await wait();
            var hidden = peak();
            var d = JSON.parse(el.dataset.chartData);
            var ts = Object.keys(d.data).map(Number).sort(function(a, b){ return a - b; });
            d.data[ts[ts.length - 1] + (d.step || 300)] = d.data[ts[ts.length - 1]];
            el.setAttribute('data-chart-data', JSON.stringify(d));
            await wait();
            var out = { names: el.chart.getOption().series.map(function(s){ return s.name; }), tcpAfterNewData: el.chart.getOption().legend[0].selected.TCP, peakChanged: before !== hidden, peakKept: peak() === hidden };
            el.chart.dispatchAction({ type: 'legendToggleSelect', name: 'TCP' });
            return out;
        })()`);
        assert.deepEqual(picker.names, ['TCP', 'UDP', 'ICMP', 'Other'], 'the picker stacks the four protocols');
        assert.equal(picker.tcpAfterNewData, false, 'TCP stays hidden when new data is drawn');
        assert.equal(picker.peakKept, true, 'the accessible peak still leaves TCP out');
        if (!picker.peakChanged) console.log('  (graphs: hiding TCP did not change the peak here)');
        await page.gotoPage('overview');
        await page.waitFor(`${CHART}.dataset.mode === 'overview'`, { label: 'the Overview graph again' });

        // Dark mode re-themes: the chart keeps a transparent background on the dark card.
        await page.chooseTheme('dark');
        await page.waitFor(`document.documentElement.getAttribute('data-theme') === 'dark'`, { label: 'dark theme to apply' });
        await sleep(300);
        assert.equal(await page.evaluate(`${CHART}.chart?.getOption().backgroundColor ?? 'transparent'`), 'transparent');
        await page.chooseTheme('light'); // leave in light mode for whatever runs next

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Graphs test, got:\n${errors.join('\n')}`);
    });

    // The Flows "Traffic over time" chart (6.9): a Rocket host with a role and a name of its own, and a
    // configuration from data-attr that a sync keeps (K4), the display timezone chosen on the client included.
    await withPage(async (page) => {
        // A browser zone apart from the server's, so the two display timezones draw different labels.
        await page.send('Emulation.setTimezoneOverride', { timezoneId: 'Pacific/Auckland' });
        await page.navigate(BASE + '/#/flows');
        await page.waitForBoot();
        await page.waitForPage('flows');
        // One day keeps the build to a few seconds.
        await page.setRangePreset('24h');
        await page.evaluate(`(function(){ var b = document.querySelector('#flowsGraph .flows-disclosure'); if (b.getAttribute('aria-expanded') !== 'true') b.click(); })()`);
        await page.waitFor(`!!document.querySelector('button[data-run="flows-graph"]:not(:disabled)')`, { timeout: 30000, label: 'the Build graph button' });
        await page.runQuery('flows-graph', { timeout: 120000 });

        const FLOWS = "document.querySelector('#flowsGraph nfsen-chart')";
        const flowsChart = `(function(){
            var el = ${FLOWS};
            return { role: el.getAttribute('role'), label: el.getAttribute('aria-label'), config: JSON.parse(el.getAttribute('data-chart-config') || 'null'),
                     y: el.chart ? el.chart.getOption().yAxis[0].name : null };
        })()`;
        await page.waitFor(`${FLOWS}?.rocketInstanceId !== undefined && /^Traffic over time, /.test(${FLOWS}.getAttribute('aria-label') || '')`, {
            timeout: 15000,
            label: 'the Flows chart to draw and name itself',
        });
        const first = await page.evaluate(flowsChart);
        assert.equal(first.role, 'img');
        assert.match(first.label, /^Traffic over time, (.+ to .+|no data in this range)/);
        assert.ok(first.config?.displayTz, `the configuration came from data-attr: ${JSON.stringify(first.config)}`);

        const next = first.config.displayTz === 'server' ? 'browser' : 'server';
        await page.evaluate(`(async function(){
            var root = (await import('datastar')).root;
            root[Object.keys(root).find(function(k){ return k.startsWith('displayTz____'); })] = ${JSON.stringify(next)};
        })()`);
        await page.waitFor(`JSON.parse(${FLOWS}.getAttribute('data-chart-config')).displayTz === ${JSON.stringify(next)}`, { label: 'the other display timezone' });
        await sleep(800);
        const chosen = await page.evaluate(flowsChart);
        if (/ to /.test(first.label)) assert.notEqual(chosen.label, first.label, 'the name follows the display timezone');

        await page.evaluate(`document.getElementById('flowsGraphTitle').setAttribute('data-e2e-sync', '')`);
        await page.evaluate(`document.querySelector('input[name=flowsGraphUnit]:checked').dispatchEvent(new Event('change', { bubbles: true }))`);
        await page.waitFor(`!document.getElementById('flowsGraphTitle').hasAttribute('data-e2e-sync')`, { timeout: 10000, label: 'a sync of the Flows page' });
        await sleep(800);
        assert.deepEqual(await page.evaluate(flowsChart), chosen, 'a sync keeps the configuration, its timezone and the name');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors with the Flows chart, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    graphsTest()
        .then(() => console.log('graphs: PASS'))
        .catch((e) => {
            console.error('graphs: FAIL\n', e);
            process.exit(1);
        });
}
