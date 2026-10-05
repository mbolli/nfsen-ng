// Hash routing (1.1, 5.5): the default view, old bookmarks once, reload and history keep the
// page, and a sidebar click sets the title at once and focuses the heading once the page is in.
import assert from 'node:assert/strict';
import { withPage, BASE } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The page on screen, as the router, the sidebar and the signal see it. */
async function shown(page) {
    return page.evaluate(`({
        hash: location.hash,
        title: document.title,
        current: document.querySelector('.sidebar-nav a[aria-current="page"]')?.getAttribute('href') ?? null,
        visible: [...document.querySelectorAll('section.page')].filter((s) => !s.hidden).map((s) => s.dataset.page),
    })`);
}

async function expectPage(page, id, title, message) {
    await page.waitForPage(id);
    await page.waitFor(`location.hash === '#/${id}' && document.title === ${JSON.stringify(`${title} · nfsen-ng`)}`, {
        label: `${message}: hash and title of ${id}`,
    });
    const state = await shown(page);
    assert.deepEqual(
        state,
        { hash: `#/${id}`, title: `${title} · nfsen-ng`, current: `#/${id}`, visible: [id] },
        `${message}: the page on screen is ${id}`
    );
    assert.equal(await page.signalValue('page'), id, `${message}: the page signal follows`);
}

/** A full load of `url`, also when only its fragment differs from the current one. */
async function load(page, url) {
    await page.navigate('about:blank');
    await page.navigate(url);
    await page.waitForBoot();
}

/**
 * The signal keys of every datastar-patch-signals event in the first 2.5 s of a fresh tab's SSE
 * stream (the first sync).
 */
