// Top Talkers: the Flow Records statistic through an nfdump -A aggregation (#174).
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
        await page.waitForBoot();
        await page.gotoPage('talkers');
        await page.setRangePreset('1y');

        // Only nfdump's record statistic takes an aggregation, so the controls belong to it.
        const visibleFor = async (statistic) => {
            await page.setSelectValue('#statsFilterForSelection', statistic);
            return page.evaluate(
                `(function(){var e=document.getElementById('filterStatsAggregation');return !!e&&e.offsetParent!==null;})()`
            );
        };
        assert.equal(await visibleFor('srcip'), false, 'aggregation controls should be hidden for an element statistic');
        assert.equal(await visibleFor('record'), true, 'aggregation controls should be shown for Flow Records');

        // The Direction control has a "Destination" label too, so look inside the aggregation block.
        await page.evaluate(
            `[...document.querySelectorAll('#filterStatsAggregation label')].find((l) => l.textContent.trim() === 'Destination').click()`
        );
        await page.runQuery('talkers');

        const message = await page.evaluate(`document.getElementById('statsMessage').textContent`);
        assert.match(message, /-Adstport/, `expected the aggregation in the nfdump command, got: ${message}`);
        assert.doesNotMatch(message, /error|warning/i, `expected no error or warning, got: ${message}`);

        // Aggregating by destination port is what makes the column set collapse onto it:
        // the unaggregated statistic lists a full 5-tuple per row.
        const columns = await page.evaluate(columnsOf);
        assert.ok(columns.length > 0, 'expected the statistics table to have columns');
        assert.ok(columns.includes('Destination Port'), `expected a destination port column, got: ${columns.join(', ')}`);
        assert.ok(
            !columns.some((c) => /source/i.test(c)),
            `expected the source address column to be aggregated away, got: ${columns.join(', ')}`
        );
        // nfdump answers an aggregated query in csv, whose field names differ from the json
        // ones the unaggregated query returns, so the titles have to cover both (#174).
        assert.ok(
            !columns.some((c) => /^(srcAddr|dstPort|firstSeen|bpp|bps)$/.test(c.replace(/\s/g, ''))),
            `expected column titles, not raw nfdump field names, got: ${columns.join(', ')}`
        );

        // The stale notice follows the -A the run used: a prefix only counts for IPv4/IPv6.
        const STALE = `(function(){ var e = document.querySelector('#statsResults .notice[data-kind="stale"]'); return !!e && e.getClientRects().length > 0; })()`;
        assert.equal(await page.evaluate(STALE), false, 'a fresh run is not stale');
        await page.setSelectValue('#filterStatsAggregation select', 'srcip4');
        await page.waitFor(STALE, { label: 'the stale notice for another aggregation' });
        await page.setInputValue('#filterStatsAggregation .prefix-input', '16');
        await page.setSelectValue('#filterStatsAggregation select', 'none');
        await page.waitFor(`!${STALE}`, { label: 'no stale notice once the -A is the same again, prefix left behind' });
        await page.setSelectValue('#filterStatsAggregation select', 'srcip');
        await page.waitFor(STALE, { label: 'the stale notice for Src: IP' });
        await page.setSelectValue('#filterStatsAggregation select', 'none');
        await page.setInputValue('#filterStatsAggregation .prefix-input', '');
        await page.waitFor(`!${STALE}`, { label: 'the stale notice to go' });

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
