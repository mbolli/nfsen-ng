// Settings, and the two pages that used to be its sections: Settings holds the preferences
// and the deployment, Health the import controls and checks, Alerts the rules. Read-only
// navigation check -- alerts.test.mjs is the one that changes settings state.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const text = (id) => `document.getElementById('page-${id}').textContent`;

export default async function settingsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitForBoot();

        await page.gotoPage('settings');
        assert.match(await page.evaluate(text('settings')), /User Preferences/, 'Settings shows the preferences');
        assert.match(await page.evaluate(text('settings')), /Deployment configuration/, 'Settings shows the deployment');

        await page.gotoPage('health');
        assert.match(await page.evaluate(text('health')), /System Health/, 'Health shows the checks');
        assert.match(await page.evaluate(text('health')), /Import/, 'Health shows the import controls');

        await page.gotoPage('alerts');
        assert.match(await page.evaluate(text('alerts')), /Alert Rules/, 'Alerts shows the rules');

        // An old view id in the hash lands on the page it became.
        await page.evaluate(`location.hash = '#/statistics'`);
        await page.waitForPage('talkers');
        assert.equal(await page.evaluate('location.hash'), '#/talkers', 'a legacy hash is rewritten to the page id');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors navigating Settings, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    settingsTest()
        .then(() => console.log('settings: PASS'))
        .catch((e) => {
            console.error('settings: FAIL\n', e);
            process.exit(1);
        });
}
