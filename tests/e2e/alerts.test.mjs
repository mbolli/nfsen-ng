// Alerts (4.6): create a rule with its sources, edit it and keep them, test it once where it fires
// and once where it cannot (the dialog shows the rendered templates, the history gains a Test
// event, focus returns to the Test button), switch it on and off from its row while it is in the
// form, save a default template with another profile selected and see the template, the profile
// and the disabled rule survive a reload, then delete the rule. A second tab (a fresh context)
// follows the create, the template save and the delete without a reload.
// The rule stays disabled whenever its condition can hold, so the live evaluation never fires it.
// Mutates backend/settings/preferences.json and restores it, also after a failure. The two Test
// events stay in the alert history: the store has no way to remove them.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export const MUTATING = true;

const SECTION = '#page-alerts';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
// En and em dash, as char codes so no formatter turns them into the characters.
const DASHES = [0x2013, 0x2014].map((code) => String.fromCharCode(code));

async function press(page, key, modifiers = 0) {
    const codes = { Escape: 27, Enter: 13, Tab: 9, Space: 32 };
    const base = {
        key: key === 'Space' ? ' ' : key,
        code: key,
        windowsVirtualKeyCode: codes[key],
        nativeVirtualKeyCode: codes[key],
        modifiers,
    };
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(key === 'Enter' ? { text: '\r' } : {}) });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

/** The row of the rule named `name` under #alertRules tbody, as an expression. */
const rowExpr = (name) =>
    `[...document.querySelectorAll('${SECTION} #alertRules tbody tr')].find(function(r){ return r.querySelector('th')?.firstChild?.textContent.trim() === ${JSON.stringify(name)}; })`;

/** The row button whose aria-label is `<verb> <name>`, as an expression. */
const rowButtonExpr = (name, verb) =>
    `(function(){ var row = ${rowExpr(name)}; return row && [...row.querySelectorAll('button')].find(function(b){ return b.getAttribute('aria-label') === ${JSON.stringify(`${verb} ${name}`)}; }); })()`;

async function ruleCount(page) {
    const text = await page.evaluate(`document.querySelector('${SECTION} #alertRules .card-header .badge')?.textContent ?? ''`);
    const match = text.match(/(\d+) rules?/);
    return match ? Number(match[1]) : null;
}

async function clickRowButton(page, name, verb) {
    const clicked = await page.evaluate(
        `(function(){ var b = ${rowButtonExpr(name, verb)}; if (!b) return false; b.click(); return true; })()`
    );
    if (!clicked) throw new Error(`no "${verb} ${name}" button in the rules table`);
}

async function focusedLabel(page) {
    return page.evaluate(`document.activeElement?.getAttribute('aria-label') ?? document.activeElement?.tagName`);
}

async function clickButtonText(page, text, scope = SECTION) {
    const clicked = await page.evaluate(`(function(){
        var b = [...document.querySelectorAll(${JSON.stringify(`${scope} button`)})].find(function(e){ return e.getClientRects().length > 0 && e.textContent.trim() === ${JSON.stringify(text)}; });
        if (!b) return false;
        b.click();
        return true;
    })()`);
    if (!clicked) throw new Error(`no visible "${text}" button`);
}

async function selectedSources(page) {
    return page.evaluate(`[...document.getElementById('alertFormSources').selectedOptions].map(function(o){ return o.value; })`);
}

async function rowCell(page, name, index) {
    return page.evaluate(`${rowExpr(name)}?.children[${index}].textContent.trim()`);
}

async function rowStatus(page, name) {
    return page.evaluate(`${rowExpr(name)}?.dataset.status`);
}

/** Click the row's enabled switch and wait for the row to show `status`. */
async function toggleRow(page, name, status) {
    await page.evaluate(`${rowExpr(name)}.querySelector('input[role="switch"]').click()`);
    await page.waitFor(`${rowExpr(name)}?.dataset.status === ${JSON.stringify(status)}`, { label: `the row to show ${status}` });
}

/**
 * Edit the rule, change its condition and save it from the keyboard; waits for the row to show
 * the new condition and checks that the Save button kept the focus.
 */
