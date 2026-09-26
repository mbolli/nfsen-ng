// Smoke test: every page from the sidebar and the tab bar without console errors, status in
// words, the theme menu (D8), the IP modal across a sync, the collapsed sidebar across a reload.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const TITLES = {
    overview: 'Overview',
    talkers: 'Top Talkers',
    flows: 'Flows',
    conversations: 'Conversations',
    alerts: 'Alerts',
    health: 'Health',
    settings: 'Settings',
};
const PAGES = Object.keys(TITLES);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function press(page, key, modifiers = 0) {
    const codes = { Escape: 27, Enter: 13, Tab: 9, ArrowDown: 40 };
    const base = { key, code: key, windowsVirtualKeyCode: codes[key], nativeVirtualKeyCode: codes[key], modifiers };
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(key === 'Enter' ? { text: '\r' } : {}) });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

async function setOsTheme(page, scheme) {
    await page.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: scheme }] });
}

/** The theme as the page shows it: the resolved attribute, the stored choice and the pressed item. */
async function theme(page) {
    return page.evaluate(`({
        theme: document.documentElement.dataset.theme,
        stored: localStorage.getItem('nfsen-theme'),
        pressed: [...document.querySelectorAll('#themeMenuList [data-theme-choice]')].filter((b) => b.getAttribute('aria-pressed') === 'true').map((b) => b.dataset.themeChoice),
    })`);
}

/** Posts refresh-graphs for `pageId` and resolves once the sync it causes has morphed the page. */
function syncAs(pageId) {
    return `(async function(){
        var html = document.documentElement.outerHTML;
        var ctx = (html.match(/via_ctx&quot;:&quot;([^&]+)&quot;/) || html.match(/via_ctx":"([^"]+)"/) || [])[1];
        var pageSignal = (html.match(/\\bpage____[a-z0-9]+/) || [])[0];
        var url = (html.match(/[^'"\\s]*_action\\/refresh-graphs[A-Za-z0-9-]*/) || [])[0];
        if (!ctx || !pageSignal || !url) return 'missing';
        var probe = document.getElementById('page-content');
        probe.setAttribute('data-probe', '');
        var body = { via_ctx: ctx };
        body[pageSignal] = ${JSON.stringify(pageId)};
        await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Datastar-Request': 'true' }, body: JSON.stringify(body) });
        for (var i = 0; i < 100 && probe.isConnected && probe.hasAttribute('data-probe'); i++) await new Promise(function(r){ setTimeout(r, 100); });
        return probe.hasAttribute('data-probe') ? 'no sync' : 'synced';
    })()`;
}

/** Posts refresh-graphs as another page, so the next sync renders a page the client has left (1.2). */
const STALE_SYNC = `(async function(){
    var html = document.documentElement.outerHTML;
    var ctx = (html.match(/via_ctx&quot;:&quot;([^&]+)&quot;/) || html.match(/via_ctx":"([^"]+)"/) || [])[1];
    var pageId = (html.match(/\\bpage____[a-z0-9]+/) || [])[0];
    var url = (html.match(/[^'"\\s]*_action\\/refresh-graphs[A-Za-z0-9-]*/) || [])[0];
    if (!ctx || !pageId || !url) return 'missing ' + JSON.stringify({ ctx: ctx, pageId: pageId, url: url });
    var body = { via_ctx: ctx };
    body[pageId] = 'overview';
    var res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Datastar-Request': 'true' }, body: JSON.stringify(body) });
    return res.status;
})()`;

