// Smoke test: the app boots and every top-level tab is reachable with no
// console errors. This is the cheapest, highest-signal E2E check -- if this
// fails, nothing else in the suite is worth running.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

export default async function smokeTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitFor(`document.querySelector('ul#viewList')`, { label: 'nav to render' });

        const title = await page.evaluate('document.title');
        assert.match(title, /nfsen-ng/, `expected page title to mention nfsen-ng, got: ${title}`);

        const defaultView = await page.evaluate(`document.querySelector('a.nav-link.active')?.textContent.trim()`);
        assert.match(defaultView, /Graphs/, `expected Graphs to be the default active tab, got: ${defaultView}`);

        // Click through every data tab + Settings and back to Graphs, confirming
        // each becomes the active nav link and its view container becomes visible.
        const tabs = [
            { click: `_currentView = 'flows'`, view: 'flows' },
            { click: `_currentView = 'statistics'`, view: 'statistics' },
            { click: `_currentView = 'sankey'`, view: 'sankey' },
            { click: `_currentView = 'settings'`, view: 'settings' },
            { click: `_currentView = 'graphs'`, view: 'graphs' },
        ];
        for (const tab of tabs) {
            await page.clickByAttr(tab.click);
            await page.waitForPanel('$_currentView', tab.view, { timeout: 3000 });
        }

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during smoke test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    smokeTest()
        .then(() => console.log('smoke: PASS'))
        .catch((e) => {
            console.error('smoke: FAIL\n', e);
            process.exit(1);
        });
}
