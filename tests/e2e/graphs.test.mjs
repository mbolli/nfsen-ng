// Overview's traffic graph: the chart mounts at the real container size (not the stale
// 100px-measured-while-hidden bug that shipped once), a Ctrl+wheel style zoom shows the
// #zoomPreview whose Apply sets the global window, Sync now and Follow graph zoom do the same
// from the Live menu, Previous range restores the window before, series style toggles apply,
// and dark mode re-themes without errors.
//
// Data-dependent assertions (zoom, sync, style toggles) only run if the widest available range
// actually has data: this sandbox's "Traffic" datatype is often empty because cross-container
// inotify does not fire here (book/src/architecture/import-pipeline.md), and a fresh
// environment has nothing in any datatype. The test fails on wrong behaviour, not on missing data.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const CHART = "document.getElementById('trafficGraph')";

async function selectMostLikelyToHaveData(page) {
    await page.setRangePreset('1y');
    for (const label of ['Flows', 'Packets', 'Traffic']) {
        await page.evaluate(`(function(){
            var els = document.querySelectorAll('#filterTypes label');
            var lbl = [...els].find(function(l){ return l.textContent.trim() === ${JSON.stringify(label)}; });
            if (lbl) lbl.click();
        })()`);
        await new Promise((resolve) => setTimeout(resolve, 1500));
        const hasData = await page.evaluate(`!!${CHART}.chart`);
        if (hasData) return label;
    }
    return null;
}

