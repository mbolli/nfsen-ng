// Graphs tab: codifies the manual verification done for the Dygraphs -> ECharts
// migration as a repeatable regression check -- chart mounts at the real
// container size (not the stale 100px-measured-while-hidden bug that shipped
// once), zoom fires the sync button + graph-zoom wiring date-range.html.twig
// depends on, series visibility/style toggles apply, and dark mode re-themes
// without errors.
//
// Data-dependent assertions (zoom, sync, style toggles) only run if the
// widest available range actually has data -- this dev sandbox's "Traffic"
// (bits/bytes) datatype is frequently empty due to a known, documented,
// pre-existing environment quirk (cross-container inotify not reliably
// firing here, see book/src/architecture/import-pipeline.md's Environment
// caveat), which gaps RRD's derived byte-rate counters more than its Flows/
// Packets counters. A genuinely fresh environment (e.g. CI with no demo
// data at all) would have nothing in *any* datatype. Either way, the test
// must not hard-fail on missing data -- only on the app behaving wrong given
// whatever data is actually there.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

async function selectMostLikelyToHaveData(page) {
    await page.clickByText('Year', 'button');
    for (const label of ['Flows', 'Packets', 'Traffic']) {
        await page.evaluate(`(function(){
            var els = document.querySelectorAll('#filterTypes label');
            var lbl = [...els].find(function(l){ return l.textContent.trim() === ${JSON.stringify(label)}; });
            if (lbl) lbl.click();
        })()`);
        await new Promise((resolve) => setTimeout(resolve, 1500));
        const hasData = await page.evaluate(`!!document.querySelector('nfsen-chart').chart`);
        if (hasData) return label;
    }
    return null;
}

export default async function graphsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitFor(`document.querySelector('nfsen-chart')`, { label: 'chart element to exist' });

        const dataType = await selectMostLikelyToHaveData(page);
        if (!dataType) {
            console.log('  (graphs: no data in any datatype across the widest available range -- verifying empty state only)');
            const emptyText = await page.evaluate(`document.querySelector('.chart-canvas').textContent.trim()`);
            assert.equal(emptyText, 'No data available for the selected range.');
            assert.deepEqual(page.realErrors(), []);
            return;
        }
        console.log(`  (graphs: using '${dataType}' datatype, which has data in this environment)`);

        // Container-sizing regression check: the chart must be measured at its
        // real rendered width, not a stale/undersized snapshot from before the
        // results card finished laying out.
        const sizes = await page.evaluate(`(function(){
            var el = document.querySelector('nfsen-chart');
            var rect = el.querySelector('.chart-canvas').getBoundingClientRect();
            return { chartWidth: el.chart.getWidth(), containerWidth: rect.width };
        })()`);
        assert.ok(sizes.containerWidth > 200, `expected a real container width, got ${sizes.containerWidth}`);
        assert.equal(
            sizes.chartWidth,
            Math.round(sizes.containerWidth),
            `chart-reported width (${sizes.chartWidth}) should match the container's actual width (${sizes.containerWidth})`
        );

        // Zoom -> sync button should enable, data-zoom-* attrs should be set, and
        // getCurrentRange() should reflect the zoomed (not full) range.
        await page.evaluate(`(function(){
            var el = document.querySelector('nfsen-chart');
            var src = el.chart.getOption().dataset[0].source;
            var first = src[0][0], mid = src[Math.floor(src.length / 3)][0];
            var toTs = function(d){ return d instanceof Date ? d.getTime() : d; };
            el.chart.dispatchAction({ type: 'dataZoom', startValue: toTs(first), endValue: toTs(mid) });
        })()`);
        await page.waitFor(`document.querySelector('#date_syncing button.sync-date').disabled === false`, {
            label: 'sync button to enable after zoom',
        });
        const range = await page.evaluate(`document.querySelector('nfsen-chart').getCurrentRange()`);
        assert.ok(range && range.from < range.to, `expected a valid {from,to} range, got ${JSON.stringify(range)}`);

        // "Sync now" should move the date-range slider to match the chart's current range.
        await page.clickByText('Sync now', 'button');
        await page.waitFor(`document.querySelector('#date_syncing button.sync-date').disabled === true`, {
            label: 'sync button to re-disable after Sync now',
        });
        const sliderRange = await page.evaluate(`document.getElementById('dateRangeSlider').slider.noUiSlider.get(true).map(Number)`);
        assert.equal(Math.round(sliderRange[0]), range.from, 'slider start should match the chart range after Sync now');
        assert.equal(Math.round(sliderRange[1]), range.to, 'slider end should match the chart range after Sync now');

        // Sync now moved the date slider, which fires a 'change' event that
        // layout.html.twig throttles to one refreshGraphs POST per second (see
        // its data-on:change__window__throttle.1000ms). Let that settle before
        // touching client-side style toggles below -- otherwise the server's
        // full-option refresh (built from the config captured *before* the
        // toggle below) can land moments after the toggle and stomp it back to
        // its pre-toggle value. Same characteristic existed pre-migration; not
        // something to paper over inside the component itself.
        await new Promise((resolve) => setTimeout(resolve, 1500));

        // Series-display / scale toggles should apply without throwing.
        const chartEl = "document.querySelector('nfsen-chart')";
        await page.evaluate(`document.getElementById('graph_linestacked_stacked').click()`);
        const series0 = await page.evaluate(`${chartEl}.chart.getOption().series[0]`);
        assert.equal(series0.stack, 'total', 'expected stack:"total" after clicking Stacked');
        assert.ok(series0.areaStyle, 'expected areaStyle after clicking Stacked');

        await page.evaluate(`document.getElementById('graph_linlog_log').click()`);
        const yAxisType = await page.evaluate(`${chartEl}.chart.getOption().yAxis[0].type`);
        assert.equal(yAxisType, 'log', 'expected log-scale y-axis after clicking Logarithmic');

        // Reset back to defaults so this test doesn't leave client-local UI state
        // behind for whatever runs next against the same dev server.
        await page.evaluate(`document.getElementById('graph_linestacked_line').click(); document.getElementById('graph_linlog_linear').click();`);

        // Dark mode should re-theme without throwing.
        await page.clickByAttr(`_darkMode = !`);
        await page.waitFor(`document.documentElement.getAttribute('data-bs-theme') === 'dark'`, { label: 'dark theme to apply' });
        await page.clickByAttr(`_darkMode = !`); // leave in light mode for whatever runs next

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
