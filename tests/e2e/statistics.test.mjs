// Top Talkers: runs a real nfdump -s query end to end, same reasoning as flows.test.mjs:
// assert on the always-present command line rather than assuming a non-empty result set.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export default async function statisticsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.gotoPage('talkers');

        await page.setRangePreset('1y');
        await page.runQuery('talkers');

        const message = await page.evaluate(`document.getElementById('statsMessage').textContent`);
        assert.match(message, /nfdump:|processed in/, `expected the command that ran in #statsMessage, got: ${message}`);
        assert.doesNotMatch(message, /error/i, `expected no error notice, got: ${message}`);

        const title = await page.evaluate(`document.getElementById('statsResultsTitle').textContent`);
        assert.match(title, /^Flow Records, ordered by \w+ · [\d,]+ rows?$/, `the results title names the statistic, got: ${title}`);

        const rowCount = await page.evaluate(`document.querySelectorAll('#statsTable tbody tr').length`);
        console.log(`  (statistics: query returned ${rowCount} row(s))`);
        assert.equal(await page.evaluate(`document.querySelectorAll('#statsTable').length`), 1, 'one #statsTable in the document');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Top Talkers test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    statisticsTest()
        .then(() => console.log('statistics: PASS'))
        .catch((e) => {
            console.error('statistics: FAIL\n', e);
            process.exit(1);
        });
}