export default async function graphsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.gotoPage('overview');
        await page.waitFor(CHART, { label: 'chart element to exist' });

        const dataType = await selectMostLikelyToHaveData(page);
        if (!dataType) {
            console.log('  (graphs: no data in any datatype across the widest available range -- verifying empty state only)');
            const emptyText = await page.evaluate(`${CHART}.querySelector('.chart-canvas').textContent.trim()`);
            assert.equal(emptyText, 'No data available for the selected range.');
            assert.deepEqual(page.realErrors(), []);
            return;
        }
        console.log(`  (graphs: using '${dataType}' datatype, which has data in this environment)`);

        // Container-sizing regression check: the chart must be measured at its
        // real rendered width, not a stale/undersized snapshot from before the
        // results card finished laying out.
        const sizes = await page.evaluate(`(function(){
            var el = document.getElementById('trafficGraph');
            var rect = el.querySelector('.chart-canvas').getBoundingClientRect();
            return { chartWidth: el.chart.getWidth(), containerWidth: rect.width };
        })()`);
        assert.ok(sizes.containerWidth > 200, `expected a real container width, got ${sizes.containerWidth}`);
        assert.equal(
            sizes.chartWidth,
            Math.round(sizes.containerWidth),
            `chart-reported width (${sizes.chartWidth}) should match the container's actual width (${sizes.containerWidth})`
        );

        // Zoom the chart the way Ctrl+wheel does, inside the stored data (a range before it is
        // refused): the preview names the zoomed range and its Apply makes it the global window.
        const zoomTo = async (at = [1 / 4, 1 / 2]) => {
            const min = await page.signalValue('data_range_min');
            await page.evaluate(`(function(){
                var el = document.getElementById('trafficGraph');
                var src = el.chart.getOption().dataset[0].source;
                var toTs = function(d){ return d instanceof Date ? d.getTime() : d; };
                var first = Math.max(toTs(src[0][0]), ${min} * 1000), last = toTs(src[src.length - 1][0]);
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
                const [from, to] = [await page.signalValue('datestart'), await page.signalValue('dateend')];
                if (Math.abs(from - range.from / 1000) <= 300 && Math.abs(to - range.to / 1000) <= 300) return;
                if (Date.now() - start > 8000) {
                    throw new Error(`window ${from}..${to} does not match the zoom ${range.from / 1000}..${range.to / 1000}`);
                }
                await new Promise((resolve) => setTimeout(resolve, 150));
            }
        };

        const presetWindow = [await page.signalValue('datestart'), await page.signalValue('dateend')];
        const zoomed = await zoomTo();
        assert.match(await page.evaluate(`document.getElementById('zoomPreview').textContent`), /Previewing .+ to /);
        await page.evaluate(`document.getElementById('zoomApply').click()`);
        await windowMatches(zoomed);
        assert.equal(await page.signalValue('range_live'), false, 'an applied zoom in the past is a fixed window');
        await page.waitFor(`document.getElementById('zoomPreview').hidden`, { label: 'the preview to go once applied' });

        // Previous range brings the live 1y preset back.
        await page.waitFor(`!document.getElementById('rangeUndo').disabled`, { label: '#rangeUndo to enable' });
        await page.evaluate(`document.getElementById('rangeUndo').click()`);
        await page.waitFor(`document.querySelector('#rangeMenu .menu-toggle').textContent.includes('Last year')`, {
            label: 'the 1y preset to return',
        });
        const [undoFrom, undoTo] = [await page.signalValue('datestart'), await page.signalValue('dateend')];
        assert.ok(Math.abs(undoTo - undoFrom - (presetWindow[1] - presetWindow[0])) <= 300, 'Previous range restores the width');
        assert.equal(await page.signalValue('range_live'), true, 'Previous range restores a live window as live');
        await page.waitFor(`!!${CHART}.chart`, { label: 'the chart to redraw' });

        // Reset puts the view back without touching the window.
        await zoomTo();
        await page.evaluate(`document.getElementById('zoomReset').click()`);
        await page.waitFor(`document.getElementById('zoomPreview').hidden`, { label: 'Reset to hide the preview' });
        assert.equal(await page.signalValue('range_live'), true, 'Reset leaves the window alone');

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
        const min = await page.signalValue('data_range_min');
        const followed = await page.evaluate(`(function(){
            var el = document.getElementById('trafficGraph');
            var src = el.chart.getOption().dataset[0].source;
            var toTs = function(d){ return d instanceof Date ? d.getTime() : d; };
            var first = Math.max(toTs(src[0][0]), ${min} * 1000), last = toTs(src[src.length - 1][0]);
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

        // Follow live data off pins the window, so no live tick redraws under the toggles below.
        await page.evaluate(`(function(){
            var toggle = document.querySelector('#liveMenu .menu-toggle');
            if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
            document.getElementById('followLive').click();
        })()`);
        await page.waitFor(`document.querySelector('#trafficGraphSection .badge')?.textContent.includes('HISTORICAL')`, {
            label: 'the HISTORICAL badge once pinned',
        });
        assert.equal(await page.signalValue('range_live'), false, 'Follow live data off pins the window');

        // Series-display / scale toggles should apply without throwing. Each toggle also redraws
        // through a view transition, so the option is awaited rather than read at once.
        const chartEl = CHART;
        await page.evaluate(`document.getElementById('graph_linestacked_stacked').click()`);
        await page.waitFor(`${chartEl}.chart.getOption().series[0].stack === 'total'`, { label: 'stack:"total" after clicking Stacked' });
        assert.ok(await page.evaluate(`!!${chartEl}.chart.getOption().series[0].areaStyle`), 'expected areaStyle after clicking Stacked');

        await page.evaluate(`document.getElementById('graph_linlog_log').click()`);
        await page.waitFor(`${chartEl}.chart.getOption().yAxis[0].type === 'log'`, {
            label: 'a log-scale y-axis after clicking Logarithmic',
        });

        // Reset back to defaults so this test doesn't leave client-local UI state
        // behind for whatever runs next against the same dev server.
        await page.evaluate(
            `document.getElementById('graph_linestacked_line').click(); document.getElementById('graph_linlog_linear').click();`
        );

        // Dark mode should re-theme without throwing.
        await page.chooseTheme('dark');
        await page.waitFor(`document.documentElement.getAttribute('data-theme') === 'dark'`, { label: 'dark theme to apply' });
        await page.chooseTheme('light'); // leave in light mode for whatever runs next

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Graphs test, got:\n${errors.join('\n')}`);
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