async function firstSyncSignals() {
    const res = await fetch(BASE + '/');
    const html = await res.text();
    const cookie = res.headers
        .getSetCookie()
        .map((c) => c.split(';')[0])
        .join('; ');
    const ctx = html.match(/via_ctx":"([^"]+)"/)?.[1];
    assert.ok(ctx && /\bpage(?:____[0-9a-z]+)+/.test(html), 'the page carries its context and page signal ids');

    const abort = new AbortController();
    const stop = setTimeout(() => abort.abort(), 2500);
    const sse = await fetch(`${BASE}/_sse?datastar=${encodeURIComponent(JSON.stringify({ via_ctx: ctx }))}`, {
        headers: { cookie, accept: 'text/event-stream', 'accept-encoding': 'identity' },
        signal: abort.signal,
    });
    assert.equal(sse.status, 200, 'the SSE stream opens');
    const decoder = new TextDecoder();
    let text = '';
    try {
        for await (const chunk of sse.body) text += decoder.decode(chunk, { stream: true });
    } catch (e) {
        if (e.name !== 'AbortError') throw e;
    } finally {
        clearTimeout(stop);
    }

    const keys = text
        .split('\n\n')
        .filter((event) => event.startsWith('event: datastar-patch-signals'))
        .flatMap((event) => {
            const json = event
                .split('\n')
                .filter((line) => line.startsWith('data: signals '))
                .map((line) => line.slice('data: signals '.length))
                .join('');
            return Object.keys(JSON.parse(json));
        });
    return { keys, html };
}

/** The wire id of signal `name` among `keys` (php-via adds one or more `____` suffixes), or undefined. */
const idOf = (keys, name) => keys.find((k) => k.startsWith(`${name}____`));

/** php-via's seed: the signal values the first sync sends, in a <meta> ahead of the SSE bootstrap. */
function headSeed(html) {
    const head = html.slice(0, html.indexOf('</head>'));
    const attr = head.match(/<meta data-signals__ifmissing="([^"]*)">/)?.[1];
    assert.ok(attr, 'the head carries a signal seed');
    const json = attr
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
    return { seed: JSON.parse(json), head };
}

export default async function routerTest() {
    // Appendix A: the first sync pushes the new signals but not page, which the client seeds
    // itself (1.2); an echo would send a tab back to the server's page.
    const { keys, html } = await firstSyncSignals();
    assert.ok(!!idOf(keys, 'query_running'), `the first sync pushes the shell signals: ${keys.slice(0, 5).join(', ')}`);
    assert.ok(!idOf(keys, 'page'), 'the first sync does not contain page');

    // The same values are in the page itself, ahead of the layout's one via_ctx seed and SSE bootstrap.
    const { seed, head } = headSeed(html);
    assert.equal(seed[idOf(Object.keys(seed), 'query_running')], false, 'the seed has the shell signals');
    assert.equal(typeof seed[idOf(Object.keys(seed), 'nfcapdTz')], 'string', 'the seed has the timezones');
    assert.ok(!idOf(Object.keys(seed), 'page'), 'the seed leaves page to the client');
    assert.equal(head.match(/via_ctx/g)?.length, 1, "one via_ctx seed, the layout's");
    assert.ok(head.search(/<meta data-signals__ifmissing=/) < head.indexOf("_sse'"), 'the seed comes before the SSE bootstrap');

    // Seeded, a tab works before its stream connects: here it never does.
    await withPage(async (page) => {
        await page.send('Network.enable');
        await page.send('Network.setBlockedURLs', { urls: ['*/_sse*'] });
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        const offline = await page.evaluate(`(async function(){
            var root = (await import('datastar')).root;
            var get = function(n){ return root[Object.keys(root).find(function(k){ return k.startsWith(n + '____'); })]; };
            // Overview has no Run button while its precomputed lists answer; the drawer's is always there.
            var runs = Array.from(document.querySelectorAll('button[data-run], #drawerApplyRun'));
            return {
                running: get('query_running'),
                live: get('range_live'),
                range: document.querySelector('#rangeMenu .menu-toggle span').textContent.trim(),
                runnable: runs.length > 0 && runs.every(function(b){ return !b.disabled; }),
            };
        })()`);
        assert.equal(offline.running, false, 'query_running is seeded');
        assert.equal(offline.live, true, 'range_live is seeded');
        assert.doesNotMatch(offline.range, /undefined|NaN|Invalid/, `the range label is computed from the seed: "${offline.range}"`);
        assert.ok(offline.runnable, 'every Run button is enabled without a sync');
        assert.deepEqual(page.realErrors(), [], 'no console error without the SSE stream');
    });

    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.evaluate(`localStorage.clear()`);

        // A bare / opens the default view, which the brand link names as home.
        await load(page, BASE + '/');
        const home = await page.evaluate(`document.querySelector('.sidebar-brand').getAttribute('href')`);
        assert.match(home, /^#\/[a-z]+$/, 'the brand links to the default page');
        const homeId = home.slice(2);
        const homeTitle = await page.evaluate(`document.querySelector('[data-page-heading="${homeId}"] h1').textContent.trim()`);
        await expectPage(page, homeId, homeTitle, 'bare /');

        // Setting the hash switches the page; a legacy id is rewritten to its page (D2).
        await page.evaluate(`location.hash = '#/alerts'`);
        await expectPage(page, 'alerts', 'Alerts', 'hash #/alerts');
        await page.evaluate(`location.hash = '#/statistics'`);
        await expectPage(page, 'talkers', 'Top Talkers', 'legacy hash #/statistics');

        // A sidebar click: the title changes before the page has arrived, and the focus moves to
        // the page heading once #page-flows[data-ready] exists, not before.
        await page.evaluate(`(function(){
            window.__route = { titleAtReady: null, focusBeforeReady: false };
            new MutationObserver(function(){
                var ready = !!document.querySelector('#page-flows[data-ready]');
                if (ready && window.__route.titleAtReady === null) window.__route.titleAtReady = document.title;
                if (!ready && document.activeElement?.closest?.('[data-page-heading="flows"]')) window.__route.focusBeforeReady = true;
            }).observe(document.body, { subtree: true, attributes: true, childList: true });
            document.querySelector('.sidebar-nav a[href="#/flows"]').click();
        })()`);
        await page.waitFor(`!!document.querySelector('#page-flows[data-ready]')`, { label: 'Flows to arrive' });
        await page.waitFor(`document.activeElement?.id === 'pageTitle'`, { label: 'focus on the page heading' });
        const route = await page.evaluate(`({
            titleAtReady: window.__route.titleAtReady,
            focusBeforeReady: window.__route.focusBeforeReady,
            heading: document.activeElement.closest('[data-page-heading]')?.dataset.pageHeading,
            text: document.activeElement.textContent.trim(),
        })`);
        assert.equal(route.titleAtReady, 'Flows · nfsen-ng', 'the title changes before the page content arrives');
        assert.equal(route.focusBeforeReady, false, 'the heading is not focused before the page has arrived');
        assert.deepEqual([route.heading, route.text], ['flows', 'Flows'], 'the Flows heading has the focus');
        await expectPage(page, 'flows', 'Flows', 'sidebar click');

        // The browser history walks the pages back and forward.
        await page.evaluate('history.back()');
        await expectPage(page, 'talkers', 'Top Talkers', 'first back');
        await page.evaluate('history.back()');
        await expectPage(page, 'alerts', 'Alerts', 'second back');
        await page.evaluate('history.forward()');
        await expectPage(page, 'talkers', 'Top Talkers', 'forward');

        // A reload keeps the page, although the server renders the default one for a bare GET.
        await page.reload();
        await page.waitForBoot();
        await expectPage(page, 'talkers', 'Top Talkers', 'reload');
        // The initial load sets the title but leaves the focus alone.
        assert.notEqual(await page.evaluate('document.activeElement?.id'), 'pageTitle', 'a reload does not move the focus');

        // Old bookmarks: the view persisted by the tab layout opens its page once, and both keys go.
        const legacy = [
            { view: 'statistics', section: null, id: 'talkers', title: 'Top Talkers' },
            { view: 'settings', section: 'import', id: 'health', title: 'Health' },
            { view: 'settings', section: 'alerts', id: 'alerts', title: 'Alerts' },
            { view: 'sankey', section: null, id: 'conversations', title: 'Conversations' },
        ];
        for (const { view, section, id, title } of legacy) {
            await page.evaluate(`(function(){
                localStorage.setItem('nfsen-persist:_currentView', ${JSON.stringify(JSON.stringify(view))});
                ${section ? `localStorage.setItem('nfsen-persist:_settingsSection', ${JSON.stringify(JSON.stringify(section))});` : ''}
            })()`);
            await load(page, BASE + '/');
            await expectPage(page, id, title, `persisted view ${view}${section ? `/${section}` : ''}`);
            assert.deepEqual(
                await page.evaluate(
                    `[localStorage.getItem('nfsen-persist:_currentView'), localStorage.getItem('nfsen-persist:_settingsSection')]`
                ),
                [null, null],
                'the old keys are gone'
            );
        }

        // A hash in the URL wins over a persisted view, which is still removed.
        await page.evaluate(`localStorage.setItem('nfsen-persist:_currentView', '"graphs"')`);
        await load(page, BASE + '/#/health');
        await expectPage(page, 'health', 'Health', 'a URL with a hash');
        assert.equal(await page.evaluate(`localStorage.getItem('nfsen-persist:_currentView')`), null);

        // A hash that names no page falls back to the page the server rendered.
        await load(page, BASE + '/#/nowhere');
        await expectPage(page, homeId, homeTitle, 'an unknown hash');

        await sleep(300);
        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors during the router test, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    routerTest()
        .then(() => console.log('router: PASS'))
        .catch((e) => {
            console.error('router: FAIL\n', e);
            process.exit(1);
        });
}
