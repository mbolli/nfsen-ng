// Smoke test: every page from the sidebar and the tab bar without console errors, status in
// words, the theme popover (D8), the IP modal across a sync, the collapsed sidebar across a reload.
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

const SHIFT = 8;
const KEYS = {
    Escape: { code: 'Escape', keyCode: 27 },
    Enter: { code: 'Enter', keyCode: 13, text: '\r' },
    ' ': { code: 'Space', keyCode: 32, text: ' ' },
    Tab: { code: 'Tab', keyCode: 9 },
    ArrowDown: { code: 'ArrowDown', keyCode: 40 },
    ArrowUp: { code: 'ArrowUp', keyCode: 38 },
    Home: { code: 'Home', keyCode: 36 },
    End: { code: 'End', keyCode: 35 },
};

async function press(page, key, modifiers = 0) {
    const { code, keyCode, text } = KEYS[key];
    const base = { key, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode, modifiers };
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(text ? { text } : {}) });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

/** A mouse press and release at the middle of the first element matching `selector`. */
async function pressOn(page, selector) {
    const at = await page.evaluate(`(function(){
        var r = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();
        return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
    })()`);
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, ...at, button: 'left', clickCount: 1 });
    }
}

const THEME = `document.getElementById('themeMenu')`;
const THEME_TRIGGER = `document.querySelector('#themeMenu .menu-toggle')`;
/** Once the sidebar and #page-content are parsed: the theme, whether sb-popover is defined, the theme
    list's display; and a mark on #page-content that a sync morph would remove. */
const AT_PARSE = `new MutationObserver(function(records, observer){
    var list = document.getElementById('themeMenuList');
    var content = document.getElementById('page-content');
    if (!list || !content) return;
    observer.disconnect();
    window.__e2eAtParse = {
        theme: document.documentElement.dataset.theme,
        defined: !!customElements.get('sb-popover'),
        list: getComputedStyle(list).display,
    };
    content.setAttribute('data-e2e-unsynced', '');
}).observe(document, { childList: true, subtree: true });`;

