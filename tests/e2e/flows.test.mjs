// Flows tab: runs a real nfdump query end to end (client click -> POST
// /_action/flow-actions -> Nfdump::execute() -> table render -> SSE push)
// and asserts on the notification, which always appears with the executed
// command regardless of whether the result set happens to be empty -- see
// graphs.test.mjs for why this suite can't assume real data exists.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export default async function flowsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.clickByAttr(`_currentView = 'flows'`);
        await page.waitForPanel('$_currentView', 'flows');

        await page.clickByText('Year', 'button');
        // Cap the result count -- a wide range can mean hundreds of rows.
        await page.setSelectValue('#filterFlowsLimit select', 20);
        await page.processData();

        const notificationHtml = await page.evaluate(`document.getElementById('flowMessage').textContent`);
        assert.match(
            notificationHtml,
            /nfdump:|processed in/,
            `expected the flow-actions notification to report the executed nfdump command or timing, got: ${notificationHtml}`
        );
        assert.doesNotMatch(notificationHtml, /error/i, `expected no error notification, got: ${notificationHtml}`);

        const rowCount = await page.evaluate(`document.querySelectorAll('#flowTable tbody tr').length`);
        console.log(`  (flows: query returned ${rowCount} row(s))`);

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Flows test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    flowsTest()
        .then(() => console.log('flows: PASS'))
        .catch((e) => {
            console.error('flows: FAIL\n', e);
            process.exit(1);
        });
}
