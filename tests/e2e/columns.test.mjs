// The Columns pickers: the Flows list's, an sb-popover the server renders next to the list (ADOPT-VSCROLL 3.7), and
// the Top Talkers table's, which nfsen-table.js builds (POPOVER-SPEC PA8), with the Original view switch it keeps.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const POPOVER = '#flowTable-columnsPopover';
const BTN = '#flowTable-columnsPopover [slot="trigger"]';
const MENU = '#flowTable-columns';
const LIST = `document.querySelector('sb-virtual-scroll[id^="flowRows-"]')`;
const STORED = `JSON.parse(localStorage.getItem('nfsen-persist:_flows_hidden') ?? '[]')`;
const MENU_OPEN = `(document.querySelector('${POPOVER}')?.open === true && !!document.querySelector('${MENU}')?.checkVisibility())`;
const STATS_POPOVER = '#statsTable sb-popover.column-selector';
const KEYS = { Enter: 13, Escape: 27, ArrowDown: 40, ArrowUp: 38, Home: 36, End: 35 };

async function press(page, key) {
    const base = { key, code: key, windowsVirtualKeyCode: KEYS[key], nativeVirtualKeyCode: KEYS[key] };
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(key === 'Enter' ? { text: '\r' } : {}) });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

/** The table is a Rocket host whose shadow root only slots its light DOM (ROCKET-SPEC 6.8, shape A). */
async function assertRocketHost(page, id) {
    const host = await page.evaluate(`(function(){
        var t = document.getElementById(${JSON.stringify(id)});
        return { id: t.rocketInstanceId, shadow: t.shadowRoot ? [...t.shadowRoot.childNodes].map(function(n){ return n.nodeName; }).join() : null };
    })()`);
    assert.ok(typeof host.id === 'string' && host.id !== '', `#${id} is a Rocket host, got ${host.id}`);
    assert.equal(host.shadow, 'SLOT', `#${id}'s shadow root holds only its slot`);
}

/** The popover contract (PC1, PC2, PC5, PC6, PC7) as the script builds it for table `id`. */
async function assertContract(page, id) {
    const got = await page.evaluate(`(function(){
        var table = document.getElementById(${JSON.stringify(id)});
        var hosts = table.querySelectorAll('sb-popover');
        var p = hosts[0];
        var slotted = p.shadowRoot.querySelector('slot[name="trigger"]').assignedElements();
        var t = slotted[0];
        var list = document.getElementById(t.getAttribute('aria-controls'));
        return {
            hosts: hosts.length, id: p.id, className: p.className, rocket: typeof p.rocketInstanceId === 'string',
            placeholder: p.parentElement.classList.contains('column-selector-placeholder') && p.parentElement.childNodes.length === 1,
            label: p.getAttribute('label'), placement: p.getAttribute('placement'),
            serverState: ['open', 'mode', 'arrow', 'name'].filter(function(a){ return p.hasAttribute(a); }),
            slotted: slotted.length, trigger: t.localName + '.' + t.className + '[type=' + t.type + ']', text: t.textContent,
            haspopup: t.getAttribute('aria-haspopup'), controls: t.getAttribute('aria-controls'),
            list: !!list && list.localName === 'ul' && list.classList.contains('popover-list') && list.parentElement === p,
            menuAncestor: !!p.closest('.menu'),
            roles: p.querySelectorAll('[role="menu"], [role="menuitem"], [tabindex="-1"]').length,
        };
    })()`);
    assert.deepEqual(
        got,
        {
            hosts: 1,
            id: `${id}-columnsPopover`,
            className: 'column-selector',
            rocket: true,
            placeholder: true,
            label: 'Columns to show',
            placement: 'bottom-end',
            serverState: [],
            slotted: 1,
            trigger: 'button.menu-toggle[type=button]',
            text: 'Columns',
            haspopup: 'dialog',
            controls: `${id}-columns`,
            list: true,
            menuAncestor: false,
            roles: 0,
        },
        `#${id}'s Columns picker keeps to the popover contract`
    );
}

/** A real pointer press at a point, which is what closes a popover from outside. */
async function clickAt(page, x, y) {
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
}

