// Statistics tab: the Flow Records statistic through an nfdump -A aggregation (#174).
//
// The value of running this for real is that nfdump refuses `-o json` once records are
// aggregated, so the query only works if the processor swapped the format. A mocked
// processor cannot show that; an empty table and a working one look the same from PHP.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const columnsOf = `[...document.querySelectorAll('#statsTable thead th')].map((th) => th.textContent.trim())`;

export default async function statisticsAggregationTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.clickToPanel(`_currentView = 'statistics'`, '$_currentView', 'statistics');
        await page.clickByText('Year', 'button');

        // Only nfdump's record statistic takes an aggregation, so the controls belong to it.
        const visibleFor = async (statistic) => {
            await page.setSelectValue('#statsFilterForSelection', statistic);
            return page.evaluate(
                `(function(){var e=document.getElementById('filterStatsAggregation');return !!e&&e.offsetParent!==null;})()`
            );
        };
        assert.equal(await visibleFor('srcip'), false, 'aggregation controls should be hidden for an element statistic');
        assert.equal(await visibleFor('record'), true, 'aggregation controls should be shown for Flow Records');

        await page.clickByText('Destination', 'label');
        await page.processData();

        const notification = await page.evaluate(`document.getElementById('statsMessage').textContent`);
        assert.match(notification, /-Adstport/, `expected the aggregation in the nfdump command, got: ${notification}`);
        assert.doesNotMatch(notification, /error|warning/i, `expected no error or warning, got: ${notification}`);

        // Aggregating by destination port is what makes the column set collapse onto it:
        // the unaggregated statistic lists a full 5-tuple per row.
        const columns = await page.evaluate(columnsOf);
        assert.ok(columns.length > 0, 'expected the statistics table to have columns');
        assert.ok(
            columns.some((c) => /dstport/i.test(c.replace(/\s/g, ''))),
            `expected a destination port column, got: ${columns.join(', ')}`
        );
        assert.ok(
            !columns.some((c) => /srcaddr|source ip/i.test(c.replace(/\s/g, ''))),
            `expected the source address column to be aggregated away, got: ${columns.join(', ')}`
        );

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    statisticsAggregationTest()
        .then(() => console.log('statistics-aggregation: PASS'))
        .catch((e) => {
            console.error('statistics-aggregation: FAIL\n', e);
            process.exit(1);
        });
}
