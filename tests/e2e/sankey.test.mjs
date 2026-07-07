// Sankey tab: runs the real aggregation query end to end and, when it
// returns data, verifies the ECharts sankey series actually got built with
// the expected src:/dst: node-id shape (see SankeyActions::buildSankeyPayload
// and nfsen-sankey.js) -- not just "a chart exists somewhere."
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export default async function sankeyTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.clickByAttr(`_currentView = 'sankey'`);
        await page.waitForPanel('$_currentView', 'sankey');

        await page.clickByText('Year', 'button');
        await page.processData();

        const notificationHtml = await page.evaluate(`document.getElementById('sankeyMessage').textContent`);
        assert.match(
            notificationHtml,
            /nfdump:|processed in/,
            `expected the sankey-actions notification to report the executed nfdump command or timing, got: ${notificationHtml}`
        );
        assert.doesNotMatch(notificationHtml, /error/i, `expected no error notification, got: ${notificationHtml}`);

        const payload = await page.evaluate(`JSON.parse(document.querySelector('nfsen-sankey').dataset.sankeyData)`);
        if (!payload.nodes.length) {
            console.log('  (sankey: no src/dst pairs in this range -- verifying empty state only)');
            const emptyText = await page.evaluate(`document.querySelector('.sankey-canvas').textContent.trim()`);
            assert.equal(emptyText, 'No data available for the selected range.');
        } else {
            console.log(`  (sankey: ${payload.nodes.length} nodes, ${payload.links.length} links)`);
            assert.ok(
                payload.nodes.every((n) => n.name.startsWith('src:') || n.name.startsWith('dst:')),
                'expected every node id to be prefixed src: or dst: (the bipartite-layout convention)'
            );
            assert.ok(
                payload.links.every((l) => l.source.startsWith('src:') && l.target.startsWith('dst:')),
                'expected every link to go from a src: node to a dst: node'
            );
            assert.ok(
                payload.links.every((l) => l.source.slice(4) !== l.target.slice(4)),
                'expected no self-loop pairs (sa === da) to have slipped through'
            );

            const chartOption = await page.evaluate(`document.querySelector('nfsen-sankey').chart.getOption()`);
            assert.equal(chartOption.series[0].type, 'sankey');
            assert.equal(chartOption.series[0].data.length, payload.nodes.length, 'chart should render every node in the payload');
        }

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Sankey test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    sankeyTest()
        .then(() => console.log('sankey: PASS'))
        .catch((e) => {
            console.error('sankey: FAIL\n', e);
            process.exit(1);
        });
}