export default async function smokeTest() {
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitFor(`document.querySelector('.sidebar-nav')`, { label: 'sidebar to render' });
        await page.waitForBoot();

        assert.deepEqual(page.realErrors(), [], 'no console error before the first SSE sync');
        // The default view is a preference (Overview unless Settings changed it); the brand names it.
        const home = (await page.evaluate(`document.querySelector('.sidebar-brand').getAttribute('href')`)).slice(2);
        assert.ok(TITLES[home], `the brand links to a page: #/${home}`);
        assert.equal(await page.evaluate('document.title'), `${TITLES[home]} · nfsen-ng`);

        // No status by colour alone (2.1): every glyph in the shell comes with words.
        const status = await page.evaluate(`({
            footer: [...document.querySelectorAll('.status-footer .status-item')].map((i) => i.textContent.replace(/\\s+/g, ' ').trim()),
            dots: [...document.querySelectorAll('.status-footer .status-dot')].map((d) => d.dataset.level),
            badge: [...document.querySelectorAll('.sidebar .nav-badge')].map((b) => b.textContent.replace(/\\s+/g, ' ').trim()),
            health: [...document.querySelectorAll('.sidebar .nav-dot')].map((d) => d.textContent.trim()),
            import: document.querySelector('.sidebar .nav-import')?.textContent.trim(),
        })`);
        assert.equal(status.footer.length, 2, 'the footer shows the capture and the daemon status');
        assert.match(status.footer[0], /^nfcapd (ok|stale|no data)$/, `capture status in words, got "${status.footer[0]}"`);
        assert.match(status.footer[1], /^daemon (ok|starting|disabled)$/, `daemon status in words, got "${status.footer[1]}"`);
        assert.ok(
            status.dots.every((l) => ['success', 'warning', 'error', 'neutral'].includes(l)),
            `glyph levels ${status.dots}`
        );
        assert.ok(
            status.badge.every((t) => /^\d+ rules? firing$/.test(t)),
            `the Alerts count says what it counts: ${status.badge}`
        );
        assert.ok(
            status.health.every((t) => /^Health: /.test(t)),
            `the Health glyph says the level: ${status.health}`
        );
        assert.equal(status.import, 'Import running');
        const current = await page.evaluate(`document.querySelector('.sidebar-nav a[aria-current="page"]')?.textContent.trim()`);
        assert.ok(current?.startsWith(TITLES[home]), `expected ${TITLES[home]} to be the current page, got: ${current}`);
        assert.equal(await page.evaluate('location.hash'), `#/${home}`, 'a bare / lands on the default page');

        // Every page and back to Overview: the current item, the title and the focus follow, and
        // the inactive sections are hidden skeletons with no page content.
        for (const id of [...PAGES.filter((p) => p !== home), ...(home === 'overview' ? [] : [home]), 'overview']) {
            await page.gotoPage(id);
            await page.waitFor(
                `document.activeElement?.id === 'pageTitle' && !!document.activeElement.closest('[data-page-heading="${id}"]')`,
                { label: `focus on the ${id} heading` }
            );
            const state = await page.evaluate(`(function(){
                return {
                    current: document.querySelector('.sidebar-nav a[aria-current="page"]')?.getAttribute('href'),
                    hash: location.hash,
                    title: document.title,
                    others: [...document.querySelectorAll('section.page:not(#page-${id})')].map(function(s){
                        return s.hidden && !s.hasAttribute('data-ready') && !!s.querySelector(':scope > .page-skeleton')
                            && [...s.children].every(function(c){ return c.matches('.page-skeleton, p.visually-hidden'); });
                    }),
                };
            })()`);
            assert.equal(state.current, `#/${id}`, `the sidebar marks ${id}`);
            assert.equal(state.hash, `#/${id}`);
            assert.equal(state.title, `${TITLES[id]} · nfsen-ng`);
            assert.ok(state.others.every(Boolean), `every section but ${id} is a hidden skeleton, got ${JSON.stringify(state.others)}`);
        }

        // Theme (D8, 4.0.7): a choice for this browser wins, System follows the OS live, and "Use
        // instance default" drops the choice. The menu marks the current choice.
        await setOsTheme(page, 'light');
        const instance = await page.evaluate(`document.documentElement.dataset.themeDefault`);
        const instanceDark = (os) => (instance === 'dark' ? 'dark' : instance === 'light' ? 'light' : os);
        await page.chooseTheme('dark');
        await page.waitFor(`document.documentElement.dataset.theme === 'dark'`, { label: 'the dark theme' });
        assert.deepEqual(await theme(page), { theme: 'dark', stored: 'dark', pressed: ['dark'] });
        await page.chooseTheme('system');
        await page.waitFor(`document.documentElement.dataset.theme === 'light'`, { label: 'System to follow a light OS' });
        assert.deepEqual(await theme(page), { theme: 'light', stored: 'system', pressed: ['system'] });
        await setOsTheme(page, 'dark');
        await page.waitFor(`document.documentElement.dataset.theme === 'dark'`, { label: 'System to follow the OS live' });
        await page.chooseTheme('default');
        await page.waitFor(`localStorage.getItem('nfsen-theme') === null`, { label: 'the choice to be dropped' });
        assert.deepEqual(
            await theme(page),
            { theme: instanceDark('dark'), stored: null, pressed: ['default'] },
            `instance default ${instance}`
        );
        assert.match(
            await page.evaluate(`document.querySelector('#themeMenuList [data-theme-choice="default"]').textContent.trim()`),
            /^Use instance default \((System|Light|Dark)\)$/
        );
        await page.chooseTheme('light');
        await page.reload();
        await page.waitForBoot();
        assert.deepEqual(await theme(page), { theme: 'light', stored: 'light', pressed: ['light'] }, 'a choice survives a reload');
        await page.chooseTheme('default');
        await setOsTheme(page, 'light');

        // Keyboard only: Enter opens the theme menu, Tab reaches its items, Escape closes it and
        // gives the focus back to its toggle.
        await page.evaluate(`document.querySelector('#themeMenu .menu-toggle').focus()`);
        await press(page, 'Enter');
        await page.waitFor(`document.getElementById('themeMenuList').matches(':popover-open')`, { label: 'Enter to open the theme menu' });
        assert.equal(await page.evaluate(`document.querySelector('#themeMenu .menu-toggle').getAttribute('aria-expanded')`), 'true');
        await press(page, 'Tab');
        assert.equal(await page.evaluate(`document.activeElement?.dataset.themeChoice`), 'light', 'Tab moves into the menu');
        await press(page, 'Escape');
        await page.waitFor(`!document.getElementById('themeMenuList').matches(':popover-open')`, {
            label: 'Escape to close the theme menu',
        });
        assert.ok(
            await page.evaluate(`document.activeElement === document.querySelector('#themeMenu .menu-toggle')`),
            'the focus is back on the toggle'
        );

        // Overview's partial is inserted again on the way back, and must not reset the graph settings.
        await page.setSelectValue('#filterDisplaySelect', 'protocols');
        await page.waitFor(`document.getElementById('trafficGraph').dataset.chartConfig?.includes('"display":"protocols"')`, {
            label: 'the graph to show protocols',
        });
        await page.gotoPage('flows');
        await page.gotoPage('overview');
        await sleep(1000);
        assert.deepEqual(await page.signalValue('graph_protocols'), ['tcp', 'udp', 'icmp', 'other'], 'the protocols survive a page switch');
        await page.setSelectValue('#filterDisplaySelect', 'sources');

        // A sync rendered for a page the client has already left must not flip it back, and the
        // client asks for its own page again.
        await page.gotoPage('health');
        await page.evaluate(`(function(){
            window.__staleTitles = [];
            new MutationObserver(function(){
                if (document.body.dataset.serverPage === 'overview') window.__staleTitles.push(document.title);
            }).observe(document.body, { attributes: true, attributeFilter: ['data-server-page'] });
        })()`);
        assert.equal(await page.evaluate(STALE_SYNC), 200, 'refresh-graphs posted as Overview');
        await page.waitFor(`window.__staleTitles.length > 0`, { label: 'the stale Overview render to arrive' });
        await page.waitFor(
            `document.body.dataset.serverPage === 'health' && !!document.querySelector('#page-health[data-ready]') && !document.querySelector('#page-health > .page-skeleton')`,
            { label: 'Health to be rendered again' }
        );
        const stale = await page.evaluate(`({
            health: !document.getElementById('page-health').hidden,
            overview: document.getElementById('page-overview').hidden && !document.getElementById('page-overview').hasAttribute('data-ready'),
            graph: document.getElementById('trafficGraphSection').hidden,
            heading: !document.querySelector('[data-page-heading="health"]').hidden,
            current: document.querySelector('.sidebar-nav a[aria-current="page"]')?.getAttribute('href'),
            staleTitle: window.__staleTitles[0],
            title: document.title,
        })`);
        assert.deepEqual(stale, {
            health: true,
            overview: true,
            graph: true,
            heading: true,
            current: '#/health',
            staleTitle: 'Health · nfsen-ng',
            title: 'Health · nfsen-ng',
        });

        // A hash change undone within the same frame ends on the page the hash names.
        await page.evaluate(`(function(){
            location.hash = '#/flows';
            setTimeout(function(){ location.hash = '#/health'; }, 0);
        })()`);
        await sleep(1500);
        await page.waitForPage('health');
        assert.deepEqual(
            await page.evaluate(`({
                hash: location.hash,
                current: document.querySelector('.sidebar-nav a[aria-current="page"]')?.getAttribute('href'),
                flows: document.getElementById('page-flows').hidden,
                title: document.title,
            })`),
            { hash: '#/health', current: '#/health', flows: true, title: 'Health · nfsen-ng' },
            'a hash change and its undo end on the page the hash names'
        );
        assert.equal(await page.signalValue('page'), 'health');

        // Two pages that both hold a result: switching between them swaps each for a skeleton and
        // back, and the tables' shared ids move between them without a morph error.
        await page.gotoPage('flows');
        await page.setRangePreset('1y');
        await page.setSelectValue('#filterFlowsLimit select', 20);
        await page.runQuery('flows');
        await page.gotoPage('talkers');
        await page.runQuery('talkers');
        for (const id of ['flows', 'talkers', 'flows', 'talkers']) {
            await page.gotoPage(id);
            await sleep(500);
        }
        assert.equal(await page.evaluate(`document.querySelectorAll('html > div[hidden]').length`), 0, 'no morph left its pantry behind');
        assert.deepEqual(page.realErrors(), [], 'switching between two results raises no error');

        // The IP modal (D24) stays open across a sync, and stays closed once closed. A fresh result
        // over a year, since a dev server restart drops the tab's range back to the last 24 hours.
        await page.gotoPage('flows');
        await page.setRangePreset('1y');
        await page.runQuery('flows', { timeout: 30000 });
        await page.waitFor(`!!document.querySelector('#page-flows .ip-link')`, { timeout: 20000, label: 'an IP link in the Flows result' });
        await page.evaluate(`document.querySelector('#page-flows .ip-link').click()`);
        await page.waitFor(`document.getElementById('ip-modal-inner')?.open`, { timeout: 15000, label: 'the IP modal to open' });
        const modal = await page.evaluate(`({
            inRoot: !!document.querySelector('#modal-root > #ip-modal-inner'),
            modal: document.getElementById('ip-modal-inner').matches(':modal'),
            title: document.getElementById('ipModalLabel').textContent.trim(),
            hostname: !!document.querySelector('#ip-modal-inner dt'),
        })`);
        assert.ok(modal.inRoot && modal.modal && modal.hostname, `the modal is the tab's modal: ${JSON.stringify(modal)}`);
        assert.match(modal.title, /^IP info: /);
        assert.equal(await page.evaluate(syncAs('flows')), 'synced', 'a sync arrived while the modal was open');
        assert.equal(await page.evaluate(`document.getElementById('ip-modal-inner')?.open`), true, 'the modal survives the sync');
        await press(page, 'Escape');
        await page.waitFor(`!document.getElementById('ip-modal-inner').open`, { label: 'Escape to close the modal' });
        assert.equal(await page.evaluate(syncAs('flows')), 'synced');
        assert.equal(await page.evaluate(`document.getElementById('ip-modal-inner').open`), false, 'a closed modal stays closed');

        // A reload keeps the page.
        await page.gotoPage('flows');
        await page.reload();
        await page.waitForBoot();
        await page.waitForPage('flows');
        assert.equal(await page.evaluate('location.hash'), '#/flows', 'a reload keeps the page');
        assert.equal(await page.evaluate('document.title'), 'Flows · nfsen-ng');

        // An old bookmark: the persisted view of the tab layout opens its page once (D2).
        await page.evaluate(`localStorage.setItem('nfsen-persist:_currentView', JSON.stringify('statistics'))`);
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.waitForPage('talkers');
        assert.equal(await page.evaluate('location.hash'), '#/talkers', 'a persisted statistics view opens Top Talkers');
        assert.equal(await page.evaluate(`localStorage.getItem('nfsen-persist:_currentView')`), null, 'the old key is gone');

        // Forced colors (2.2): the current page, the pressed theme and the status glyphs stay visible.
        await page.withForcedColors(async () => {
            await sleep(300);
            await page.screenshot('/tmp/nfsen-smoke-forced-expanded.png');
        });

        // Collapse, reload, still collapsed, and collapsed from the first paint on (the body has
        // the state before Datastar boots); the Alerts count and the Health glyph keep their text.
        await page.evaluate(`document.querySelector('.sidebar-toggle').click()`);
        await page.waitFor(`document.body.dataset.sidebar === 'collapsed'`, { label: 'sidebar to collapse' });
        await page.send('Page.addScriptToEvaluateOnNewDocument', {
            source: `new MutationObserver(function(records, observer){
                if (!document.querySelector('aside.sidebar')) return;
                window.__sidebarAtParse = document.body.getAttribute('data-sidebar');
                observer.disconnect();
            }).observe(document, { childList: true, subtree: true });`,
        });
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.waitFor(`document.body.dataset.sidebar === 'collapsed'`, { label: 'collapsed sidebar after reload' });
        assert.equal(await page.evaluate('window.__sidebarAtParse'), 'collapsed', 'the sidebar is collapsed before Datastar boots');
        const collapsed = await page.evaluate(`({
            width: Math.round(document.querySelector('.sidebar').getBoundingClientRect().width),
            names: [...document.querySelectorAll('.sidebar-nav .nav-item')].every((a) => a.title && a.querySelector('.nav-label').textContent.trim()),
            badge: [...document.querySelectorAll('.sidebar .nav-badge')].map((b) => b.firstChild.textContent.trim()),
        })`);
        assert.ok(collapsed.width <= 60, `the collapsed sidebar is icons only (${collapsed.width} px)`);
        assert.ok(collapsed.names, 'every collapsed item keeps a name and a title');
        assert.ok(
            collapsed.badge.every((n) => /^\d+$/.test(n)),
            'the Alerts count stays a number'
        );
        await page.withForcedColors(async () => {
            await sleep(300);
            await page.screenshot('/tmp/nfsen-smoke-forced-collapsed.png');
        });
        await page.evaluate(`document.querySelector('.sidebar-toggle').click()`);
        await page.waitFor(`document.body.dataset.sidebar === 'expanded'`, { label: 'sidebar to expand again' });

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during smoke test, got:\n${errors.join('\n')}`);
    });

    // A phone: the tab bar and its More menu reach every page, including the switches between
    // a page with the traffic graph and one without, with no console errors.
    await withPage(
        async (page) => {
            await page.navigate(BASE + '/');
            await page.waitForBoot();
            assert.ok(await page.isMobile(), 'the phone layout is active');
            for (const id of ['flows', 'alerts', 'health', 'talkers', 'settings', 'overview', 'conversations']) {
                await page.gotoPage(id);
                await sleep(800);
                assert.equal(await page.evaluate('location.hash'), `#/${id}`);
            }
            await page.withForcedColors(async () => {
                await sleep(300);
                await page.screenshot('/tmp/nfsen-smoke-forced-phone.png');
            });
            const errors = page.realErrors();
            assert.deepEqual(errors, [], `expected no console errors on a phone, got:\n${errors.join('\n')}`);
        },
        { width: 390, height: 844, mobile: true }
    );
}

if (import.meta.url === `file://${process.argv[1]}`) {
    smokeTest()
        .then(() => console.log('smoke: PASS'))
        .catch((e) => {
            console.error('smoke: FAIL\n', e);
            process.exit(1);
        });
}
