// The result tables' Columns menu on nfsen-controls.js's disclosure contract (4.3.4), one per
// table, and the Original view switch the Top Talkers table keeps.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const BTN = '#flowTable .column-selector button';
const MENU = '#flowTable .column-selector-menu';
const MENU_OPEN = `(function(){var m=document.querySelector('${MENU}');return !!m&&m.hasAttribute('data-open')&&m.offsetParent!==null;})()`;

async function press(page, key, code = key, keyCode = 0) {
    for (const type of ['keyDown', 'keyUp']) {
        await page.send('Input.dispatchKeyEvent', { type, key, code, windowsVirtualKeyCode: keyCode });
    }
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

/** A real pointer press at a point, which is what closes a menu from outside. */
async function clickAt(page, x, y) {
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
}

export default async function columnsTest() {
    await withPage(async (page) => {
        await page.navigate(`${BASE}/`);
        await page.waitForBoot();
        await page.gotoPage('flows');

        await page.setRangePreset('1y');
        await page.setSelectValue('#filterFlowsLimit select', 20);
        await page.runQuery('flows', { timeout: 60000 });

        await page.waitFor(`!!document.querySelector('${BTN}')`, { timeout: 15000, label: 'column selector button' });
        await assertRocketHost(page, 'flowTable');
        assert.equal(await page.evaluate(MENU_OPEN), false, 'the menu should start closed');
        assert.equal(
            await page.evaluate(`document.querySelector('${BTN}').getAttribute('aria-controls')`),
            'flowTable-columns',
            'the toggle names its list, by an id derived from the table id'
        );

        // Open.
        await page.evaluate(`document.querySelector('${BTN}').click()`);
        await page.waitFor(MENU_OPEN, { label: 'menu to open' });
        assert.equal(
            await page.evaluate(`document.querySelector('${BTN}').getAttribute('aria-expanded')`),
            'true',
            'aria-expanded follows the open state'
        );

        // Laid out under the button, right-aligned with it, inside the viewport.
        const box = JSON.parse(
            await page.evaluate(`(function(){
                var b=document.querySelector('${BTN}').getBoundingClientRect();
                var m=document.querySelector('${MENU}').getBoundingClientRect();
                return JSON.stringify({below:m.top>=b.bottom-1, rightAligned:Math.abs(m.right-b.right)<2,
                    inViewport:m.left>=0&&m.right<=document.documentElement.clientWidth, w:m.width, h:m.height});
            })()`)
        );
        assert.ok(box.w > 0 && box.h > 0, `expected a rendered menu box, got ${JSON.stringify(box)}`);
        assert.ok(box.below, `expected the menu below the button, got ${JSON.stringify(box)}`);
        assert.ok(box.rightAligned, `expected the menu right-aligned with the button, got ${JSON.stringify(box)}`);
        assert.ok(box.inViewport, `expected the menu to fit in the viewport, got ${JSON.stringify(box)}`);

        // A click inside the menu keeps it open; the column goes by its key.
        const key = await page.evaluate(
            `(function(){var c=document.querySelector('${MENU} .column-checkbox');c.click();return c.dataset.columnKey;})()`
        );
        assert.equal(await page.evaluate(MENU_OPEN), true, 'clicking a checkbox keeps the menu open');
        const hidden = `(function(){
            var th=document.querySelector('#flowTable thead th[data-original-title=${JSON.stringify(key)}]');
            var i=[...th.parentNode.children].indexOf(th);
            var cells=[...document.querySelectorAll('#flowTable tbody tr')].map(function(r){return r.cells[i];});
            return th.hidden && cells.length>0 && cells.every(function(c){return c.hidden && c.getClientRects().length===0;});
        })()`;
        await page.waitFor(hidden, { label: `column "${key}" to hide` });
        const stored = await page.evaluate(`JSON.parse(localStorage.getItem('nfsen-table-hidden-columns-flowTable'))`);
        assert.deepEqual(stored, [key], 'the choice is stored by column key');
        assert.equal(await page.evaluate(`document.querySelector('${MENU} [data-column-all]').checked`), false, 'Show all is unchecked');

        // An outside press closes.
        const outside = JSON.parse(
            await page.evaluate(
                `JSON.stringify((function(){var r=document.getElementById('pageTitle').getBoundingClientRect();return {x:r.left+5,y:r.top+5};})())`
            )
        );
        await clickAt(page, outside.x, outside.y);
        await page.waitFor(`!${MENU_OPEN}`, { label: 'menu to close on outside press' });

        // Keyboard: Escape closes and gives the focus back to the toggle.
        await page.evaluate(`document.querySelector('${BTN}').click()`);
        await page.waitFor(MENU_OPEN, { label: 'menu to reopen' });
        await page.evaluate(`document.querySelector('${MENU} [data-column-all]').focus()`);
        await press(page, 'Escape', 'Escape', 27);
        await page.waitFor(`!${MENU_OPEN}`, { label: 'menu to close on Escape' });
        assert.ok(await page.evaluate(`document.activeElement === document.querySelector('${BTN}')`), 'focus is back on the toggle');

        // Show all brings the column back and leaves localStorage as it found it.
        await page.evaluate(`document.querySelector('${BTN}').click()`);
        await page.waitFor(MENU_OPEN, { label: 'menu to open again' });
        await page.evaluate(`document.querySelector('${MENU} [data-column-all]').click()`);
        await page.waitFor(`!${hidden}`, { label: `column "${key}" to come back` });
        assert.deepEqual(await page.evaluate(`JSON.parse(localStorage.getItem('nfsen-table-hidden-columns-flowTable'))`), []);
        await clickAt(page, outside.x, outside.y);
        await page.waitFor(`!${MENU_OPEN}`, { label: 'menu to close again' });

        // The Top Talkers table has its own menu, and its Original view switch works.
        await page.gotoPage('talkers');
        await page.setRangePreset('1y');
        await page.runQuery('talkers', { timeout: 60000 });
        await page.waitFor(`!!document.querySelector('#statsTable .column-selector button')`, {
            timeout: 15000,
            label: 'stats column selector',
        });
        await assertRocketHost(page, 'statsTable');
        await page.evaluate(`document.querySelector('#statsTable .column-selector button').click()`);
        await page.waitFor(`document.querySelector('#statsTable .column-selector-menu').hasAttribute('data-open')`, {
            label: 'stats menu to open',
        });
        assert.equal(
            await page.evaluate(`document.querySelector('#statsTable .column-selector button').getAttribute('aria-controls')`),
            'statsTable-columns'
        );
        assert.equal(await page.evaluate(MENU_OPEN), false, "the Top Talkers menu does not open the Flows table's menu");
        await press(page, 'Escape', 'Escape', 27);

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
        })`);
        assert.deepEqual(
            JSON.parse(original),
            { pressed: 'true', text: true, table: false },
            "the Original view shows nfdump's text instead of the table"
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
            t.querySelector('button[data-view="original"]').click();
            return t.dataset.result;
        })()`);
        await page.runQuery('talkers', { timeout: 60000 });
        await page.waitFor(`document.getElementById('statsTable')?.dataset.result !== ${JSON.stringify(was)}`, {
            timeout: 15000,
            label: 'the second Top Talkers result',
        });
        const rerun = await page.evaluate(`(function(){
            var t = document.getElementById('statsTable');
            var wrap = t.querySelector('.table-wrap');
            return {
                reused: t === window.__statsHost, view: t.dataset.view,
                pressed: t.querySelector('button[data-view="table"]').getAttribute('aria-pressed'),
                original: t.querySelector('.original').hidden, table: wrap.getClientRects().length > 0,
                overflow: t.hasAttribute('data-overflow') === wrap.scrollWidth > wrap.clientWidth + 1,
            };
        })()`);
        assert.deepEqual(
            rerun,
            { reused: true, view: 'table', pressed: 'true', original: true, table: true, overflow: true },
            'a new result in the reused host opens in the table, its scrollbar state measured again'
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