async function updateCondition(page, name, operator, threshold) {
    // Focus left on the Name field by an earlier edit would satisfy the focus wait below too early.
    await page.evaluate(`document.activeElement?.blur()`);
    await clickRowButton(page, name, 'Edit');
    await page.waitFor(`document.getElementById('alertFormName').value === ${JSON.stringify(name)}`, { label: 'Edit to fill the form' });
    // Edit moves focus to the Name field on the next frame; wait for it before moving it on.
    await page.waitFor(`document.activeElement?.id === 'alertFormName'`, { label: 'focus on the Name field after Edit' });
    await page.evaluate('new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))');
    await page.setSelectValue('#alertFormOperator', operator);
    await page.setInputValue('#alertFormThresholdValue', String(threshold));
    await page.evaluate(`document.querySelector('${SECTION} .alert-form-actions button[data-variant="primary"]').focus()`);
    await press(page, 'Enter');
    await page.waitFor(`${rowExpr(name)}?.children[1].textContent.trim() === ${JSON.stringify(`bytes ${operator} absolute`)}`, {
        label: `the row to show "bytes ${operator} absolute"`,
    });
    assert.equal(
        await page.evaluate(
            `document.activeElement === document.querySelector('${SECTION} .alert-form-actions button[data-variant="primary"]') ? 'Save' : document.activeElement?.outerHTML.slice(0, 120)`
        ),
        'Save',
        'the Save button keeps the focus while it saves'
    );
}

/**
 * Test the rule from the keyboard and read the dialog. Retried while the rule cannot be
 * evaluated, as during a rotation.
 */
async function testRule(page, name) {
    for (let attempt = 1; ; attempt++) {
        await page.evaluate(`(function(){
            var d = document.getElementById('alertTestResult');
            if (d) { d.dataset.e2eStale = '1'; if (d.open) d.close(); }
        })()`);
        await page.evaluate(`${rowButtonExpr(name, 'Test')}.focus()`);
        await press(page, 'Enter');
        await page.waitFor(
            `(function(){ var d = document.getElementById('alertTestResult'); return !!d && d.open && !d.dataset.e2eStale; })()`,
            { label: 'the Test dialog to open', timeout: 20000 }
        );
        const dialog = await page.evaluate(`(function(){
            var d = document.getElementById('alertTestResult');
            return {
                title: d.querySelector('h2').textContent.trim(),
                headline: d.querySelector('.alert-test-outcome').textContent.trim(),
                terms: [...d.querySelectorAll('dt')].map(function(t){ return t.textContent.trim(); }),
                text: d.textContent.replace(/\\s+/g, ' '),
            };
        })()`);
        if (!dialog.text.includes('Could not evaluate') || attempt === 3) return dialog;
        await press(page, 'Escape');
        await sleep(5000);
    }
}

/** Escape closes the Test dialog and focus goes back to the row's Test button. */
async function closeTestDialog(page, name) {
    await press(page, 'Escape');
    await page.waitFor(`document.getElementById('alertTestResult')?.open === false`, { label: 'Escape to close the dialog' });
    assert.equal(await focusedLabel(page), `Test ${name}`, 'closing the dialog returns focus to the Test button');
}

function assertEvaluated(dialog) {
    assert.ok(!dialog.text.includes('Could not evaluate'), `expected the Test to evaluate the rule, got: ${dialog.text}`);
    assert.deepEqual(dialog.terms, ['Webhook title', 'Webhook message', 'Email subject', 'Email body']);
    assert.ok(!DASHES.some((d) => dialog.text.includes(d)), 'no en or em dash in the Test dialog');
}

async function openTemplates(page) {
    await page.evaluate(`(function(){
        var toggle = document.querySelector('#alert-default-templates-card .alert-templates-toggle');
        if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
    })()`);
    await page.waitFor(`!document.getElementById('alertTemplatesBody').hidden`, { label: 'the default templates to open' });
}

async function saveTemplates(page) {
    await clickButtonText(page, 'Save default templates');
    await page.waitFor(
        `[...document.querySelectorAll('#alerts-toast-container *')].some(function(e){ return e.textContent.includes('Default templates saved.'); })`,
        {
            label: 'the templates to be saved',
        }
    );
}

async function openAlerts(page) {
    await page.gotoPage('alerts');
    await page.waitFor(`!!document.querySelector('${SECTION} #alertRules') && !!document.querySelector('${SECTION} #alertHistory')`, {
        label: 'the rules and the recent alerts',
    });
}

async function reloadAlerts(page) {
    await page.reload();
    await page.waitForBoot();
    await openAlerts(page);
}

