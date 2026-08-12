// Column selector: the "Columns" dropdown used to be pure Bootstrap markup
// (data-bs-toggle="dropdown") long after Bootstrap's JS was dropped from the
// bundle, so clicking it did nothing (#161). Open/close is now driven by a
// browser-local Datastar signal, which is what this asserts -- plus that the
// checkboxes still actually hide a column.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const BTN = '#flowTable .column-selector button';
const MENU = '#flowTable .column-selector-menu';
const MENU_OPEN = `(function(){var m=document.querySelector('${MENU}');return !!m&&m.classList.contains('show')&&m.offsetParent!==null;})()`;

export default async function columnsTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.clickToPanel(`_currentView = 'flows'`, '$_currentView', 'flows');

        await page.clickByText('Year', 'button');
        await page.setSelectValue('#filterFlowsLimit select', 20);
        await page.processData();

        await page.waitFor(`!!document.querySelector('${BTN}')`, { label: 'column selector button' });
        assert.equal(await page.evaluate(MENU_OPEN), false, 'the menu should start closed');

        // Open.
        await page.clickByText('Columns', 'button');
        await page.waitFor(MENU_OPEN, { label: 'menu to open' });
        assert.equal(
            await page.evaluate(`document.querySelector('${BTN}').getAttribute('aria-expanded')`),
            'true',
            'aria-expanded should follow the open state'
        );

        // Popper is gone with Bootstrap's JS, so verify the menu is actually laid
        // out under the button instead of unpositioned beside it -- and that it
        // stays inside the viewport, which Popper used to take care of.
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

        // A click inside the menu must NOT close it -- it is a checkbox list.
        const firstColumn = await page.evaluate(
            `(function(){var c=document.querySelector('${MENU} .column-checkbox');c.click();return c.dataset.columnName;})()`
        );
        assert.equal(await page.evaluate(MENU_OPEN), true, 'clicking a checkbox should keep the menu open');

        // ...and it must hide that column's header + cells.
        await page.waitFor(
            `(function(){
                var th=[...document.querySelectorAll('#flowTable thead th')].find(function(h){return h.textContent.replace(/[▲▼]/g,'').trim()===${JSON.stringify(firstColumn)};});
                return !!th && th.style.display==='none';
            })()`,
            { label: `column "${firstColumn}" to hide` }
        );

        // Outside click closes.
        await page.evaluate(`document.body.click()`);
        await page.waitFor(`!${MENU_OPEN}`, { label: 'menu to close on outside click' });

        // Escape closes.
        await page.clickByText('Columns', 'button');
        await page.waitFor(MENU_OPEN, { label: 'menu to reopen' });
        await page.evaluate(`window.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))`);
        await page.waitFor(`!${MENU_OPEN}`, { label: 'menu to close on Escape' });

        // Restore the column so the test leaves localStorage as it found it.
        await page.clickByText('Columns', 'button');
        await page.evaluate(`document.querySelector('${MENU} .column-checkbox').click()`);
        await page.evaluate(`document.body.click()`);
        await page.waitFor(`!${MENU_OPEN}`, { label: 'menu to close again' });

        // Both tables live on the page at once, so the open state has to be
        // per-table -- one shared signal would open both menus at a time.
        await page.clickToPanel(`_currentView = 'statistics'`, '$_currentView', 'statistics');
        await page.clickByText('Year', 'button');
        await page.processData();
        await page.waitFor(`!!document.querySelector('#statsTable .column-selector button')`, { label: 'stats column selector' });
        await page.evaluate(`document.querySelector('#statsTable .column-selector button').click()`);
        await page.waitFor(
            `document.querySelector('#statsTable .column-selector-menu').classList.contains('show')`,
            { label: 'stats menu to open' }
        );
        assert.equal(await page.evaluate(MENU_OPEN), false, "the Statistics menu should not open the Flows table's menu");

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