/** A press on the page title, brought into view first: nothing there takes the press. */
async function pressOutside(page) {
    const { x, y } = await page.evaluate(`(function(){
        var title = document.getElementById('pageTitle');
        title.scrollIntoView({ block: 'nearest' });
        var r = title.getBoundingClientRect();
        return { x: r.left + 5, y: r.top + 5 };
    })()`);
    await clickAt(page, x, y);
}

const focusIs = (selector) => `document.activeElement === document.querySelector('${selector}')`;

/** Page code that counts, on the table, each removal of its Columns popover or of an ancestor, and each sb-close. */
const watchColumns = (id) => `(function(){
    var table = document.getElementById(${JSON.stringify(id)});
    var p = table.querySelector('sb-popover.column-selector');
    var log = table.__e2eColumnsWatch = { removed: 0, closed: [] };
    new MutationObserver(function(records){
        records.forEach(function(r){ r.removedNodes.forEach(function(n){ if (n.contains(p)) log.removed++; }); });
    }).observe(table, { childList: true, subtree: true });
    p.addEventListener('sb-close', function(e){ log.closed.push(e.detail && e.detail.reason); });
})();`;

/** The Flows list's Columns picker keeps to the popover contract (PC1, PC2, PC5, PC6, PC7). */
async function assertFlowsContract(page) {
    const got = await page.evaluate(`(function(){
        var p = document.querySelector('${POPOVER}');
        var slotted = p.shadowRoot.querySelector('slot[name="trigger"]').assignedElements();
        var t = slotted[0];
        var list = document.getElementById(t.getAttribute('aria-controls'));
        return {
            className: p.className, rocket: typeof p.rocketInstanceId === 'string', tools: p.parentElement.id,
            label: p.getAttribute('label'), placement: p.getAttribute('placement'),
            serverState: ['open', 'mode', 'arrow', 'name'].filter(function(a){ return p.hasAttribute(a); }),
            slotted: slotted.length, trigger: t.localName + '.' + t.className + '[type=' + t.type + ']', text: t.textContent.trim(),
            haspopup: t.getAttribute('aria-haspopup'), controls: t.getAttribute('aria-controls'),
            list: !!list && list.localName === 'ul' && list.classList.contains('popover-list') && list.parentElement === p,
            roles: p.querySelectorAll('[role="menu"], [role="menuitem"], [tabindex="-1"]').length,
        };
    })()`);
    assert.deepEqual(
        got,
        {
            className: 'column-selector',
            rocket: true,
            tools: 'flowsListTools',
            label: 'Columns to show',
            placement: 'bottom-end',
            serverState: [],
            slotted: 1,
            trigger: 'button.menu-toggle[type=button]',
            text: 'Columns',
            haspopup: 'dialog',
            controls: 'flowTable-columns',
            list: true,
            roles: 0,
        },
        "the Flows list's Columns picker keeps to the popover contract"
    );
}

