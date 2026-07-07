// Alerts: the one test in this suite that mutates persisted state
// (backend/settings/preferences.json) -- create a uniquely-named rule,
// toggle it, then delete it, asserting the rule count returns to baseline
// so the run doesn't leave test artifacts behind in a real preferences file.
//
// All row/table queries are scoped to the alerts section's own container
// ([data-show="$_settingsSection == 'alerts'"]) rather than a bare "table
// tbody" -- every settings sub-section's markup stays in the DOM at once
// (data-show only toggles CSS visibility), so an unscoped query matches
// whichever table happens to come first in document order (the Import
// section's profiles table), not the alerts table.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const ALERTS_SECTION = `[data-show="$_settingsSection == 'alerts'"]`;

async function getRuleCount(page) {
    const text = await page.evaluate(`(function(){
        var section = document.querySelector(${JSON.stringify(ALERTS_SECTION)});
        var b = [...section.querySelectorAll('.badge')].find(function(x){ return /rule/.test(x.textContent); });
        return b ? b.textContent.trim() : null;
    })()`);
    const match = text && text.match(/(\d+)/);
    return match ? Number(match[1]) : null;
}

async function clickRuleButton(page, ruleName, title) {
    const clicked = await page.evaluate(`(function(){
        var section = document.querySelector(${JSON.stringify(ALERTS_SECTION)});
        var row = [...section.querySelectorAll('table tbody tr')].find(function(r){ return r.textContent.includes(${JSON.stringify(ruleName)}); });
        if (!row) return false;
        var btn = [...row.querySelectorAll('button')].find(function(b){ return b.title === ${JSON.stringify(title)}; });
        if (!btn) return false;
        btn.click();
        return true;
    })()`);
    if (!clicked) throw new Error(`could not find a button titled "${title}" in the row for rule "${ruleName}"`);
}

export default async function alertsTest() {
    await withPage(async (page) => {
        page.autoAcceptDialogs(); // the delete button gates on a native confirm()

        await page.navigate(BASE + '/');
        await page.clickByAttr(`_currentView = 'settings'`);
        await page.waitForPanel('$_currentView', 'settings');
        await page.clickByAttr(`_settingsSection = 'alerts'`);
        await page.waitForPanel('$_settingsSection', 'alerts');

        const baseline = await getRuleCount(page);
        assert.notEqual(baseline, null, 'expected to find the "N rules" badge');

        const ruleName = `e2e-test-rule-${Date.now()}`;
        await page.setInputValue('#alert-form-card input[placeholder="e.g. High traffic on gw1"]', ruleName);
        await page.clickByText('Create rule', 'button');
        await page.waitFor(
            `(function(){
                var section = document.querySelector(${JSON.stringify(ALERTS_SECTION)});
                return section && section.querySelector('table tbody')?.textContent.includes(${JSON.stringify(ruleName)});
            })()`,
            { label: 'new rule to appear in the table' }
        );
        assert.equal(await getRuleCount(page), baseline + 1, 'expected the rule count badge to increment by one');

        // New rules default to enabled -- toggle it off and check the row's badge flips.
        await clickRuleButton(page, ruleName, 'Disable');
        await page.waitFor(
            `(function(){
                var section = document.querySelector(${JSON.stringify(ALERTS_SECTION)});
                var row = [...section.querySelectorAll('table tbody tr')].find(function(r){ return r.textContent.includes(${JSON.stringify(ruleName)}); });
                return row && row.textContent.includes('off');
            })()`,
            { label: 'toggled rule to show as off' }
        );

        // Clean up: delete it and confirm the count returns to baseline.
        await clickRuleButton(page, ruleName, 'Delete');
        await page.waitFor(
            `(function(){
                var section = document.querySelector(${JSON.stringify(ALERTS_SECTION)});
                return !section.querySelector('table tbody')?.textContent.includes(${JSON.stringify(ruleName)});
            })()`,
            { label: 'deleted rule to disappear from the table' }
        );
        assert.equal(await getRuleCount(page), baseline, 'expected the rule count badge to return to its baseline after cleanup');

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Alerts test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    alertsTest()
        .then(() => console.log('alerts: PASS'))
        .catch((e) => {
            console.error('alerts: FAIL\n', e);
            process.exit(1);
        });
}
