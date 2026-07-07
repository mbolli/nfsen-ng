// Statistics tab: runs a real nfdump -s query end to end, same reasoning as
// flows.test.mjs -- assert on the always-present notification rather than
// assuming a non-empty result set.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export default async function statisticsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.clickByAttr(`_currentView = 'statistics'`);
        await page.waitForPanel('$_currentView', 'statistics');

        await page.clickByText('Year', 'button');
        await page.processData();

        const notificationHtml = await page.evaluate(`document.getElementById('statsMessage').textContent`);
        assert.match(
            notificationHtml,
            /nfdump:|processed in/,
            `expected the stats-actions notification to report the executed nfdump command or timing, got: ${notificationHtml}`
        );
        assert.doesNotMatch(notificationHtml, /error/i, `expected no error notification, got: ${notificationHtml}`);

        const rowCount = await page.evaluate(`document.querySelectorAll('#statsTable tbody tr').length`);
        console.log(`  (statistics: query returned ${rowCount} row(s))`);

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Statistics test, got:\n${errors.join('\n')}`);
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
