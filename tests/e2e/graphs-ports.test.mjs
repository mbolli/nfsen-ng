// Overview graph, Ports display: the failure mode reported in #160, the ports view dying on a
// filter change and staying dead until a full page reload.
//
// Two independent regressions are covered here, because either one alone was enough to produce
// the reported "the Graphs tab crashes" behaviour:
//
//  1. graph_ports arriving as something other than a plain array: a <select>'s values are
//     strings, and a scalar "25" once took the whole ports view down. The server normalises the
//     signal back to a list of ints.
//
//  2. An error during a chart update leaving a live ECharts instance bound to a container whose
//     contents had already been replaced by the error message. showMessage() disposes first,
//     so the next update rebuilds from scratch.
//
// The ports are read from the page (the dev stack configures NFSEN_PORTS), and every switch is
// waited for: a change posted before the SSE stream is up is otherwise lost.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const CHART = "document.getElementById('trafficGraph')";
const config = `JSON.parse(${CHART}.dataset.chartConfig || '{}')`;

/** Merge a raw value into a context-scoped signal the way a stray client write would. */
async function pokeSignal(page, name, value) {
    const ok = await page.evaluate(`(function(){
        var m = document.documentElement.outerHTML.match(new RegExp(${JSON.stringify(name)} + '____[a-z0-9]+'));
        if (!m) return false;
        var d = document.createElement('div');
        var o = {}; o[m[0]] = ${JSON.stringify(value)};
        d.setAttribute('data-signals', JSON.stringify(o));
        document.body.appendChild(d);
        return true;
    })()`);
    if (!ok) throw new Error('signal id not found in page: ' + name);
    // Datastar applies the new data-signals element on its next mutation pass.
    const start = Date.now();
    while (JSON.stringify(await page.signalValue(name)) !== JSON.stringify(value)) {
        if (Date.now() - start > 3000) throw new Error(`${name} never took the poked value`);
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
}

/** Switch the display and wait until the chart's configuration says so. */
async function showDisplay(page, display) {
    await page.setSelectValue('#filterDisplaySelect', display);
    await page.waitFor(`${config}.display === ${JSON.stringify(display)} && ${CHART}.dataset.mode === 'overview'`, {
        timeout: 10000,
        label: `the graph to show ${display}`,
    });
}

export default async function graphsPortsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.gotoPage('overview');
        await page.waitFor(`!!${CHART}.dataset.chartData`, { label: 'the graph data' });
        // A week keeps rrd_xport well above its minimum row count.
        await page.setRangePreset('7d');

        const ports = await page.evaluate(`[...document.querySelectorAll('#filterPortsSelect option')].map(function(o){ return Number(o.value); })`);
        assert.ok(ports.length > 0 && ports.every((p) => p > 0), `expected the configured ports, got ${JSON.stringify(ports)}`);
        await showDisplay(page, 'ports');
        assert.match(await page.evaluate(`document.getElementById('trafficGraphTitle').textContent`), / by port$/);
        assert.equal(await page.evaluate(`!document.getElementById('filterPorts').hidden`), true, 'the Ports select shows for the Ports display');

        // Nothing below is meaningful without ports data to draw; a fresh environment has none.
        if (!(await page.evaluate(`!!${CHART}.chart`))) {
            console.log('  (graphs-ports: no ports data in this environment -- skipping)');
            await showDisplay(page, 'sources');
            return;
        }

        // Each configured port is its own series, coloured in configured order (2.3).
        const drawn = await page.evaluate(`${config}.seriesNames`);
        const slots = await page.evaluate(`${config}.seriesSlots`);
        for (const [i, name] of drawn.entries()) {
            assert.equal(slots[i], ports.indexOf(Number(name)) + 1, `port ${name} keeps its configured slot`);
        }

        // 1. A scalar port must not take the view down.
        const port = ports[0];
        await pokeSignal(page, 'graph_ports', String(port));
        await page.setSelectValue('#filterDisplaySelect', 'ports'); // re-fire the refresh
        await page.waitFor(`${config}.seriesNames?.length === 1`, { timeout: 10000, label: 'one series for one port' });

        assert.ok(await page.evaluate(`!!${CHART}.chart`), 'chart should survive a scalar graph_ports');
        assert.deepEqual(await page.signalValue('graph_ports'), [port], `the server normalizes graph_ports back to [${port}]`);
        assert.deepEqual(await page.evaluate(`${config}.seriesNames`), [String(port)], 'the one series is named after the port');

        // 2. After an error wipes the chart, the next update must rebuild it.
        await page.evaluate(`${CHART}.showMessage('simulated failure')`);
        assert.equal(await page.evaluate(`${CHART}.chart`), null, 'showMessage() should dispose the chart instance');

        await pokeSignal(page, 'graph_ports', ports);
        await page.setSelectValue('#filterDisplaySelect', 'ports');
        await page.waitFor(`!!${CHART}.chart`, { timeout: 10000, label: 'the chart to rebuild after an error' });
        assert.ok(await page.evaluate(`!!${CHART}.querySelector('.chart-canvas canvas')`), 'expected a real canvas back in the container after recovery');

        // Leave the view as we found it for whatever runs next.
        await showDisplay(page, 'sources');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Ports test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    graphsPortsTest()
        .then(() => console.log('graphs-ports: PASS'))
        .catch((e) => {
            console.error('graphs-ports: FAIL\n', e);
            process.exit(1);
        });
}