export default async function columnsTest() {
    await withPage(async (page) => {
        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        await page.gotoPage('flows');

        await page.setRangePreset('1y');
        await page.setSelectValue('#filterFlowsLimit select', 20);
        await page.runQuery('flows', { timeout: 60000 });

        await page.waitFor(`!!document.querySelector('${BTN}') && !!${LIST}?.hasAttribute('total')`, {
            timeout: 15000,
            label: 'column selector button',
        });
        await assertFlowsContract(page);
        assert.equal(await page.evaluate(MENU_OPEN), false, 'the popover should start closed');
        assert.equal(await page.evaluate(`document.querySelector('${BTN}').getAttribute('aria-expanded')`), 'false');

        // Open, with room below: the popover flips above its trigger only when the panel does not fit under it.
        await page.evaluate(`(function(){
            var b = document.querySelector('${BTN}');
            b.scrollIntoView({ block: 'center' });
            b.click();
        })()`);
        await page.waitFor(MENU_OPEN, { label: 'popover to open' });
        assert.equal(
            await page.evaluate(`document.querySelector('${BTN}').getAttribute('aria-expanded')`),
            'true',
            'aria-expanded follows the open state'
        );
        await page.waitFor(focusIs(`${MENU} [data-column-all]`), { label: 'focus on Show all, the first item' });

        // Laid out under the button, right-aligned with it, inside the viewport; the panel scrolls, not the list.
        const box = await page.evaluate(`(function(){
            var b = document.querySelector('${BTN}').getBoundingClientRect();
            var panel = document.querySelector('${POPOVER}').shadowRoot.querySelector('[part~="panel"]');
            var m = panel.getBoundingClientRect();
            var list = document.querySelector('${MENU}');
            var rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
            var view = document.documentElement;
            return { below: m.top >= b.bottom - 1, rightAligned: Math.abs(m.right - b.right) < 2,
                inViewport: m.left >= 0 && m.right <= view.clientWidth && m.top >= 0 && m.bottom <= view.clientHeight,
                capped: m.height <= 20 * rem + 1, listScrolls: list.scrollHeight > list.clientHeight + 1, w: m.width, h: m.height };
        })()`);
        assert.ok(box.w > 0 && box.h > 0, `expected a rendered panel, got ${JSON.stringify(box)}`);
        assert.ok(box.below, `expected the panel below the button, got ${JSON.stringify(box)}`);
        assert.ok(box.rightAligned, `expected the panel right-aligned with the button, got ${JSON.stringify(box)}`);
        assert.ok(box.inViewport, `expected the panel to fit in the viewport, got ${JSON.stringify(box)}`);
        assert.ok(box.capped && !box.listScrolls, `expected a panel of at most 20rem that scrolls itself, got ${JSON.stringify(box)}`);

        // A click inside the popover keeps it open; the column goes by its key, from the header and every row.
        const key = await page.evaluate(
            `(function(){var c=document.querySelector('${MENU} .column-checkbox');c.click();return c.dataset.columnKey;})()`
        );
        assert.equal(await page.evaluate(MENU_OPEN), true, 'clicking a checkbox keeps the popover open');
        const hidden = `(function(){
            var h = ${LIST};
            if (!h || h.querySelector('button[data-sort-key=${JSON.stringify(key)}]')) return false;
            var head = h.querySelectorAll('[slot="header"] [role="columnheader"]').length;
            var rows = [...h.children].filter(function(el){ return !el.slot; });
            return rows.length > 0 && rows.every(function(r){ return r.children.length === head; });
        })()`;
        await page.waitFor(hidden, { label: `column "${key}" to hide` });
        assert.deepEqual(await page.evaluate(STORED), [key], 'the choice is kept in the browser by column key');
        assert.equal(await page.evaluate(`document.querySelector('${MENU} [data-column-all]').checked`), false, 'Show all is unchecked');

        // An outside press closes.
        await pressOutside(page);
        await page.waitFor(`!${MENU_OPEN}`, { label: 'popover to close on outside press' });
        assert.equal(await page.evaluate(`document.querySelector('${BTN}').getAttribute('aria-expanded')`), 'false');

        // Escape closes and gives the focus back to the trigger.
        await page.evaluate(`document.querySelector('${BTN}').click()`);
        await page.waitFor(MENU_OPEN, { label: 'popover to reopen' });
        await page.evaluate(`document.querySelector('${MENU} [data-column-all]').focus()`);
        await press(page, 'Escape');
        await page.waitFor(`!${MENU_OPEN}`, { label: 'popover to close on Escape' });
        assert.ok(await page.evaluate(focusIs(BTN)), 'focus is back on the trigger');

        // Keyboard: Enter opens on Show all, the arrows, Home and End walk the checkboxes, Escape returns.
        await page.evaluate(`document.querySelector('${BTN}').focus()`);
        await press(page, 'Enter');
        await page.waitFor(`${MENU_OPEN} && ${focusIs(`${MENU} [data-column-all]`)}`, { label: 'Enter to open on Show all' });
        await press(page, 'ArrowDown');
        await page.waitFor(focusIs(`${MENU} .column-checkbox`), { label: 'ArrowDown to the first column' });
        await press(page, 'End');
        await page.waitFor(`document.activeElement === [...document.querySelectorAll('${MENU} .column-checkbox')].at(-1)`, {
            label: 'End to the last column',
        });
        await press(page, 'ArrowDown');
        await page.waitFor(focusIs(`${MENU} [data-column-all]`), { label: 'ArrowDown to wrap to Show all' });
        await press(page, 'Escape');
        await page.waitFor(`!${MENU_OPEN} && ${focusIs(BTN)}`, { label: 'Escape to close and return to the trigger' });
        await press(page, 'ArrowUp');
        await page.waitFor(`${MENU_OPEN} && document.activeElement === [...document.querySelectorAll('${MENU} .column-checkbox')].at(-1)`, {
            label: 'ArrowUp on the trigger to open on the last column',
        });

        // A sync morphs the page around the list: the popover stays open, the focus where it was.
        await press(page, 'ArrowUp');
        const focused = await page.evaluate(`(function(){
            window.__e2eFocused = document.activeElement;
            window.__e2ePopover = document.querySelector('${POPOVER}');
            return document.activeElement.dataset.columnKey;
        })()`);
        await page.syncNow('flows');
        assert.deepEqual(
            await page.evaluate(`({ open: ${MENU_OPEN}, focus: document.activeElement === window.__e2eFocused,
                same: document.querySelector('${POPOVER}') === window.__e2ePopover })`),
            { open: true, focus: true, same: true },
            `after a sync the popover is still open with the focus on "${focused}"`
        );
        await press(page, 'Escape');
        await page.waitFor(`!${MENU_OPEN}`, { label: 'popover to close after the sync' });

        // Show all brings the column back and empties the kept choice.
        await page.evaluate(`document.querySelector('${BTN}').click()`);
        await page.waitFor(MENU_OPEN, { label: 'popover to open again' });
        await page.evaluate(`document.querySelector('${MENU} [data-column-all]').click()`);
        await page.waitFor(`!${hidden}`, { label: `column "${key}" to come back` });
        assert.deepEqual(await page.evaluate(STORED), []);

        // A second run lands while the popover is open: the morph keeps the popover, its trigger, its list and the
        // focused box by their ids, and the new list carries the kept choice.
        await page.evaluate(`document.querySelector('${MENU} .column-checkbox').click()`);
        await page.waitFor(hidden, { label: `column "${key}" to hide before the second run` });
        const keyBox = `#flowTable-col-${key}`;
        const firstList = await page.evaluate(`(function(){
            var p = window.__e2eColumns = document.querySelector('${POPOVER}');
            window.__e2eTrigger = p.querySelector('[slot="trigger"]');
            window.__e2eList = p.querySelector('.column-selector-menu');
            window.__e2eBox = document.querySelector('${keyBox}');
            window.__e2eClosed = [];
            p.addEventListener('sb-close', function(e){ window.__e2eClosed.push(e.detail && e.detail.reason); });
            window.__e2eBox.focus();
            return ${LIST}.id;
        })()`);
        assert.ok(await page.evaluate(`${MENU_OPEN} && ${focusIs(keyBox)}`), 'the popover is open before the second run');
        await page.runQuery('flows', { timeout: 60000 });
        await page.waitFor(`${LIST}?.id !== ${JSON.stringify(firstList)} && ${LIST}?.hasAttribute('total')`, {
            timeout: 15000,
            label: 'the second Flows result',
        });
        await page.waitFor(hidden, { label: `column "${key}" hidden in the second result` });
        await assertFlowsContract(page);
        const rerun = await page.evaluate(`(function(){
            var p = document.querySelector('${POPOVER}');
            var b = document.querySelector('${keyBox}');
            return { popover: p === window.__e2eColumns, trigger: p.querySelector('[slot="trigger"]') === window.__e2eTrigger,
                list: p.querySelector('.column-selector-menu') === window.__e2eList, open: ${MENU_OPEN},
                box: b === window.__e2eBox, focus: document.activeElement === b, unchecked: !!b && !b.checked,
                all: p.querySelector('[data-column-all]').checked, closed: window.__e2eClosed };
        })()`);
        assert.deepEqual(
            rerun,
            { popover: true, trigger: true, list: true, open: true, box: true, focus: true, unchecked: true, all: false, closed: [] },
            'the second result keeps the open popover, its trigger, its list and the focused box, with the kept choice'
        );
        await page.evaluate(`document.querySelector('${MENU} [data-column-all]').click()`);
        await page.waitFor(`!${hidden}`, { label: `column "${key}" back in the second result` });
        assert.deepEqual(await page.evaluate(STORED), []);
        await press(page, 'Escape');
        await page.waitFor(`!${MENU_OPEN} && ${focusIs(BTN)}`, { label: 'the kept popover to close on Escape' });
        await page.evaluate(`document.querySelector('${BTN}').click()`);
        await page.waitFor(`${MENU_OPEN} && ${focusIs(`${MENU} [data-column-all]`)}`, { label: 'the kept popover to open again' });
        await press(page, 'Escape');
        await page.waitFor(`!${MENU_OPEN}`, { label: 'the kept popover to close' });

        // The Top Talkers table has its own popover, and its Original view switch works.
        await page.gotoPage('talkers');
        await page.setRangePreset('1y');
        await page.runQuery('talkers', { timeout: 60000 });
        await page.waitFor(`!!document.querySelector('#statsTable .column-selector button')`, {
            timeout: 15000,
            label: 'stats column selector',
        });
        await assertRocketHost(page, 'statsTable');
        await assertContract(page, 'statsTable');
        await page.evaluate(`document.querySelector('#statsTable .column-selector button').click()`);
        await page.waitFor(`document.querySelector('${STATS_POPOVER}').open === true`, { label: 'stats popover to open' });
        assert.equal(await page.evaluate(MENU_OPEN), false, "the Top Talkers popover does not open the Flows table's");
        await press(page, 'Escape');
        await page.waitFor(`document.querySelector('${STATS_POPOVER}').open === false`, { label: 'stats popover to close on Escape' });

        // StatsActions passes nfdump's text, so the switch must be there.
        assert.equal(
            await page.evaluate(`!!document.querySelector('#statsTable button[data-view="original"]')`),
            true,
            'the Top Talkers table has its Original view switch'
        );
        await page.evaluate(`document.querySelector('#statsTable button[data-view="original"]').click()`);
        const original = await page.evaluate(`JSON.stringify({
            pressed: document.querySelector('#statsTable button[data-view="original"]').getAttribute('aria-pressed'),
            text: document.querySelector('#statsTable .original').getClientRects().length > 0,
            table: document.querySelector('#statsTable .table-wrap').getClientRects().length > 0,
            columns: document.querySelector('${STATS_POPOVER}').getClientRects().length > 0,
        })`);
        assert.deepEqual(
            JSON.parse(original),
            { pressed: 'true', text: true, table: false, columns: false },
            "the Original view shows nfdump's text instead of the table, without the Columns trigger"
        );
        await page.evaluate(`document.querySelector('#statsTable button[data-view="table"]').click()`);
        assert.equal(
            await page.evaluate(`document.querySelector('#statsTable .table-wrap').getClientRects().length > 0`),
            true,
            'and back to the table'
        );

        // A second run reuses the host; its morph drops data-view and data-overflow, and the rebuild sets both again
        // for the new result, which opens in the table (so neither needs data-preserve-attr, K4).
        const was = await page.evaluate(`(function(){
            var t = window.__statsHost = document.getElementById('statsTable');
            window.__statsColumns = t.querySelector('sb-popover.column-selector');
            ${watchColumns('statsTable')}
            t.querySelector('button[data-view="original"]').click();
            return t.dataset.result;
        })()`);
        await page.runQuery('talkers', { timeout: 60000 });
        await page.waitFor(`document.getElementById('statsTable')?.dataset.result !== ${JSON.stringify(was)}`, {
            timeout: 15000,
            label: 'the second Top Talkers result',
        });
        const rerunStats = await page.evaluate(`(function(){
            var t = document.getElementById('statsTable');
            var wrap = t.querySelector('.table-wrap');
            return {
                reused: t === window.__statsHost, view: t.dataset.view,
                pressed: t.querySelector('button[data-view="table"]').getAttribute('aria-pressed'),
                original: t.querySelector('.original').hidden, table: wrap.getClientRects().length > 0,
                overflow: t.hasAttribute('data-overflow') === wrap.scrollWidth > wrap.clientWidth + 1,
                columns: t.querySelector('sb-popover.column-selector') === window.__statsColumns,
                watched: t.__e2eColumnsWatch,
            };
        })()`);
        assert.deepEqual(
            rerunStats,
            {
                reused: true,
                view: 'table',
                pressed: 'true',
                original: true,
                table: true,
                overflow: true,
                columns: true,
                watched: { removed: 0, closed: [] },
            },
            'a new result in the reused host opens in the table, its scrollbar state measured again, its Columns popover kept'
        );

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the Columns test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    columnsTest()
        .then(() => console.log('columns: PASS'))
        .catch((e) => {
            console.error('columns: FAIL\n', e);
            process.exit(1);
        });
}
