// Alerts (4.6): create a rule with its sources, edit it and keep them, test it once where it fires
// and once where it cannot (the dialog shows the rendered templates, the history gains a Test
// event, focus returns to the Test button), switch it on and off from its row while it is in the
// form, save a default template with another profile selected and see the template, the profile
// and the disabled rule survive a reload, then delete the rule. The Test dialog reports each
// channel: a webhook that answers (the app's own port) is sent, one that refuses the connection
// failed. A percent-of-average rule with a filter says its average is the filter's own traffic,
// which a disabled rule has not recorded, so its Test has no baseline. A second tab (a fresh
// context) follows the create, the template save and the delete without a reload. After that,
// saving nothing: Last triggered's sb-relative-time keeps the server's label when it upgrades,
// counts on its own, and its hover date follows displayTz together with Recent alerts. Only the
// live evaluation records a firing, so these checks are skipped where no rule has one.
// The rule stays disabled whenever its condition can hold, so the live evaluation never fires it.
// Mutates backend/settings/preferences.json and restores it, also after a failure. Deleting the
// rule removes its Test events from the history.
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
 * Edit the rule, set the given form fields and save it; waits for the row to show `condition`.
 * Fields: operator, threshold, thresholdType, avgWindow, webhook, filter.
 */
async function updateRule(page, name, fields, condition) {
    await page.evaluate(`document.activeElement?.blur()`);
    await clickRowButton(page, name, 'Edit');
    await page.waitFor(`document.getElementById('alertFormName').value === ${JSON.stringify(name)}`, { label: 'Edit to fill the form' });
    await page.waitFor(`document.activeElement?.id === 'alertFormName'`, { label: 'focus on the Name field after Edit' });
    const selects = { thresholdType: '#alertFormThresholdType', avgWindow: '#alertFormAvgWindow', operator: '#alertFormOperator' };
    const inputs = { threshold: '#alertFormThresholdValue', webhook: '#alertFormWebhook', filter: '#alertNfdumpFilter' };
    for (const [field, value] of Object.entries(fields)) {
        if (selects[field]) await page.setSelectValue(selects[field], value);
        else await page.setInputValue(inputs[field], String(value));
    }
    await page.evaluate(`document.querySelector('${SECTION} .alert-form-actions button[data-variant="primary"]').click()`);
    // A saved rule resets the form; the condition alone may read the same before and after.
    await page.waitFor(`document.getElementById('alertFormName').value === ''`, { label: 'the form to reset after the save' });
    await page.waitFor(`${rowExpr(name)}?.children[1].textContent.trim() === ${JSON.stringify(condition)}`, {
        label: `the row to show "${condition}"`,
    });
}

/**
 * Test the rule from the keyboard and read the dialog. Retried while the rule cannot be
 * evaluated, as during a rotation, unless the dialog already says `settled`.
 */
async function testRule(page, name, settled = null) {
    for (let attempt = 1; ; attempt++) {
        await page.evaluate(`(function(){
            var d = document.getElementById('alertTestResult');
            if (d) { d.dataset.e2eStale = '1'; if (d.isOpen) d.close(); }
        })()`);
        await page.evaluate(`${rowButtonExpr(name, 'Test')}.focus()`);
        await press(page, 'Enter');
        await page.waitFor(
            `(function(){ var d = document.getElementById('alertTestResult'); return !!d && d.isOpen && !d.dataset.e2eStale; })()`,
            { label: 'the Test dialog to open', timeout: 20000 }
        );
        const dialog = await page.evaluate(`(function(){
            var d = document.getElementById('alertTestResult');
            return {
                title: d.getAttribute('heading'),
                headline: d.querySelector('.alert-test-outcome').textContent.trim(),
                terms: [...d.querySelectorAll('.alert-test-templates dt')].map(function(t){ return t.textContent.trim(); }),
                channels: [...d.querySelectorAll('.alert-test-channels dt')].map(function(t){ return [t.textContent.trim(), t.nextElementSibling.textContent.trim()]; }),
                text: d.textContent.replace(/\\s+/g, ' '),
            };
        })()`);
        if (!dialog.text.includes('Could not evaluate') || settled?.test(dialog.text) || attempt === 3) return dialog;
        await press(page, 'Escape');
        await sleep(5000);
    }
}