/** Whether the theme popover is open, and the focus: its trigger, a choice, or another element's tag. */
const THEME_FOCUS = `({
    open: ${THEME}.open,
    focus: document.activeElement === ${THEME_TRIGGER} ? 'trigger' : document.activeElement?.dataset.themeChoice ?? document.activeElement?.tagName.toLowerCase(),
})`;

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

        // The theme popover by keyboard (POPOVER-SPEC 4.2): Enter, Space and ArrowUp open it, the
        // arrows, Home and End wrap, Escape closes it onto its trigger.
        assert.deepEqual(
            await page.evaluate(`[
                ${THEME}.shadowRoot.querySelector('slot[name="trigger"]').assignedElements().length,
                ${THEME_TRIGGER}.getAttribute('aria-haspopup'),
                ${THEME_TRIGGER}.getAttribute('aria-expanded'),
                ${THEME_TRIGGER}.hasAttribute('aria-controls'),
            ]`),
            [1, 'dialog', 'false', false],
            'the theme menu has its own trigger, which sb-popover marks as opening a dialog'
        );
        await page.evaluate(`${THEME_TRIGGER}.focus()`);
        for (const key of ['Enter', ' ']) {
            await press(page, key);
            await page.waitFor(`${THEME}.open === true && document.activeElement?.dataset.themeChoice === 'light'`, {
                label: `${KEYS[key].code} to open the theme menu on Light`,
            });
            assert.equal(await page.evaluate(`${THEME_TRIGGER}.getAttribute('aria-expanded')`), 'true');
            await press(page, 'Escape');
            await page.waitFor(`${THEME}.open === false && document.activeElement === ${THEME_TRIGGER}`, {
                label: `Escape after ${KEYS[key].code} to close the theme menu onto its trigger`,
            });
        }
        await press(page, 'ArrowUp');
        await page.waitFor(`${THEME}.open === true && document.activeElement?.dataset.themeChoice === 'default'`, {
            label: 'ArrowUp to open the theme menu on its last choice',
        });
        for (const [key, to] of [
            ['ArrowDown', 'light'],
            ['ArrowDown', 'dark'],
            ['End', 'default'],
            ['ArrowUp', 'system'],
            ['Home', 'light'],
            ['ArrowUp', 'default'],
        ]) {
            await press(page, key);
            assert.deepEqual(await page.evaluate(THEME_FOCUS), { open: true, focus: to }, `${KEYS[key].code} moves to ${to}`);
        }

        // A sync morph keeps it open with the focus on the same choice (POPOVER-SPEC 6, item 1).
        await press(page, 'ArrowUp');
        await page.syncNow('overview');
        assert.deepEqual(await page.evaluate(THEME_FOCUS), { open: true, focus: 'system' }, 'a sync keeps the theme menu open and the focus');
        assert.equal(await page.evaluate(`${THEME_TRIGGER}.getAttribute('aria-expanded')`), 'true', 'the sync keeps aria-expanded');

        // Enter on a choice applies it, closes the menu onto its trigger, and the trigger names it.
        await press(page, 'ArrowUp');
        await press(page, 'Enter');
        await page.waitFor(`document.documentElement.dataset.theme === 'dark'`, { label: 'Enter on Dark' });
        assert.deepEqual(await page.evaluate(THEME_FOCUS), { open: false, focus: 'trigger' }, 'choosing closed the menu onto its trigger');
        assert.deepEqual(await theme(page), { theme: 'dark', stored: 'dark', pressed: ['dark'] });
        assert.equal(await page.signalValue('_themeChoice'), 'dark', '_themeChoice follows the choice');
        assert.equal(
            await page.evaluate(`document.querySelector('#themeMenu .nav-label').textContent.replace(/\\s+/g, ' ').trim()`),
            'Theme: Dark'
        );

        // Tab past the last choice closes it, and the focus goes on to the sidebar toggle.
        await press(page, 'ArrowUp');
        await page.waitFor(`${THEME}.open === true && document.activeElement?.dataset.themeChoice === 'default'`, {
            label: 'ArrowUp to open the theme menu again',
        });
        await press(page, 'Tab');
        await page.waitFor(`${THEME}.open === false`, { label: 'Tab out of the theme menu to close it' });
        assert.ok(await page.evaluate(`document.activeElement?.matches('.sidebar-toggle')`), 'Tab left the menu for the sidebar toggle');

        // A press outside closes it, and the focus stays where the press put it.
        await page.evaluate(`${THEME_TRIGGER}.click()`);
        await page.waitFor(`${THEME}.open === true`, { label: 'a click to open the theme menu' });
        await pressOn(page, '[data-page-heading="overview"] h1');
        await page.waitFor(`${THEME}.open === false`, { label: 'a press outside to close the theme menu' });
        assert.equal(await page.evaluate(`document.activeElement?.id`), 'pageTitle', 'the focus is on the pressed heading');
        await page.chooseTheme('default');
        await page.waitFor(`localStorage.getItem('nfsen-theme') === null`, { label: 'the choice to be dropped again' });
        assert.equal(await page.evaluate(`${THEME}.open`), false, 'choosing through chooseTheme closed the menu');

        // Overview's partial is inserted again on the way back, and must not reset the graph settings.
        await page.setSelectValue('#filterDisplaySelect', 'protocols');
        await page.waitFor(`document.getElementById('trafficGraph').dataset.chartConfig?.includes('"display":"protocols"')`, {
            label: 'the graph to show protocols',
        });
        await page.gotoPage('flows');
        await page.gotoPage('overview');
        await sleep(1000);
        assert.equal(await page.signalValue('graph_display'), 'protocols', 'the display survives a page switch');
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

        // A toast shown while the modal is open lands in the modal's own stack, where it is not
        // inert: a real click on its close button dismisses it.
        await page.evaluate(`window.showMessage('error', 'Shown over the modal', false)`);
        await page.waitFor(`!!document.querySelector('#ip-modal-inner > .toast-stack nfsen-toast .notice')`, {
            label: 'the toast in the modal',
        });
        assert.deepEqual(
            await page.evaluate(`(function(){
                var t = document.querySelector('#ip-modal-inner > .toast-stack nfsen-toast');
                return { rocket: t.rocketInstanceId !== undefined, name: t.querySelector('button[data-variant=close]').getAttribute('aria-label') };
            })()`),
            { rocket: true, name: 'Dismiss notification' },
            'the toast in the modal is a Rocket host with a named close button'
        );
        const close = await page.evaluate(`(function(){
            var b = document.querySelector('#ip-modal-inner > .toast-stack nfsen-toast button[data-variant=close]').getBoundingClientRect();
            var x = b.x + b.width / 2, y = b.y + b.height / 2;
            return { x: x, y: y, hit: document.elementFromPoint(x, y)?.matches('button[data-variant=close]') ?? false };
        })()`);
        assert.ok(close.hit, 'the toast close button is on top and hit-testable');
        assert.equal(await page.evaluate(syncAs('flows')), 'synced', 'a sync arrived while the toast was shown');
        assert.equal(
            await page.evaluate(`document.querySelectorAll('#ip-modal-inner nfsen-toast').length`),
            1,
            'the toast survives the sync'
        );
        for (const type of ['mousePressed', 'mouseReleased']) {
            await page.send('Input.dispatchMouseEvent', { type, x: close.x, y: close.y, button: 'left', clickCount: 1 });
        }
        await page.waitFor(`!document.querySelector('#ip-modal-inner nfsen-toast')`, { label: 'the toast to be dismissed by a click' });
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

        // Forced colors (2.2): the current page, the pressed theme and the status glyphs stay visible,
        // and the open theme menu its panel edge and focus ring (POPOVER-SPEC 6, item 10).
        await page.withForcedColors(async () => {
            await sleep(300);
            await page.screenshot('/tmp/nfsen-smoke-forced-expanded.png');
            await page.evaluate(`${THEME_TRIGGER}.focus()`);
            await press(page, 'ArrowUp');
            await press(page, 'ArrowDown');
            await page.waitFor(`${THEME}.open === true && document.activeElement?.dataset.themeChoice === 'light'`, {
                label: 'the theme menu open on Light in forced colors',
            });
            await sleep(300);
            const forced = await page.evaluate(`(function(){
                var panel = getComputedStyle(${THEME}.shadowRoot.querySelector('.panel'));
                var ring = getComputedStyle(document.activeElement);
                var pressed = document.querySelector('#themeMenuList [aria-pressed="true"]');
                var mark = getComputedStyle(pressed, '::before');
                return {
                    edge: panel.outlineStyle + ' ' + panel.outlineWidth,
                    ring: [ring.outlineStyle, parseFloat(ring.outlineWidth)],
                    pressed: pressed.dataset.themeChoice,
                    mark: { content: mark.content, mask: mark.maskImage !== 'none', adjust: mark.forcedColorAdjust, drawn: parseFloat(mark.width) > 0 },
                };
            })()`);
            assert.equal(forced.edge, 'solid 1px', `the theme panel keeps its edge in forced colors: ${forced.edge}`);
            assert.ok(forced.ring[0] !== 'none' && forced.ring[1] >= 2, `the focused choice shows a ring: ${forced.ring}`);
            assert.deepEqual(
                [forced.pressed, forced.mark],
                ['default', { content: '""', mask: true, adjust: 'none', drawn: true }],
                'the pressed choice keeps its check mark in forced colors'
            );
            await page.screenshot('/tmp/nfsen-smoke-forced-theme.png');
            await press(page, 'Escape');
            await page.waitFor(`${THEME}.open === false && document.activeElement === ${THEME_TRIGGER}`, {
                label: 'Escape to close the theme menu in forced colors',
            });
        });

        // Keyboard (V-A11Y): Tab from the theme menu reaches the sidebar toggle, Shift+Tab walks
        // back up the navigation to Alerts, and Enter works on both.
        await page.evaluate(`document.querySelector('#themeMenu .menu-toggle').focus()`);
        await press(page, 'Tab');
        assert.ok(
            await page.evaluate(`document.activeElement?.matches('.sidebar-toggle')`),
            'Tab from the theme menu reaches the sidebar toggle'
        );
        await press(page, 'Enter');
        await page.waitFor(`document.body.dataset.sidebar === 'collapsed'`, { label: 'Enter on the toggle to collapse the sidebar' });
        await press(page, 'Enter');
        await page.waitFor(`document.body.dataset.sidebar === 'expanded'`, { label: 'Enter again to expand it' });
        await press(page, 'Tab', SHIFT);
        assert.ok(await page.evaluate(`document.activeElement?.matches('#themeMenu .menu-toggle')`), 'Shift+Tab returns to the theme menu');
        const links = await page.evaluate(`[...document.querySelectorAll('.sidebar-nav a')].map((a) => a.getAttribute('href'))`);
        for (const href of links.slice(links.indexOf('#/alerts')).reverse()) {
            await press(page, 'Tab', SHIFT);
            assert.equal(await page.evaluate(`document.activeElement?.getAttribute('href')`), href, `Shift+Tab reaches ${href}`);
        }
        await press(page, 'Enter');
        await page.waitForPage('alerts');

        // A toast: literal text, its own alert role, and it stays while it holds the focus.
        await page.evaluate(`window.showMessage('error', 'x <b>y</b>', true)`);
        await page.waitFor(`!!document.querySelector('#alerts-toast-container nfsen-toast .notice')`, { label: 'the toast' });
        const toast = await page.evaluate(`(function(){
            var t = document.querySelector('#alerts-toast-container nfsen-toast');
            var n = t.querySelector('.notice');
            return {
                rocket: t.rocketInstanceId !== undefined,
                role: n.getAttribute('role'),
                markup: !!n.querySelector('b'),
                live: document.getElementById('alerts-toast-container').getAttribute('aria-live'),
                name: n.querySelector('button[data-variant=close]').getAttribute('aria-label'),
            };
        })()`);
        assert.deepEqual(
            toast,
            { rocket: true, role: 'alert', markup: false, live: null, name: 'Dismiss notification' },
            'an error toast is a Rocket host, an alert of plain text, with a named close button'
        );
        await page.waitFor(`document.querySelector('#alerts-toast-container .toast-message')?.textContent === 'x <b>y</b>'`, {
            label: 'the literal toast text',
        });
        // The stack follows the footer in the document, so Tab from its last link reaches the toast.
        await page.evaluate(`[...document.querySelectorAll('.status-footer a')].at(-1).focus()`);
        await press(page, 'Tab');
        assert.ok(
            await page.evaluate(`document.activeElement?.matches('#alerts-toast-container nfsen-toast button[data-variant=close]')`),
            'Tab from the footer reaches the toast close button'
        );
        await sleep(6000);
        assert.equal(
            await page.evaluate(`!!document.querySelector('#alerts-toast-container nfsen-toast')`),
            true,
            'focus holds the toast past its 5 s'
        );
        await press(page, 'Enter');
        await page.waitFor(`!document.querySelector('#alerts-toast-container nfsen-toast')`, {
            label: 'Enter on its close button to dismiss it',
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

    // Before any sync, with the stream blocked (POPOVER-SPEC 6, items 4, 9 and 12): the stored theme is
    // set and the list hidden at parse, and the popover still applies a choice.
    await withPage(async (page) => {
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        await setOsTheme(page, 'light');
        await page.evaluate(`localStorage.setItem('nfsen-theme', 'dark')`);
        await page.send('Page.addScriptToEvaluateOnNewDocument', { source: AT_PARSE });
        await page.send('Network.enable');
        await page.send('Network.setBlockedURLs', { urls: ['*/_sse*'] });
        await page.reload();
        await page.waitForBoot();
        await page.waitFor(`${THEME_TRIGGER}?.getAttribute('aria-haspopup') === 'dialog'`, { label: 'sb-popover to render the theme menu' });
        assert.deepEqual(
            await page.evaluate('window.__e2eAtParse'),
            { theme: 'dark', defined: false, list: 'none' },
            'the stored theme is set and the theme list hidden when the sidebar is parsed'
        );
        await page.evaluate(`${THEME_TRIGGER}.click()`);
        await page.waitFor(`${THEME}.open === true && document.activeElement?.dataset.themeChoice === 'light'`, {
            label: 'the theme menu to open before any sync',
        });
        await page.evaluate(`document.querySelector('#themeMenuList [data-theme-choice="system"]').click()`);
        await page.waitFor(`document.documentElement.dataset.theme === 'light'`, { label: 'System to apply before any sync' });
        assert.deepEqual(
            await page.evaluate(`({
                state: ${THEME_FOCUS},
                pressed: [...document.querySelectorAll('#themeMenuList [aria-pressed="true"]')].map((b) => b.dataset.themeChoice),
                label: document.querySelector('#themeMenu .nav-label').textContent.replace(/\\s+/g, ' ').trim(),
                unsynced: document.getElementById('page-content').hasAttribute('data-e2e-unsynced'),
            })`),
            { state: { open: false, focus: 'trigger' }, pressed: ['system'], label: 'Theme: System (light)', unsynced: true },
            'the theme popover works before any sync'
        );

        const boxes = `(function(){
            var box = function(el){ var r = el.getBoundingClientRect(); return [r.x, r.y, r.width, r.height].map(Math.round).join(' '); };
            return { trigger: box(${THEME_TRIGGER}), panel: box(${THEME}.shadowRoot.querySelector('.panel')) };
        })()`;
        const measure = async () => {
            await page.evaluate(`${THEME}.show()`);
            await page.waitFor(`${THEME}.open === true`, { label: 'the theme menu to open' });
            await sleep(200);
            const at = await page.evaluate(boxes);
            await page.evaluate(`${THEME}.hide()`);
            return at;
        };
        const density = await page.evaluate(`document.documentElement.getAttribute('data-density')`);
        await page.evaluate(`document.documentElement.removeAttribute('data-density')`);
        const comfortable = await measure();
        await page.evaluate(`document.documentElement.setAttribute('data-density', 'compact')`);
        assert.deepEqual(await measure(), comfortable, 'compact tables change nothing about the theme popover');
        await page.evaluate(`(function(d){ d === null ? document.documentElement.removeAttribute('data-density') : document.documentElement.setAttribute('data-density', d); })(${JSON.stringify(density)})`);
        await page.evaluate(`localStorage.removeItem('nfsen-theme')`);
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
