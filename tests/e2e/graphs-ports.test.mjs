// Graphs tab, Ports display: the failure mode reported in #160 -- the ports view
// dying on a filter change and staying dead until a full page reload.
//
// Two independent regressions are covered here, because either one alone was
// enough to produce the reported "the Graphs tab crashes" behaviour:
//
//  1. graph_ports arriving as something other than a plain array. Port values are
//     the only filter values that parse as JSON scalars, so a scalar "25" used to
//     come out of getTitle()'s parse() as the *number* 25 and throw
//     "displayItems.join is not a function" -- which is why this only ever hit the
//     Ports view. The server now normalizes the signal back to a list of ints, and
//     the client no longer assumes JSON.parse() returns an array.
//
//  2. An error during a chart update leaving a live ECharts instance bound to a
//     container whose contents had already been replaced by the error message.
//     Every later update then took the setOption() path on that orphan and the
//     graph never came back. showMessage() now disposes first, so the next update
//     rebuilds from scratch.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const CHART = "document.querySelector('nfsen-chart')";
const settle = () => new Promise((resolve) => setTimeout(resolve, 2500));

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
}

export default async function graphsPortsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitFor(`${CHART}`, { label: 'chart element to exist' });

        await page.setSelectValue('#filterDisplaySelect', 'ports');
        await settle();

        // Nothing below is meaningful without ports data to draw; this sandbox can
        // legitimately have none (see the note in graphs.test.mjs).
        if (!(await page.evaluate(`!!${CHART}.chart`))) {
            console.log('  (graphs-ports: no ports data in this environment -- skipping)');
            return;
        }

        // 1. A scalar port must not take the view down.
        await pokeSignal(page, 'graph_ports', '25');
        await page.setSelectValue('#filterDisplaySelect', 'ports'); // re-fire the refresh
        await settle();

        assert.ok(await page.evaluate(`!!${CHART}.chart`), 'chart should survive a scalar graph_ports');
        const ports = await page.evaluate(`JSON.parse(${CHART}.dataset.chartConfig).ports`);
        assert.deepEqual(ports, [25], `expected the server to normalize graph_ports back to [25], got ${JSON.stringify(ports)}`);
        assert.match(
            await page.evaluate(`${CHART}.chart.getOption().title[0].text`),
            /port 25$/,
            'expected the title to name the single selected port'
        );

        // 2. After an error wipes the chart, the next update must rebuild it --
        //    without this the graph stayed blank until the user reloaded the page.
        await page.evaluate(`${CHART}.showMessage('simulated failure', 'error')`);
        assert.equal(await page.evaluate(`${CHART}.chart`), null, 'showMessage() should dispose the chart instance');

        await page.setSelectValue('#filterDisplaySelect', 'ports');
        await settle();
        assert.ok(await page.evaluate(`!!${CHART}.chart`), 'chart should rebuild on the next update after an error');
        assert.ok(
            await page.evaluate(`!!document.querySelector('.chart-canvas canvas')`),
            'expected a real canvas back in the container after recovery'
        );

        // Leave the view as we found it for whatever runs next.
        await page.setSelectValue('#filterDisplaySelect', 'sources');
        await settle();

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