/** Escape closes the Test dialog and focus goes back to the row's Test button. */
async function closeTestDialog(page, name) {
    await press(page, 'Escape');
    await page.waitFor(`document.getElementById('alertTestResult')?.isOpen === false`, { label: 'Escape to close the dialog' });
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

/** The rules' sb-relative-time hosts (rules with a recorded firing), as an array expression. */
const HOSTS = `[...document.querySelectorAll('${SECTION} #alertRules td[data-kind="time"] sb-relative-time')]`;

/** The first host whose firing Recent alerts lists, with that event's <time>; null if none. */
const LISTED = `(function(){
    for (var h of ${HOSTS}) {
        var iso = new Date(Number(h.getAttribute('datetime')) * 1000).toISOString().replace('.000Z', 'Z');
        var event = document.querySelector('#alertHistory time[datetime="' + iso + '"]');
        if (event) return { host: h, event: event };
    }
    return null;
})()`;

/** How many rules have a recorded firing, and whether Recent alerts lists one of those firings. */
async function recordedFirings() {
    return withPage(async (page) => {
        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        await openAlerts(page);
        return page.evaluate(`({ rules: ${HOSTS}.length, listed: !!${LISTED} })`);
    });
}

/** Set a signal on the client alone, as the Settings radio does before a save. */
async function setClientSignal(page, name, value) {
    await page.evaluate(`(async function(){
        var root = (await import('datastar')).root;
        var key = Object.keys(root).find(function(k){ return k === ${JSON.stringify(name)} || k.startsWith(${JSON.stringify(`${name}____`)}); });
        root[key] = ${JSON.stringify(value)};
    })()`);
}

/**
 * Before sb-relative-time loads, each rule with a firing shows the server's label; the module then
 * upgrades it with the same text. Retried when a unit edge falls between the render and the upgrade.
 */
async function upgradeKeepsTheLabel() {
    for (let attempt = 1; ; attempt++) {
        const run = await withPage(async (page) => {
            await page.send('Network.enable');
            await page.send('Network.setBlockedURLs', { urls: ['*/relative-time.js*'] });
            await page.navigate(`${BASE}/`);
            await page.waitForBoot();
            await openAlerts(page);
            // The browser in nfcapd's timezone, so both sides count the same calendar days.
            await page.send('Emulation.setTimezoneOverride', { timezoneId: await page.signalValue('nfcapdTz') });
            await setClientSignal(page, 'displayTz', 'browser');

            const src = await page.evaluate(`[...document.scripts].find(function(s){ return /\\/js\\/starbase\\/relative-time@[0-9a-f]{12}\\/relative-time\\.js$/.test(s.src); })?.src`);
            assert.ok(src, 'the layout loads sb-relative-time from the Starbase lock');
            const before = await page.evaluate(`(function(){
                return { defined: !!customElements.get('sb-relative-time'), cells: ${HOSTS}.map(function(h){
                    return { datetime: h.getAttribute('datetime'), upgraded: !!h.shadowRoot, shown: h.closest('td').innerText.trim() };
                }) };
            })()`);
            assert.ok(before.cells.length > 0, 'a rule with a recorded firing');
            assert.equal(before.defined, false, 'the module is held back');
            for (const cell of before.cells) {
                assert.equal(cell.upgraded, false);
                assert.match(cell.shown, /\S/, 'before the module loads, the cell shows the server label');
            }

            await page.send('Network.setBlockedURLs', { urls: [] });
            await page.evaluate(`import(${JSON.stringify(`${src}?late`)}).then(function(){ return true; })`);
            await page.waitFor(`${HOSTS}.every(function(h){ return !!h.shadowRoot?.querySelector('time')?.textContent; })`, {
                label: 'sb-relative-time to upgrade',
            });
            const after = await page.evaluate(`${HOSTS}.map(function(h){
                var time = h.shadowRoot.querySelector('time');
                var light = document.createRange();
                light.selectNodeContents(h);
                return { datetime: h.getAttribute('datetime'), text: time.textContent, visible: time.getClientRects().length > 0, lightShown: light.getClientRects().length > 0 };
            })`);
            return { before: before.cells, after, errors: page.realErrors() };
        });

        assert.deepEqual(run.errors, [], `no console errors around the upgrade, got:\n${run.errors.join('\n')}`);
        assert.deepEqual(
            run.after.map((c) => c.datetime),
            run.before.map((c) => c.datetime),
            'the same rules before and after the upgrade'
        );
        for (const cell of run.after) {
            assert.ok(cell.visible && !cell.lightShown, 'the upgraded text shows instead of the server label, not beside it');
        }
        const same = run.after.every((c, i) => c.text === run.before[i].shown);
        if (same) return;
        if (attempt === 3) {
            assert.deepEqual(
                run.after.map((c) => c.text),
                run.before.map((c) => c.shown),
                'the upgraded text equals the server label'
            );
        }
    }
}

/**
 * The upgraded cell counts on its own: no morph touches the light DOM while the seconds tick, the
 * column keeps its width, and the text looks like the cells beside it in light, dark and forced colours.
 */
async function countsWithoutASync() {
    await withPage(async (page) => {
        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        await openAlerts(page);
        await page.waitFor(`${HOSTS}.length > 0 && ${HOSTS}.every(function(h){ return !!h.shadowRoot?.querySelector('time')?.textContent; })`, {
            label: 'an upgraded Last triggered cell',
        });

        for (const [mode, dark] of [
            ['light', false],
            ['dark', true],
        ]) {
            await setClientSignal(page, '_darkMode', dark);
            await page.waitFor(`document.documentElement.dataset.theme === ${JSON.stringify(mode)}`, { label: `the ${mode} theme` });
            await assertLooksLikeItsNeighbours(page, mode);
        }
        await page.withForcedColors(async () => {
            await sleep(200);
            await assertLooksLikeItsNeighbours(page, 'forced colours');
            await page.screenshot('/tmp/alerts-last-triggered-forced-colors.png');
        });
        await setClientSignal(page, '_darkMode', false);

        // Eight seconds ago, counted on the client alone; the next server render puts the real moment back.
        // Sampled again when a sync lands meanwhile (the dev stack imports every five minutes).
        const sample = () =>
            page.evaluate(`(async function(){
                var host = ${HOSTS}[0];
                var cell = host.closest('td');
                var tbody = cell.closest('tbody');
                host.setAttribute('datetime', String(Math.floor(Date.now() / 1000) - 8));
                var morphs = 0;
                var observer = new MutationObserver(function(records){ morphs += records.length; });
                observer.observe(tbody, { subtree: true, childList: true, characterData: true, attributes: true });
                var out = [];
                for (var i = 0; i < 24; i++) {
                    out.push({ text: host.shadowRoot.querySelector('time').textContent, width: cell.getBoundingClientRect().width });
                    await new Promise(function(r){ setTimeout(r, 200); });
                }
                observer.disconnect();
                return { out: out, morphs: morphs };
            })()`);
        let samples = await sample();
        for (let attempt = 2; samples.morphs > 0 && attempt <= 3; attempt++) samples = await sample();
        const texts = [...new Set(samples.out.map((s) => s.text))];
        assert.equal(samples.morphs, 0, 'nothing from the server touched the rules while the seconds counted');
        assert.ok(texts.length >= 3, `the text counts up without a sync, got ${texts.join(' | ')}`);
        for (const text of texts) assert.match(text, /^\d+ seconds ago$/);
        assert.ok(
            texts.some((t) => /^\d seconds/.test(t)) && texts.some((t) => /^\d\d seconds/.test(t)),
            `the count passes from one digit to two, got ${texts.join(' | ')}`
        );
        const widths = [...new Set(samples.out.map((s) => s.width.toFixed(1)))];
        assert.equal(widths.length, 1, `the column keeps its width while the seconds count, got ${widths.join(', ')} px`);
        // The header alone can hold the column today, so the cell's own floor must fit the widest seconds text.
        const room = await page.evaluate(`(function(){
            var cell = ${HOSTS}[0].closest('td');
            var s = getComputedStyle(cell);
            var probe = document.createElement('span');
            probe.style.whiteSpace = 'nowrap';
            probe.textContent = '59 seconds ago';
            cell.append(probe);
            var text = probe.getBoundingClientRect().width;
            probe.remove();
            var box = s.boxSizing === 'border-box' ? parseFloat(s.paddingInlineStart) + parseFloat(s.paddingInlineEnd) + parseFloat(s.borderInlineStartWidth) + parseFloat(s.borderInlineEndWidth) : 0;
            return { floor: (parseFloat(s.minInlineSize) || 0) - box, text: text };
        })()`);
        assert.ok(room.floor >= room.text, `the time cell keeps room for '59 seconds ago' (${room.text.toFixed(1)} px), its floor is ${room.floor.toFixed(1)} px`);
        assert.deepEqual(page.realErrors(), []);
    });
}

/** The upgraded text takes the colour, font and figures of its cell, and the colour of the cells beside it. */
async function assertLooksLikeItsNeighbours(page, mode) {
    const styles = await page.evaluate(`(function(){
        var pick = function(el){ var s = getComputedStyle(el); return [s.color, s.fontFamily, s.fontSize, s.fontWeight, s.fontVariantNumeric, s.whiteSpace].join(' / '); };
        var host = ${HOSTS}[0];
        var cell = host.closest('td');
        return { time: pick(host.shadowRoot.querySelector('time')), cell: pick(cell), condition: getComputedStyle(cell.parentElement.children[1]).color };
    })()`);
    assert.equal(styles.time, styles.cell, `in ${mode}, the time looks like its cell`);
    assert.equal(styles.time.split(' / ')[0], styles.condition, `in ${mode}, the time has the colour of the Condition cell`);
}

/**
 * Flipping displayTz on the client, without a save, moves the rule's hover date and the Recent
 * alerts time together, in the same format, to nfcapd's wall time for "server".
 */
async function titleFollowsTheDisplayTimezone() {
    await withPage(async (page) => {
        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        const nfcapdTz = await page.signalValue('nfcapdTz');
        // A browser timezone other than nfcapd's, so the two displays differ.
        const browserTz = nfcapdTz === 'Asia/Kolkata' ? 'America/Sao_Paulo' : 'Asia/Kolkata';
        await page.send('Emulation.setTimezoneOverride', { timezoneId: browserTz });
        await page.reload();
        await page.waitForBoot();
        await openAlerts(page);
        await page.waitFor(`${HOSTS}.length > 0 && ${HOSTS}.every(function(h){ return !!h.shadowRoot?.querySelector('time')?.title; })`, {
            label: 'an upgraded Last triggered cell with its hover date',
        });

        // A rule whose last firing is in Recent alerts: the same moment in both places.
        const pair = `(function(){
            var p = ${LISTED};
            return p && { ts: Number(p.host.getAttribute('datetime')), zone: p.host.getAttribute('time-zone'), title: p.host.shadowRoot.querySelector('time').title, event: p.event.textContent.trim() };
        })()`;
        const read = async (displayTz, zone) => {
            await setClientSignal(page, 'displayTz', displayTz);
            await page.waitFor(
                `(function(){ var p = ${pair}; return !!p && p.zone === ${JSON.stringify(zone)} && p.title === p.event && p.title === new Date(p.ts * 1000).toLocaleString(undefined, ${JSON.stringify(zone ? { timeZone: zone } : {})}); })()`,
                { label: `the hover date and Recent alerts in ${displayTz} time` }
            );
            return page.evaluate(pair);
        };

        assert.ok(await page.evaluate(pair), 'a rule whose last firing is listed in Recent alerts');
        const browser = await read('browser', '');
        const server = await read('server', nfcapdTz);
        assert.notEqual(server.title, browser.title, `${browserTz} and ${nfcapdTz} show different wall times`);
        assert.match(server.title, /\d{1,2}:\d{2}:\d{2}/, 'the hover date has seconds, like Recent alerts');
        assert.deepEqual(page.realErrors(), []);
    });
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
                assert.deepEqual(fires.channels, [
                    ['Webhook', 'Not configured'],
                    ['Email', 'Not configured'],
                ]);
                await page.waitFor(
                    `[...document.querySelectorAll('#alertHistory .alert-event[data-kind="test"]')].some(function(e){ return e.textContent.includes(${JSON.stringify(ruleName)}); })`,
                    { label: 'a Test event for the rule in Recent alerts' }
                );
                // A re-render while the dialog is open keeps it open (D24).
                await clickRowButton(page, ruleName, 'Edit');
                await clickButtonText(page, 'Cancel edit');
                await sleep(300);
                assert.equal(await page.evaluate(`document.getElementById('alertTestResult').isOpen`), true, 'the dialog stays open');
                await closeTestDialog(page, ruleName);

                // Delivery per channel: the app's own port answers the webhook, port 9 refuses it.
                await updateRule(page, ruleName, { webhook: 'http://127.0.0.1:9000/favicon.svg' }, 'bytes >= absolute');
                const sent = await testRule(page, ruleName);
                assertEvaluated(sent);
                assert.equal(sent.headline, 'Would fire');
                assert.match(sent.text, /Notification sent to the webhook\./);
                assert.deepEqual(sent.channels, [
                    ['Webhook', 'Sent'],
                    ['Email', 'Not configured'],
                ]);
                await closeTestDialog(page, ruleName);
                await updateRule(page, ruleName, { webhook: 'http://127.0.0.1:9/hook' }, 'bytes >= absolute');
                const refused = await testRule(page, ruleName);
                assertEvaluated(refused);
                assert.match(refused.text, /No notification sent: sending to the webhook failed \(connection refused\)\./);
                assert.deepEqual(refused.channels[0], ['Webhook', 'Failed: connection refused']);
                await closeTestDialog(page, ruleName);

                // A percent of the average with a filter compares with the filter's own traffic, which a disabled rule never recorded.
                const noBaseline = /Could not evaluate: no baseline yet for the 10 min average of the filter's own traffic, which an enabled rule records at each check\./;
                await updateRule(
                    page,
                    ruleName,
                    { webhook: '', thresholdType: 'percent_of_avg', threshold: 200, avgWindow: '10m', filter: 'proto tcp' },
                    "bytes >= 200% of the filter's own 10 min average"
                );
                assert.equal(await rowCell(page, ruleName, 2), '10 min window');
                await page.evaluate(`document.activeElement?.blur()`);
                await clickRowButton(page, ruleName, 'Edit');
                // Edit focuses the Name field on the next frame; a later focus must not race it.
                await page.waitFor(`document.activeElement?.id === 'alertFormName'`, { label: 'focus on the Name field after Edit' });
                await page.waitFor(`document.getElementById('alertFormAvgWindowHelp')?.textContent.startsWith("The average of the filter's own traffic")`, {
                    label: "the Average over help to name the filter's own traffic",
                });
                await clickButtonText(page, 'Cancel edit');
                await page.waitFor(`document.getElementById('alertFormAvgWindowHelp')?.textContent.startsWith('The average of the stored traffic')`, {
                    label: 'the Average over help to name the stored traffic without a filter',
                });
                const cold = await testRule(page, ruleName, noBaseline);
                assert.match(cold.text, noBaseline);
                assert.equal(cold.headline, 'Would not fire');
                assert.deepEqual(cold.channels, [
                    ['Webhook', 'Not configured'],
                    ['Email', 'Not configured'],
                ]);
                await closeTestDialog(page, ruleName);

                // Test a rule that cannot fire: "< 0". The templates are shown all the same.
                await updateRule(page, ruleName, { thresholdType: 'absolute', filter: '', operator: '<', threshold: 0 }, 'bytes < absolute');
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
                await page.waitFor(
                    `![...document.querySelectorAll('#alertHistory .alert-event[data-kind="test"]')].some(function(e){ return e.textContent.includes(${JSON.stringify(ruleName)}); })`,
                    { label: "the deleted rule's Test events to leave Recent alerts" }
                );
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

    const firings = await recordedFirings();
    if (firings.rules === 0) {
        console.log('  (alerts: no rule has a recorded firing here, Last triggered checks skipped)');
        return;
    }
    await upgradeKeepsTheLabel();
    await countsWithoutASync();
    if (firings.listed) await titleFollowsTheDisplayTimezone();
    else console.log("  (alerts: no rule's last firing is among the Recent alerts, hover date check skipped)");
}

if (import.meta.url === `file://${process.argv[1]}`) {
    alertsTest()
        .then(() => console.log('alerts: PASS'))
        .catch((e) => {
            console.error('alerts: FAIL\n', e);
            process.exit(1);
        });
}
