// Settings tab: every sub-section (Preferences, Health, Alerts, Import,
// System) is reachable with no console errors. Read-only navigation check --
// see alerts.test.mjs for the one test that actually mutates settings state.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export default async function settingsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.clickByAttr(`_currentView = 'settings'`);
        await page.waitForPanel('$_currentView', 'settings');

        for (const section of ['preferences', 'health', 'alerts', 'import', 'system']) {
            await page.clickByAttr(`_settingsSection = '${section}'`);
            await page.waitForPanel('$_settingsSection', section, { timeout: 3000 });
        }

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
