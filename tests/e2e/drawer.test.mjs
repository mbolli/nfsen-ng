// Filter builder drawer (4.5, 5.5). Mutating: saves and deletes its own filters, and deletes the
// ones it imports from a seeded browser list again.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export const MUTATING = true;

const DRAWER = '#filter-drawer';
const EDITOR = '#drawerFilterTextarea';
const RAW = '#drawerRawTextarea';
const STATUS = '[data-filter-status="drawer"]';
const FLOWS_FIELD = '[data-filter-field="flows"] textarea';
const KEYS = {
    Enter: ['Enter', 'Enter', 13],
    Escape: ['Escape', 'Escape', 27],
    Tab: ['Tab', 'Tab', 9],
    ArrowDown: ['ArrowDown', 'ArrowDown', 40],
    ArrowUp: ['ArrowUp', 'ArrowUp', 38],
    ArrowLeft: ['ArrowLeft', 'ArrowLeft', 37],
    ArrowRight: ['ArrowRight', 'ArrowRight', 39],
};

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Polls a condition on the test's side, not the page's. */
async function until(check, label, timeout = 8000) {
    const end = Date.now() + timeout;
    while (!check()) {
        if (Date.now() > end) throw new Error(`timed out waiting for ${label}`);
        await sleep(50);
    }
}

/** A real key press; Enter carries its character, so it also activates a focused button. */
async function press(page, name) {
    const [key, code, keyCode] = KEYS[name];
    const down = name === 'Enter' ? { type: 'keyDown', text: '\r' } : { type: 'rawKeyDown' };
    await page.send('Input.dispatchKeyEvent', { ...down, key, code, windowsVirtualKeyCode: keyCode });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode });
}

async function type(page, text) {
    await page.send('Input.insertText', { text });
}

/** A real pointer press and release at the centre of the suggestion at `index`. */
async function pressOption(page, index) {
    const { x, y } = await page.evaluate(`(function(){
        var r = document.querySelector('#drawerSuggestions [data-index="${index}"]').getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    })()`);
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
}

/** Posts refresh-graphs as the Flows page and resolves once the sync it causes has morphed the page. */
const SYNC = `(async function(){
    var html = document.documentElement.outerHTML;
    var ctx = (html.match(/via_ctx&quot;:&quot;([^&]+)&quot;/) || html.match(/via_ctx":"([^"]+)"/) || [])[1];
    var pageSignal = (html.match(/\\bpage____[a-z0-9]+/) || [])[0];
    var url = (html.match(/[^'"\\s]*_action\\/refresh-graphs[A-Za-z0-9-]*/) || [])[0];
    if (!ctx || !pageSignal || !url) return 'missing';
    var probe = document.getElementById('page-content');
    probe.setAttribute('data-probe', '');
    var body = { via_ctx: ctx };
    body[pageSignal] = 'flows';
    await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Datastar-Request': 'true' },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(15000),
    });
    for (var i = 0; i < 100 && probe.isConnected && probe.hasAttribute('data-probe'); i++) await new Promise(function(r){ setTimeout(r, 100); });
    return probe.hasAttribute('data-probe') ? 'no sync' : 'synced';
})()`;

/** The drawer's filter checks posted from now on. */
async function drawerChecks(page) {
    const posts = [];
    await page.send('Network.enable');
    page.ws.addEventListener('message', (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.method !== 'Network.requestWillBeSent' || msg.params.request.method !== 'POST') return;
        if (/\/_action\/validate-filter[^/?]*\?target=drawer$/.test(msg.params.request.url)) posts.push(Date.now());
    });
    return posts;
}

const q = (selector) => `document.querySelector(${JSON.stringify(selector)})`;
const row = (name) => `[...document.querySelectorAll('#drawerSavedList li[data-filter-id]')].find((li) => li.dataset.name === ${JSON.stringify(name)})`;
const visible = (expr) => `(function(){ var e = ${expr}; return !!e && e.getClientRects().length > 0; })()`;

async function value(page, selector) {
    return page.evaluate(`${q(selector)}?.value`);
}