/** Pick a profile in the controls bar and wait for change-profile to have saved it. */
async function chooseProfile(page, profile) {
    await page.evaluate(`(function(){
        window.__e2eProfileSaved = false;
        document.addEventListener('datastar-fetch', function done(e){
            if (e.detail.type === 'finished' && e.detail.el?.id === 'profileSelect') {
                window.__e2eProfileSaved = true;
                document.removeEventListener('datastar-fetch', done);
            }
        });
    })()`);
    await page.setSelectValue('#profileSelect', profile);
    await page.waitFor('window.__e2eProfileSaved === true', { label: `change-profile to ${profile}`, timeout: 10000 });
}

export default async function alertsTest() {
    await withPage(async (page) => {
        page.autoAcceptDialogs(); // the Delete button asks with confirm()

        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        await openAlerts(page);

        const baseline = await ruleCount(page);
        assert.notEqual(baseline, null, 'expected the "N rules" badge');
        const historyText = await page.evaluate(`document.getElementById('alertHistory').textContent`);
        assert.ok(!historyText.includes('History unavailable'), 'the dev stack has a store, so the history is available');

        const ruleName = `e2e-rule-${Date.now()}`;
        // What the finally block puts back; null once restored.
        const restore = { rule: false, title: null, profile: null };
        let titleBefore = null;
        let profileBefore = null;
        let otherErrors = [];

        try {
            await withPage(async (other) => {
                await other.navigate(`${BASE}/`);
                await other.waitForBoot();
                await openAlerts(other);

                // Keyboard: New rule clears the form and moves focus to the Name field.
                await page.evaluate(`document.querySelector('${SECTION} .alert-new-rule').focus()`);
                await press(page, 'Enter');
                await page.waitFor(`document.activeElement?.id === 'alertFormName'`, { label: 'focus on the Name field after New rule' });
                assert.deepEqual(await selectedSources(page), [], 'a new rule selects no source, so it covers all of them');
                assert.equal(await page.evaluate(`document.getElementById('alertFormTitle').textContent.trim()`), 'New rule');

                // Create, disabled, with (up to) two sources.
                await page.setInputValue(`${SECTION} input[placeholder="e.g. High traffic on gw1"]`, ruleName);
                const sources = await page.evaluate(`(function(){
                    var select = document.getElementById('alertFormSources');
                    var values = [...select.options].slice(0, 2).map(function(o){ return o.value; });
                    for (var o of select.options) o.selected = values.includes(o.value);
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    return values;
                })()`);
                assert.ok(sources.length >= 1, 'expected at least one configured source');
                await page.setInputValue('#alertFormThresholdValue', '1000');
                await page.evaluate(`document.getElementById('alertFormEnabled').click()`);
                assert.equal(await page.evaluate(`document.getElementById('alertFormEnabled').checked`), false);
                await clickButtonText(page, 'Create rule');
                await page.waitFor(`!!${rowExpr(ruleName)}`, { label: 'the new rule in the table' });
                restore.rule = true;
                assert.equal(await ruleCount(page), baseline + 1, 'the rule count grows by one');
                assert.equal(await rowStatus(page, ruleName), 'disabled', 'the rule is saved disabled');
                assert.equal(await rowCell(page, ruleName, 2), '1 kB/s', 'the Threshold column formats the value with its unit');
                assert.equal(await rowCell(page, ruleName, 3), sources.join(', '), 'the Sources column lists the chosen sources');
                await page.waitFor(`document.getElementById('alertFormName').value === ''`, { label: 'the form to reset after the save' });
                await other.waitFor(`!!${rowExpr(ruleName)}`, { label: 'the other tab to show the new rule' });

                // Keyboard: the row's switch, then Edit, Test and Delete, in tab order.
                await page.evaluate(`${rowExpr(ruleName)}.querySelector('input[role="switch"]').focus()`);
                for (const verb of ['Edit', 'Test', 'Delete']) {
                    await press(page, 'Tab');
                    assert.equal(await focusedLabel(page), `${verb} ${ruleName}`);
                }

                // Edit keeps every field, the sources included, and the title names the rule.
                await clickRowButton(page, ruleName, 'Edit');
                await page.waitFor(`document.getElementById('alertFormName').value === ${JSON.stringify(ruleName)}`, {
                    label: 'Edit to fill the form',
                });
                assert.deepEqual(await selectedSources(page), sources, "Edit keeps the rule's sources selected");
                assert.equal(await page.evaluate(`document.getElementById('alertFormTitle').textContent.trim()`), `Edit rule: ${ruleName}`);
                assert.equal(
                    await page.evaluate(`document.getElementById('alertFormEnabled').checked`),
                    false,
                    'Edit keeps the rule disabled'
                );
                await clickButtonText(page, 'Cancel edit');

                // ">= 0" holds whenever the rule can be evaluated; the rule stays disabled.
                await updateCondition(page, ruleName, '>=', 0);
                assert.equal(await rowCell(page, ruleName, 2), '0 B/s', 'the updated threshold is in the row');
                assert.equal(await ruleCount(page), baseline + 1, 'an update adds no rule');
                assert.equal(await rowCell(page, ruleName, 3), sources.join(', '), 'the sources survive the update');
                assert.equal(await rowStatus(page, ruleName), 'disabled', 'the update keeps the rule disabled');

                // Test a rule that fires: the outcome, the rendered templates, and a Test event in the history.
                const fires = await testRule(page, ruleName);
                assert.equal(fires.title, `Test: ${ruleName}`);
                assertEvaluated(fires);
                assert.equal(fires.headline, 'Would fire');
                assert.match(fires.text, /No notification sent: the rule has no email or webhook set up\./);
                await page.waitFor(
                    `[...document.querySelectorAll('#alertHistory .alert-event[data-kind="test"]')].some(function(e){ return e.textContent.includes(${JSON.stringify(ruleName)}); })`,
                    { label: 'a Test event for the rule in Recent alerts' }
                );
                // A re-render while the dialog is open keeps it open (D24).
                await clickRowButton(page, ruleName, 'Edit');
                await clickButtonText(page, 'Cancel edit');
                await sleep(300);
                assert.equal(await page.evaluate(`document.getElementById('alertTestResult').open`), true, 'the dialog stays open');
                await closeTestDialog(page, ruleName);

                // Test a rule that cannot fire: "< 0". The templates are shown all the same.
                await updateCondition(page, ruleName, '<', 0);
                const quiet = await testRule(page, ruleName);
                assertEvaluated(quiet);
                assert.equal(quiet.headline, 'Would not fire');
                assert.match(quiet.text, /No notification sent\./);
                await closeTestDialog(page, ruleName);

                // The row's switch while the rule is in the form: the form follows, so Update rule
                // cannot undo it. "< 0" never holds, so the rule is safe to enable.
                await clickRowButton(page, ruleName, 'Edit');
                await page.waitFor(`document.getElementById('alertFormEnabled').checked === false`, { label: 'Edit to fill Enabled' });
                await toggleRow(page, ruleName, 'ok');
                assert.equal(await page.evaluate(`${rowExpr(ruleName)}.querySelector('input[role="switch"]').checked`), true);
                await page.waitFor(`document.getElementById('alertFormEnabled').checked === true`, {
                    label: "the form's Enabled switch to follow the row on",
                });
                await toggleRow(page, ruleName, 'disabled');
                assert.equal(
                    await page.evaluate(`${rowExpr(ruleName)}.querySelector('.badge[data-status]').textContent.trim()`),
                    'Disabled'
                );
                assert.equal(await page.evaluate(`${rowExpr(ruleName)}.querySelector('input[role="switch"]').checked`), false);
                await page.waitFor(`document.getElementById('alertFormEnabled').checked === false`, {
                    label: "the form's Enabled switch to follow the row off",
                });
                await clickButtonText(page, 'Cancel edit');

                // The switch persists: on survives a reload, and so does off.
                await toggleRow(page, ruleName, 'ok');
                await reloadAlerts(page);
                assert.equal(await rowStatus(page, ruleName), 'ok', 'the enabled rule stays enabled after a reload');
                assert.equal(await page.evaluate(`${rowExpr(ruleName)}.querySelector('input[role="switch"]').checked`), true);
                await toggleRow(page, ruleName, 'disabled');
                await reloadAlerts(page);
                assert.equal(await rowStatus(page, ruleName), 'disabled', 'the disabled rule stays disabled after a reload');

                // Default templates persist, and saving them keeps a selected profile other than the default.
                const profiles = await page.signalValue('available_profiles');
                profileBefore = await page.signalValue('selected_profile');
                const otherProfile = Array.isArray(profiles) ? profiles.find((p) => p !== profileBefore) : undefined;
                assert.ok(otherProfile, 'the dev stack has a second profile (live and test)');
                restore.profile = profileBefore;
                await chooseProfile(page, otherProfile);
                await reloadAlerts(page);
                assert.equal(await page.signalValue('selected_profile'), otherProfile, 'the profile switch is saved');

                const formProfileBefore = await page.evaluate(`document.getElementById('alertFormProfile').value`);
                await openTemplates(page);
                titleBefore = await page.evaluate(`document.getElementById('alertDefaultWebhookTitle').value`);
                restore.title = titleBefore;
                const newTitle = `e2e {rule} ${Date.now()}`;
                await page.setInputValue('#alertDefaultWebhookTitle', newTitle);
                await page.waitFor(
                    `document.querySelector('[aria-labelledby="alertDefaultWebhookPreviewLabel"]').textContent.startsWith('Title: e2e Example Rule')`,
                    {
                        label: 'the live preview to render the token',
                    }
                );
                await saveTemplates(page);
                await other.waitFor(`document.getElementById('alertDefaultWebhookTitle').value === ${JSON.stringify(newTitle)}`, {
                    label: 'the other tab to take over the saved default template',
                });

                await reloadAlerts(page);
                await openTemplates(page);
                assert.equal(
                    await page.evaluate(`document.getElementById('alertDefaultWebhookTitle').value`),
                    newTitle,
                    'the default template persisted'
                );
                assert.equal(await page.signalValue('selected_profile'), otherProfile, 'saving the templates keeps the selected profile');
                assert.equal(
                    await page.evaluate(`document.getElementById('alertFormProfile').value`),
                    formProfileBefore,
                    'the profile select keeps its value'
                );
                assert.equal(await rowStatus(page, ruleName), 'disabled', 'the disabled rule stays disabled after a reload');
                assert.equal(await page.evaluate(`${rowExpr(ruleName)}.querySelector('input[role="switch"]').checked`), false);

                // Forced colors: a screenshot for the wave gate's look (2.2).
                await page.withForcedColors(async () => {
                    await sleep(200);
                    await page.screenshot('/tmp/alerts-forced-colors.png');
                });

                // Delete through the row's Delete button.
                await clickRowButton(page, ruleName, 'Delete');
                await page.waitFor(`!${rowExpr(ruleName)}`, { label: 'the deleted rule to leave the table' });
                restore.rule = false;
                assert.equal(await ruleCount(page), baseline, 'the rule count is back to its baseline');
                await other.waitFor(`!${rowExpr(ruleName)}`, { label: 'the other tab to drop the deleted rule' });

                otherErrors = other.realErrors();
            });
        } finally {
            // Put preferences.json back, also after a failed assertion; one failed step does not stop the others.
            if (restore.rule || restore.title !== null || restore.profile !== null) {
                const steps = [() => reloadAlerts(page)];
                if (restore.rule) {
                    steps.push(async () => {
                        await clickRowButton(page, ruleName, 'Delete');
                        await page.waitFor(`!${rowExpr(ruleName)}`, { label: 'the rule to be deleted' });
                    });
                }
                if (restore.title !== null) {
                    steps.push(async () => {
                        await openTemplates(page);
                        await page.setInputValue('#alertDefaultWebhookTitle', restore.title);
                        await saveTemplates(page);
                    });
                }
                if (restore.profile !== null) {
                    steps.push(async () => {
                        if ((await page.signalValue('selected_profile')) !== restore.profile) await chooseProfile(page, restore.profile);
                    });
                }
                for (const step of steps) {
                    try {
                        await step();
                    } catch (e) {
                        console.error(`alerts: restoring failed: ${e.message}`);
                    }
                }
            }
        }

        await reloadAlerts(page);
        await openTemplates(page);
        assert.equal(
            await page.evaluate(`document.getElementById('alertDefaultWebhookTitle').value`),
            titleBefore,
            'the default template is restored'
        );
        assert.equal(await page.signalValue('selected_profile'), profileBefore, 'the selected profile is restored');
        assert.equal(await ruleCount(page), baseline, 'no test rule is left behind');

        const errors = [...page.realErrors(), ...otherErrors];
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
