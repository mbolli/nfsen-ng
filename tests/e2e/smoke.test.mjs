// Smoke test: the app boots, every page is reachable from the sidebar and from the phone tab
// bar with no console errors, and the collapsed sidebar survives a reload. The cheapest,
// highest-signal E2E check -- if this fails, nothing else in the suite is worth running.
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

        assert.equal(await page.evaluate('document.title'), 'Overview · nfsen-ng');
        const current = await page.evaluate(`document.querySelector('.sidebar-nav a[aria-current="page"]')?.textContent.trim()`);
        assert.match(current, /Overview/, `expected Overview to be the current page, got: ${current}`);
        assert.equal(await page.evaluate('location.hash'), '#/overview', 'a bare / lands on the default page');

        // Every page and back to Overview: the current item, the title and the focus follow, and
        // the inactive sections are hidden skeletons with no page content.
        for (const id of [...PAGES.slice(1), 'overview']) {
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

        // Collapse, reload, still collapsed; then restore.
        await page.evaluate(`document.querySelector('.sidebar-toggle').click()`);
        await page.waitFor(`document.body.dataset.sidebar === 'collapsed'`, { label: 'sidebar to collapse' });
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await page.waitFor(`document.body.dataset.sidebar === 'collapsed'`, { label: 'collapsed sidebar after reload' });
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