/** Replace the editor's text as typing would, then wait for the answer to that text. */
async function setEditor(page, text) {
    await page.evaluate(`(function(){ var t = ${q(EDITOR)}; t.focus(); t.select(); })()`);
    if (text === '') await page.evaluate(`(function(){ var t = ${q(EDITOR)}; t.value = ''; t.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    else await type(page, text);
    await page.waitFor(`${q(EDITOR)}.value === ${JSON.stringify(text)}`, { label: `editor text ${text}` });
}

async function stateOf(page) {
    return page.evaluate(`${q(STATUS)}?.dataset.state`);
}

async function waitForState(page, state) {
    try {
        await page.waitFor(`${q(STATUS)}?.dataset.state === '${state}'`, { timeout: 6000, label: `drawer filter ${state}` });
    } catch (e) {
        throw new Error(`${e.message}; state ${await stateOf(page)}, text ${JSON.stringify(await value(page, EDITOR))}`);
    }
}

async function waitForNotice(page, text) {
    await page.waitFor(`${q('#drawerNotice')}?.textContent === ${JSON.stringify(text)}`, { timeout: 8000, label: `notice "${text}"` });
}

/** Open the drawer from a field's Builder or Saved button and wait for the editor. */
async function openDrawer(page, target, tab = 'builder') {
    // Focus first, as a pointer click does: closing the dialog returns focus there.
    await page.evaluate(`(function(){ var b = ${q(`[data-filter-field="${target}"] [data-open-drawer="${tab}"]`)}; b.focus(); b.click(); })()`);
    await page.waitFor(`${q(DRAWER)}.open && ${visible(q('#drawerSearch'))} && ${q('#drawerTitle')}.textContent.includes('for ')`, {
        timeout: 10000,
        label: `drawer open for ${target}`,
    });
}

async function closedDrawer(page) {
    await page.waitFor(`!${q(DRAWER)}.open`, { label: 'drawer closed' });
}

/** Open a saved filter's action menu and choose an item (apply, edit, rename, delete). */
async function menuAction(page, name, action) {
    await page.evaluate(`${row(name)}.querySelector('[aria-haspopup="menu"]').click()`);
    await page.waitFor(visible(`${row(name)}.querySelector('[role="menuitem"][data-action="${action}"]')`), {
        label: `${action} in the menu of ${name}`,
    });
    await page.evaluate(`${row(name)}.querySelector('[role="menuitem"][data-action="${action}"]').click()`);
}

async function savedNames(page) {
    return page.evaluate(`[...document.querySelectorAll('#drawerSavedList li[data-filter-id]')].map((li) => li.dataset.name)`);
}

async function deleteSaved(page, name) {
    if (!(await page.evaluate(`!!${row(name)}`))) return;
    await menuAction(page, name, 'delete');
    await page.waitFor(`!${row(name)}`, { timeout: 8000, label: `${name} deleted` });
}

/** Cleanup: on the Flows page, deletes every saved filter `match` picks. Never throws. */
async function removeSaved(page, match) {
    try {
        if (await page.evaluate(`${q(DRAWER)}.open`)) {
            await page.evaluate(`${q(DRAWER)}.close()`);
            await closedDrawer(page);
        }
        await page.gotoPage('flows');
        await openDrawer(page, 'flows', 'saved');
        const rows = await page.evaluate(
            `[...document.querySelectorAll('#drawerSavedList li[data-filter-id]')].map((li) => ({ name: li.dataset.name, expression: li.dataset.expression }))`
        );
        for (const li of rows.filter(match)) await deleteSaved(page, li.name);
    } catch (e) {
        console.warn(`  (cleanup of saved filters incomplete: ${e.message})`);
    }
}

export default async function drawerTest() {
    const stamp = Date.now().toString(36);
    const name = `e2e drawer ${stamp}`;
    const renamed = `e2e renamed ${stamp}`;
    const expression = `dst port in [80 443] and not host 192.0.2.${(Date.now() % 200) + 20}`;
    const edited = `${expression} and proto tcp`;

    await withPage(async (page) => {
        page.autoAcceptDialogs();
        await page.navigate(`${BASE}/#/flows`);
        await page.waitForBoot();
        await page.gotoPage('flows');
        await page.setRangePreset('1y');
        await page.setInputValue(FLOWS_FIELD, '');

        try {
            // ── Open from Flows: the textarea has focus, the frame knows its target ──
            await openDrawer(page, 'flows');
            await page.waitFor(`document.activeElement?.id === 'drawerFilterTextarea'`, { label: 'focus in the textarea' });
            assert.match(await page.evaluate(`${q('#drawerTitle')}.textContent`), /Filter builder\s+for Flows/);

            // ── Autocomplete: "ho" offers host, Enter accepts it, the live region says so ──
            await setEditor(page, '');
            await type(page, 'ho');
            await page.waitFor(visible(q('#drawerSuggestions [role="option"][aria-selected="true"]')), { label: 'suggestions' });
            assert.equal(await page.evaluate(`${q('#drawerSuggestions [aria-selected="true"]')}.textContent`), 'host');
            assert.match(await page.evaluate(`${q('#drawerSuggestStatus')}.textContent`), /^\d+ suggestions?, host selected$/);
            await press(page, 'Enter');
            assert.equal(await value(page, EDITOR), 'host ', 'Enter accepts the highlighted suggestion');
            assert.equal(await page.evaluate(`${q('#drawerSuggestStatus')}.textContent`), 'host inserted');
            assert.ok(await page.evaluate(`${q('#drawerSuggestions')}.hidden`), 'the list closes on accept');
            await type(page, '192.0.2.10');

            // Escape closes an open suggestion list, not the drawer.
            await type(page, ' an');
            await page.waitFor(visible(q('#drawerSuggestions [role="option"]')), { label: 'suggestions for "an"' });
            await press(page, 'Escape');
            assert.ok(await page.evaluate(`${q(DRAWER)}.open && ${q('#drawerSuggestions')}.hidden`), 'Escape closes the list only');

            // ── The editor is a Rocket element beside the textarea; the arrows move the highlight and wrap ──
            assert.ok(await page.evaluate(`!!document.getElementById('drawerEditor')?.rocketInstanceId`), 'the editor is a Rocket host');
            const highlighted = () => page.evaluate(`${q('#drawerSuggestions [aria-selected="true"]')}?.textContent`);
            const suggestStatus = () => page.evaluate(`${q('#drawerSuggestStatus')}.textContent`);
            await setEditor(page, '');
            await type(page, 'an');
            await page.waitFor(visible(q('#drawerSuggestions [data-index="1"]')), { label: 'two suggestions for "an"' });
            assert.deepEqual(await page.evaluate(`[...document.querySelectorAll('#drawerSuggestions [role="option"]')].map((o) => o.textContent)`), ['and', 'any']);
            await press(page, 'ArrowDown');
            assert.equal(await highlighted(), 'any');
            assert.equal(await suggestStatus(), '2 suggestions, any selected');
            await press(page, 'ArrowDown');
            assert.equal(await highlighted(), 'and', 'ArrowDown wraps to the first');
            await press(page, 'ArrowUp');
            assert.equal(await highlighted(), 'any', 'ArrowUp wraps to the last');
            await page.withForcedColors(() => page.screenshot('/tmp/drawer-suggestions-forced-colors.png'));

            // ── A pointer press on a suggestion inserts that one, and focus stays in the textarea ──
            await pressOption(page, 0);
            await page.waitFor(`${q(EDITOR)}.value === 'and '`, { label: 'the pressed suggestion inserted' });
            assert.equal(await page.evaluate(`document.activeElement?.id`), 'drawerFilterTextarea', 'the press leaves focus in the textarea');
            assert.ok(await page.evaluate(`${q('#drawerSuggestions')}.hidden`), 'the list closes on the press');
            assert.equal(await suggestStatus(), 'and inserted');

            // ── A sync between keystrokes keeps the textarea and the editor, and suggestions keep working ──
            await type(page, 'ho');
            await page.waitFor(visible(q('#drawerSuggestions [role="option"]')), { label: 'suggestions for "ho"' });
            await press(page, 'Escape');
            await page.evaluate(`(function(){ ${q(EDITOR)}.__keep = 1; document.getElementById('drawerEditor').__keep = 1; })()`);
            assert.equal(await page.evaluate(SYNC), 'synced', 'a sync arrives while the drawer is open');
            assert.deepEqual(
                await page.evaluate(`[${q(EDITOR)}?.__keep, document.getElementById('drawerEditor')?.__keep, document.activeElement?.id]`),
                [1, 1, 'drawerFilterTextarea'],
                'the sync keeps the textarea, the editor and the focus'
            );
            await type(page, ' an');
            await page.waitFor(visible(q('#drawerSuggestions [data-index="1"]')), { label: 'suggestions after the sync' });
            await pressOption(page, 1);
            await page.waitFor(`${q(EDITOR)}.value === 'and ho any '`, { label: 'the pressed suggestion inserted after the sync' });
            assert.equal(await page.evaluate(`document.activeElement?.id`), 'drawerFilterTextarea', 'the press after the sync leaves focus in the textarea');

            // ── A textarea replaced under the editor is taken over on its next focus ──
            await page.evaluate(`(function(){
                var t = ${q(EDITOR)}, c = t.cloneNode(true);
                t.replaceWith(c);
                c.focus();
                c.setSelectionRange(c.value.length, c.value.length);
            })()`);
            await type(page, 'ho');
            await page.waitFor(visible(q('#drawerSuggestions [role="option"]')), { label: 'suggestions in the new textarea' });
            await press(page, 'Enter');
            assert.equal(await value(page, EDITOR), 'and ho any host ');

            // ── Typing and taking a suggestion within one debounce posts one filter check ──
            const checks = await drawerChecks(page);
            await setEditor(page, '');
            await sleep(800);
            checks.length = 0;
            for (const letter of 'pro') await type(page, letter);
            await page.waitFor(visible(q('#drawerSuggestions [role="option"]')), { label: 'suggestions for "pro"' });
            await press(page, 'Enter');
            assert.equal(await value(page, EDITOR), 'proto ');
            await sleep(1000);
            assert.equal(checks.length, 1, `one filter check per debounce, not ${checks.length}`);

            await setEditor(page, 'host 192.0.2.10');

            // ── Raw filter shows the same text ──
            await page.evaluate(`${q('#drawerTabRaw')}.click()`);
            await page.waitFor(visible(q(RAW)), { label: 'raw textarea' });
            assert.equal(await value(page, RAW), 'host 192.0.2.10');
            assert.equal(await page.evaluate(`${q('#drawerTabRaw')}.getAttribute('aria-selected')`), 'true');
            await page.evaluate(`${q('#drawerTabBuilder')}.click()`);
            await page.waitFor(visible(q(EDITOR)), { label: 'builder textarea' });

            // ── Fields insert at the cursor, spaced, with the placeholder selected ──
            await page.evaluate(`(function(){ var t = ${q(EDITOR)}; t.setSelectionRange(t.value.length, t.value.length); })()`);
            await page.evaluate(`${q('[data-snippet="and <expr>"]')}.click()`);
            assert.equal(await value(page, EDITOR), 'host 192.0.2.10 and <expr>');
            assert.equal(await page.evaluate(`(function(){ var t = ${q(EDITOR)}; return t.value.slice(t.selectionStart, t.selectionEnd); })()`), '<expr>');
            await page.evaluate(`${q('[data-snippet="dst port <n>"]')}.click()`);
            assert.equal(await value(page, EDITOR), 'host 192.0.2.10 and dst port <n>');
            assert.equal(await page.evaluate(`document.activeElement?.id`), 'drawerFilterTextarea', 'the textarea keeps focus');
            await type(page, '443');
            assert.equal(await value(page, EDITOR), 'host 192.0.2.10 and dst port 443');
            await waitForState(page, 'valid');

            // ── An example is inserted; appended without an operator it is invalid ──
            await page.evaluate(`${q('.field-group:last-of-type > summary')}.click()`);
            await page.waitFor(visible(q('[data-snippet="port 53"]')), { label: 'examples' });
            await page.evaluate(`(function(){ var t = ${q(EDITOR)}; t.setSelectionRange(t.value.length, t.value.length); })()`);
            await page.evaluate(`${q('[data-snippet="port 53"]')}.click()`);
            assert.equal(await value(page, EDITOR), 'host 192.0.2.10 and dst port 443 port 53');

            // ── Validation: invalid shows nfdump's message, and Apply and run is disabled ──
            await waitForState(page, 'invalid');
            assert.equal(await page.evaluate(`${q(EDITOR)}.getAttribute('aria-invalid')`), 'true');
            assert.ok(await page.evaluate(`${q('#drawerApplyRun')}.disabled`), 'Apply and run is disabled while invalid');
            assert.notEqual(await page.evaluate(`${q(`${STATUS} .filter-status-shown`)}.textContent`), '');

            // ── Tab selects a snippet's next placeholder, then moves on once none is left ──
            const selection = `(function(){ var t = ${q(EDITOR)}; return t.value.slice(t.selectionStart, t.selectionEnd); })()`;
            await setEditor(page, '');
            await page.evaluate(`${q('[data-snippet="port in [<a> <b>]"]')}.click()`);
            assert.equal(await page.evaluate(selection), '<a>');
            await type(page, '80');
            await press(page, 'Tab');
            assert.equal(await page.evaluate(selection), '<b>');
            assert.equal(await page.evaluate(`${q('#drawerSuggestStatus')}.textContent`), '<b> selected');
            await type(page, '443');
            assert.equal(await value(page, EDITOR), 'port in [80 443]');
            await press(page, 'Tab');
            assert.notEqual(await page.evaluate(`document.activeElement?.id`), 'drawerFilterTextarea', 'Tab leaves once no placeholder is left');

            await setEditor(page, expression);
            await waitForState(page, 'valid');
            await page.waitFor(`!${q('#drawerApplyRun')}.disabled`, { label: 'Apply and run enabled' });
            assert.equal(await page.evaluate(`${q(EDITOR)}.getAttribute('aria-invalid')`), null);

            // ── The estimate for the Flows query: the dev captures of the last year hold files ──
            await page.waitFor(`${q('[data-estimate="drawer"]')}?.dataset.state === 'ready'`, { timeout: 10000, label: 'drawer estimate' });
            const files = await page.evaluate(`${q('[data-estimate="drawer"] .estimate-figures li')}.textContent.trim()`);
            assert.match(files, /^\d[\d,]* files?$/, `the estimate names a file count: ${files}`);

            // ── Save with a name ──
            await page.setInputValue('#drawerSaveName', name);
            await page.evaluate(`${q('#drawerSave')}.click()`);
            await waitForNotice(page, `Saved as ${name}`);
            await page.waitFor(`!!${row(name)}`, { label: 'saved row' });
            assert.equal(await page.evaluate(`${row(name)}.dataset.expression`), expression);
            assert.equal(await page.evaluate(`${row(name)}.dataset.origin`), 'user');

            // A second save of the same text names the first.
            await page.setInputValue('#drawerSaveName', 'duplicate');
            await page.evaluate(`${q('#drawerSave')}.click()`);
            await waitForNotice(page, `Already saved as ${name}`);
            await page.setInputValue('#drawerSaveName', '');

            // ── Rename through the kebab menu: Enter saves, the expression stays ──
            await menuAction(page, name, 'rename');
            await page.waitFor(`document.activeElement?.classList.contains('saved-rename')`, { label: 'rename field focused' });
            await page.evaluate(`document.activeElement.select()`);
            await type(page, renamed);
            await press(page, 'Enter');
            await waitForNotice(page, `Renamed to ${renamed}`);
            await page.waitFor(`!!${row(renamed)}`, { label: 'renamed row' });
            assert.equal(await page.evaluate(`${row(renamed)}.dataset.expression`), expression);

            // ── Edit its expression: the editor loads it, the save row says Save changes ──
            await menuAction(page, renamed, 'edit');
            await page.waitFor(`${q(EDITOR)}.value === ${JSON.stringify(expression)} && ${q('#drawerSave')}.textContent === 'Save changes'`, {
                label: 'edit mode',
            });
            await setEditor(page, edited);
            await page.evaluate(`${q('#drawerSave')}.click()`);
            await waitForNotice(page, `Saved changes to ${renamed}`);
            await page.waitFor(`${row(renamed)}?.dataset.expression === ${JSON.stringify(edited)}`, { label: 'edited expression' });
            await page.waitFor(`${q('#drawerSave')}.textContent === 'Save current filter'`, { label: 'edit mode left' });

            // ── Star: it moves ahead of every unstarred filter ──
            await page.evaluate(`${row(renamed)}.querySelector('.saved-star').click()`);
            await page.waitFor(`${row(renamed)}?.querySelector('.saved-star').getAttribute('aria-pressed') === 'true'`, { label: 'starred' });
            const order = await page.evaluate(
                `[...document.querySelectorAll('#drawerSavedList li[data-filter-id]')].map((li) => [li.dataset.name, li.querySelector('.saved-star').getAttribute('aria-pressed')])`
            );
            const firstUnstarred = order.findIndex(([, pressed]) => pressed !== 'true');
            const mine = order.findIndex(([n]) => n === renamed);
            assert.ok(firstUnstarred === -1 || mine < firstUnstarred, `a starred filter lists before the unstarred ones: ${JSON.stringify(order)}`);

            // ── Search filters the list by name or expression ──
            await page.evaluate(`${q('#drawerSearch')}.focus()`);
            await type(page, stamp);
            await page.waitFor(
                `[...document.querySelectorAll('#drawerSavedList li[data-filter-id]')].filter((li) => li.getClientRects().length).map((li) => li.dataset.name).join() === ${JSON.stringify(renamed)}`,
                { label: 'search shows only the match' }
            );
            await press(page, 'Escape');
            assert.ok(await page.evaluate(`${q(DRAWER)}.open && ${q('#drawerSearch')}.value === ''`), 'Escape in the search clears it');
            await page.waitFor(`${(await savedNames(page)).length} === [...document.querySelectorAll('#drawerSavedList li[data-filter-id]')].filter((li) => li.getClientRects().length).length`, {
                label: 'every row back',
            });

            // ── Apply from the menu loads it; Apply writes the Flows field ──
            await setEditor(page, 'proto udp');
            await menuAction(page, renamed, 'apply');
            await page.waitFor(`${q(EDITOR)}.value === ${JSON.stringify(edited)}`, { label: 'saved filter loaded' });
            await waitForNotice(page, `Loaded ${renamed} into the editor`);
            await page.evaluate(`${q('#drawerApply')}.click()`);
            await closedDrawer(page);
            await page.waitFor(`${q(FLOWS_FIELD)}.value === ${JSON.stringify(edited)}`, { label: 'Flows field written' });
            await page.waitFor(`${q('[data-filter-status="flows"]')}?.dataset.state === 'valid'`, { label: 'Flows field checked' });
            assert.equal(
                await page.evaluate(`${q('[data-filter-status="flows"]')}.getAttribute('aria-live')`),
                'polite',
                'focus is back in the field when Apply changes it, so its answer is announced'
            );
            assert.equal(await page.signalValue('drawer_open'), false, 'Apply tells the server the drawer is closed');

            // ── Escape with changes keeps the draft; Discard draft drops it ──
            await openDrawer(page, 'flows');
            await page.waitFor(`document.activeElement?.id === 'drawerFilterTextarea'`, { label: 'focus in the textarea again' });
            assert.equal(await value(page, EDITOR), edited, 'the drawer opens with the field text');
            await setEditor(page, `${edited} and bytes > 1000`);
            await press(page, 'Escape');
            await closedDrawer(page);
            assert.equal(await value(page, FLOWS_FIELD), edited, 'Escape does not apply');
            await openDrawer(page, 'flows');
            assert.equal(await value(page, EDITOR), `${edited} and bytes > 1000`, 'the draft is restored');
            await page.waitFor(visible(q('.drawer-draft')), { label: 'draft note' });
            await page.evaluate(`${q('.drawer-draft button')}.click()`);
            await page.waitFor(`${q(EDITOR)}.value === ${JSON.stringify(edited)} && !${visible(q('.drawer-draft'))}`, { label: 'draft discarded' });

            // ── Apply and run: the Flows query control goes running ──
            await page.evaluate(`(function(){
                var control = document.querySelector('[data-run="flows"]').closest('.query-control');
                window.__drawerRunStates = [];
                new MutationObserver(function (records) {
                    records.forEach(function (r) { window.__drawerRunStates.push(r.oldValue, control.dataset.queryState); });
                }).observe(control, { attributes: true, attributeFilter: ['data-query-state'], attributeOldValue: true });
            })()`);
            await page.waitFor(`!${q('#drawerApplyRun')}.disabled`, { label: 'Apply and run enabled' });
            await page.evaluate(`${q('#drawerApplyRun')}.click()`);
            await closedDrawer(page);
            await page.waitFor(`window.__drawerRunStates.includes('running')`, { timeout: 10000, label: 'Flows query running' });
            await page.waitFor(`${q('[data-run="flows"]')}.closest('.query-control').dataset.queryState === 'idle'`, {
                timeout: 30000,
                label: 'Flows query done',
            });

            // ── Keyboard: open with Enter, tabs by arrows, a kebab menu by ArrowDown, Escape twice ──
            await page.evaluate(`${q('[data-filter-field="flows"] [data-open-drawer="saved"]')}.focus()`);
            await press(page, 'Enter');
            await page.waitFor(`${q(DRAWER)}.open && document.activeElement?.id === 'drawerSearch'`, { timeout: 10000, label: 'Saved opens on the search' });
            await page.evaluate(`${q('#drawerTabBuilder')}.focus()`);
            await press(page, 'ArrowRight');
            await page.waitFor(`document.activeElement?.id === 'drawerTabRaw' && ${visible(q(RAW))}`, { label: 'ArrowRight selects Raw filter' });
            await press(page, 'ArrowLeft');
            await page.waitFor(`document.activeElement?.id === 'drawerTabBuilder' && ${visible(q(EDITOR))}`, { label: 'ArrowLeft selects Builder' });
            await press(page, 'Tab');
            assert.equal(await page.evaluate(`document.activeElement?.id`), 'drawerFilterTextarea', 'Tab from the tabs reaches the textarea');
            await page.evaluate(`${row(renamed)}.querySelector('[aria-haspopup="menu"]').focus()`);
            await press(page, 'ArrowDown');
            await page.waitFor(`document.activeElement?.dataset.action === 'apply'`, { label: 'ArrowDown opens the menu on Apply' });
            await press(page, 'ArrowDown');
            assert.equal(await page.evaluate(`document.activeElement?.dataset.action`), 'edit');
            await press(page, 'Escape');
            await page.waitFor(`document.activeElement?.getAttribute('aria-haspopup') === 'menu'`, { label: 'Escape returns to the menu button' });
            assert.ok(await page.evaluate(`${q(DRAWER)}.open`), 'the first Escape closes only the menu');
            await press(page, 'Escape');
            await closedDrawer(page);
            await page.waitFor(`document.activeElement?.dataset.openDrawer === 'saved'`, { label: 'focus back on the Saved button' });

            // ── Below 48em the grid stacks: the editor above the saved list ──
            await openDrawer(page, 'flows');
            await page.send('Emulation.setDeviceMetricsOverride', { width: 600, height: 900, deviceScaleFactor: 1, mobile: false });
            await sleep(300);
            const stacked = await page.evaluate(`(function(){
                var e = document.querySelector('.drawer-editor').getBoundingClientRect(), s = document.querySelector('.drawer-saved').getBoundingClientRect();
                return { below: s.top >= e.bottom - 1, width: Math.round(document.getElementById('filter-drawer').getBoundingClientRect().width) };
            })()`);
            await page.send('Emulation.clearDeviceMetricsOverride');
            assert.ok(stacked.below, 'the saved list follows the editor below 48em');
            assert.equal(stacked.width, 600, 'the drawer is full width on a phone');
            await page.withForcedColors(() => page.screenshot('/tmp/drawer-forced-colors.png'));

            // ── Delete through the menu ──
            await deleteSaved(page, renamed);
            await waitForNotice(page, `Deleted ${renamed}`);
            await press(page, 'Escape');
            await closedDrawer(page);

            // ── Alerts: Apply only, no estimate, and the rule form's field gets the text ──
            await page.gotoPage('alerts');
            await page.setInputValue('#alertNfdumpFilter', '');
            await openDrawer(page, 'alert');
            assert.match(await page.evaluate(`${q('#drawerTitle')}.textContent`), /for Alert rule/);
            await page.waitFor(`!${q('[data-estimate="drawer"]')} && !${visible(q('#drawerApplyRun'))}`, { label: 'no estimate or run for an alert' });
            await setEditor(page, 'proto icmp');
            await page.evaluate(`${q('#drawerApply')}.click()`);
            await closedDrawer(page);
            await page.waitFor(`${q('#alertNfdumpFilter')}.value === 'proto icmp'`, { label: 'alert filter written' });
            await page.setInputValue('#alertNfdumpFilter', '');

            const errors = page.realErrors().filter((e) => /drawer|filter-editor|nfsenFilterEditor|filter-field/i.test(e));
            assert.deepEqual(errors, [], `no console errors from the drawer:\n${errors.join('\n')}`);
        } finally {
            await removeSaved(page, (li) => li.name === name || li.name === renamed);
        }
    });

    // ── Datastar keeps every removed Rocket host (D3); a removed editor must not keep its drawer section with it ──
    await withPage(async (page) => {
        await page.navigate(`${BASE}/#/flows`);
        await page.waitForBoot();
        await page.gotoPage('flows');
        const CYCLES = 3;
        for (let i = 0; i < CYCLES; i++) {
            await openDrawer(page, 'flows');
            await page.evaluate(`(window.__drawerSections ??= []).push(new WeakRef(document.querySelector('.drawer-editor')))`);
            await press(page, 'Escape');
            await closedDrawer(page);
            // A sync renders the closed drawer without its editor.
            await page.gotoPage('talkers');
            await page.waitFor(`!document.getElementById('drawerEditor')`, { label: 'the editor removed by a sync' });
            await page.gotoPage('flows');
        }
        for (let i = 0; i < 3; i++) await page.send('HeapProfiler.collectGarbage');
        const alive = await page.evaluate(`window.__drawerSections.map((r, i) => (r.deref() ? i : -1)).filter((i) => i >= 0)`);
        // The last one may still be held by the morph that removed it.
        assert.deepEqual(alive.filter((i) => i < CYCLES - 1), [], `removed editor sections still reachable: ${alive.join(', ')}`);
    });

    // ── The browser's old saved list is imported once, as origin browser, and marked done only once imported ──
    const legacy = [`port 5${stamp.length}${(Date.now() % 900) + 100} and proto udp`, `src port 6${(Date.now() % 900) + 100} and proto tcp`];
    await withPage(async (page) => {
        page.autoAcceptDialogs();
        await page.navigate(`${BASE}/#/flows`);
        await page.waitForBoot();
        await page.evaluate(`(function(){
            localStorage.setItem('stored_filters', ${JSON.stringify(JSON.stringify([...legacy, '  ', legacy[0]]))});
            localStorage.removeItem('nfsen-filters-migrated');
        })()`);
        const flag = () => page.evaluate(`localStorage.getItem('nfsen-filters-migrated')`);

        // Every filter-migrate-local post is paused here: failed, or held and then sent on.
        const HOLD = 4000;
        let mode = 'fail';
        const posts = [];
        page.ws.addEventListener('message', (ev) => {
            const msg = JSON.parse(ev.data);
            if (msg.method !== 'Fetch.requestPaused') return;
            const { requestId } = msg.params;
            posts.push({ mode, at: Date.now() });
            // A reload drops the request, so an answer may find nothing to act on.
            if (mode === 'fail') page.send('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => {});
            else setTimeout(() => page.send('Fetch.continueRequest', { requestId }).catch(() => {}), HOLD);
        });
        await page.send('Fetch.enable', { patterns: [{ urlPattern: '*/_action/filter-migrate-local*', requestStage: 'Request' }] });

        try {
            // A post that never reaches the server (a restart, a network error), retried by Datastar: not marked done.
            await page.reload();
            await page.waitForBoot();
            await until(() => posts.length >= 2, 'the import post and its retry');
            assert.equal(await flag(), null, 'a failed import post leaves the browser unmarked');

            // The next load posts again. While the server has not answered, the browser stays unmarked.
            mode = 'hold';
            const before = posts.length;
            await page.reload();
            await page.waitForBoot();
            await page.waitForPage('flows');
            await until(() => posts.length > before, 'the import post of the next load');
            await sleep(Math.max(0, posts[before].at + HOLD / 2 - Date.now()));
            assert.equal(await flag(), null, 'not marked done before the server has imported');
            await page.waitFor(`localStorage.getItem('nfsen-filters-migrated') === '1'`, { timeout: 15000, label: 'migration flag' });
            assert.equal(await page.signalValue('drawer_import'), '', 'drawer_import is empty afterwards');
            await page.waitFor(`[...document.querySelectorAll('nfsen-toast')].some((t) => String(t.message).includes('Imported 2 saved filters from this browser'))`, {
                label: 'import toast',
            });
            await page.send('Fetch.disable');

            await openDrawer(page, 'flows', 'saved');
            for (const expr of legacy) {
                const rows = await page.evaluate(
                    `[...document.querySelectorAll('#drawerSavedList li[data-filter-id]')].filter((li) => li.dataset.expression === ${JSON.stringify(expr)}).map((li) => li.dataset.origin)`
                );
                assert.deepEqual(rows, ['browser'], `${expr} is imported once, as browser`);
            }
            await press(page, 'Escape');
            await closedDrawer(page);

            // Once per browser: a reload posts nothing.
            const log = await page.requestLog();
            await page.reload();
            await page.waitForBoot();
            await sleep(1500);
            assert.equal(log.count('filter-migrate-local'), 0, 'no second import post');
        } finally {
            await page.send('Fetch.disable').catch(() => {});
            await removeSaved(page, (li) => legacy.includes(li.expression));
        }
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    drawerTest()
        .then(() => console.log('drawer: PASS'))
        .catch((e) => {
            console.error('drawer: FAIL\n', e);
            process.exit(1);
        });
}
