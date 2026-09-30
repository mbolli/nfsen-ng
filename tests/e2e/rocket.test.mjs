// Rocket (ROCKET-SPEC 8.3): one engine, K1 and K2 on every Rocket host, toasts in all four stacks, identity, names, copying,
// what removed hosts leave behind. Only the alert Test dialog step writes (a Test event); E2E_SKIP_MUTATING=1 skips it.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const SKIP_MUTATING = ['1', 'true', 'yes'].includes(String(process.env.E2E_SKIP_MUTATING ?? '').toLowerCase());
const PAGES = ['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health', 'settings'];
// Datastar 1.0.4's attribute plugins (library/src/plugins/attributes) and nfsen-ng's persist.
const PLUGINS = [
    'attr',
    'bind',
    'class',
    'computed',
    'effect',
    'indicator',
    'init',
    'json-signals',
    'on',
    'on-intersect',
    'on-interval',
    'on-signal-patch',
    'ref',
    'show',
    'signals',
    'style',
    'text',
    'persist',
];
const ENGINE = /\/js\/datastar(-rocket)?\.js(\?|$)/;
// Markup, a Rocket signal and a Rocket action: all of it must stay text.
const TRICKY = `<b>bold</b> $$count @post('/nope') \${1}`;
const HEAP_TOASTS = 100;
const HEAP_RUNS = 5;
const HEAP_BOUND = 8;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function press(page, key) {
    const codes = { Escape: 27, Enter: 13 };
    const base = { key, code: key, windowsVirtualKeyCode: codes[key], nativeVirtualKeyCode: codes[key] };
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(key === 'Enter' ? { text: '\r' } : {}) });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
}

/** A real press and release on the element: over plain HTTP only user activation lets execCommand copy. */
async function clickAt(page, expr) {
    const { x, y } = await page.evaluate(`(function(){
        var el = ${expr};
        el.scrollIntoView({ block: 'center' });
        var r = el.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    })()`);
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
}

/**
 * Resolves with the text of the next copy, null after 5 s. A secure context (localhost) copies through
 * navigator.clipboard.writeText, which fires no copy event; plain HTTP through the textarea's execCommand.
 */
const nextCopy = `new Promise(function(resolve){
    var clip = window.isSecureContext ? navigator.clipboard : null;
    var timer = setTimeout(function(){ done(null); }, 5000);
    function onCopy(e){
        var t = e.target;
        done(t instanceof HTMLTextAreaElement ? t.value.slice(t.selectionStart, t.selectionEnd) : String(document.getSelection()));
    }
    function done(text){
        clearTimeout(timer);
        document.removeEventListener('copy', onCopy, true);
        if (clip) delete clip.writeText;
        resolve(text);
    }
    if (clip) {
        var write = clip.writeText;
        clip.writeText = function(text){
            return write.call(clip, text).then(function(){ done(String(text)); });
        };
    }
    document.addEventListener('copy', onCopy, true);
})`;

/**
 * Every element, shadow trees included; the Rocket hosts among them (rocketInstanceId), with
 * K1, K2 and the naming rule checked on each.
 */
const SCAN = `(function(){
    var PLUGIN = new RegExp('^data-(' + ${JSON.stringify(PLUGINS.join('|'))} + ')(:|__|$)');
    var all = [];
    (function walk(root){
        for (var el of root.querySelectorAll('*')) {
            all.push(el);
            if (el.shadowRoot) walk(el.shadowRoot);
        }
    })(document);
    var hosts = all.filter(function(el){ return el.rocketInstanceId !== undefined; });
    var problems = [];
    hosts.forEach(function(host){
        var name = host.localName + (host.id ? '#' + host.id : '') + ' (' + host.rocketInstanceId + ')';
        for (var a of host.attributes) {
            if (/^data-init(:|__|$)/.test(a.name)) problems.push(name + ': ' + a.name + ' on the host (K2)');
            if (a.name.includes('_rocket.') || a.name === 'data-rocket-ref') problems.push(name + ': ' + a.name + ' on the host');
        }
        for (var el of host.querySelectorAll('*')) {
            var ignored = el.closest('[data-ignore]');
            var skip = !!ignored && ignored !== host && host.contains(ignored);
            for (var b of el.attributes) {
                if (b.name.includes('_rocket.') || b.name === 'data-rocket-ref') problems.push(name + ': ' + b.name + ' on ' + el.localName);
                else if (!skip && PLUGIN.test(b.name)) problems.push(name + ': plugin attribute ' + b.name + ' on ' + el.localName + ' (K1)');
            }
        }
    });
    var custom = all.filter(function(el){ return /^(nfsen|sb)-/.test(el.localName); });
    return {
        hosts: hosts.map(function(h){ return h.localName; }),
        problems: problems,
        undefinedTags: [...new Set(custom.filter(function(el){ return !customElements.get(el.localName); }).map(function(el){ return el.localName; }))],
        roles: custom.filter(function(el){ return el.hasAttribute('role'); }).length,
        unnamed: custom.filter(function(el){ return el.hasAttribute('role') && !(el.getAttribute('aria-label') || '').trim(); }).map(function(el){ return el.localName + (el.id ? '#' + el.id : ''); }),
    };
})()`;

/** The toast a test tagged with `label` (an expando: a light host carries no data-* attribute, K3). */
const tagged = (label) => `[...document.querySelectorAll('nfsen-toast')].find(function(t){ return t.__e2e === ${JSON.stringify(label)}; })`;

/** Whether a dotted signal path exists; reading a missing key would create it as ''. */
const hasSignalPath = (path) => `(async function(){
    var node = (await import('datastar')).root;
    for (var seg of ${JSON.stringify(path)}.split('.')) {
        if (node === null || typeof node !== 'object' || !(seg in node)) return false;
        node = node[seg];
    }
    return true;
})()`;

/** Posts refresh-graphs for `pageId` and resolves once the sync it causes has morphed the page. */
const syncAs = (pageId) => `(async function(){
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

/** Every request of the page from now on, with the body of each POST. */
async function recordRequests(page) {
    const requests = [];
    page.ws.addEventListener('message', (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.method !== 'Network.requestWillBeSent') return;
        const { request, requestId } = msg.params;
        const entry = { url: request.url, method: request.method, body: request.postData ?? null };
        if (request.method === 'POST' && entry.body === null && request.hasPostData) {
            entry.pending = page
                .send('Network.getRequestPostData', { requestId })
                .then((r) => {
                    entry.body = r.postData;
                })
                .catch(() => {});
        }
        requests.push(entry);
    });
    await page.send('Network.enable');
    return requests;
}

/** Console messages of every level, for texts that are not errors to the harness. */
function recordConsole(page) {
    const messages = [];
    page.ws.addEventListener('message', (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.method === 'Runtime.consoleAPICalled') {
            messages.push((msg.params.args || []).map((a) => a.value ?? a.description ?? '').join(' '));
        } else if (msg.method === 'Runtime.exceptionThrown') {
            messages.push(msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text);
        }
    });
    return messages;
}

function assertScan(scan, where) {
    assert.deepEqual(scan.problems, [], `${where}: Rocket hosts break K1 or K2`);
    assert.deepEqual(scan.undefinedTags, [], `${where}: every nfsen- and sb- element is defined`);
    assert.deepEqual(scan.unnamed, [], `${where}: every nfsen- and sb- element with a role has a name`);
}

/** A Rocket host with a $$ signal in `stack`: its scope exists while it is connected and goes on removal (2.1.9). */
async function scopeProbe(page, stack, label) {
    const probe = await page.evaluate(`(async function(){
        var ds = await import('datastar');
        if (!customElements.get('e2e-scope-probe')) ds.rocket('e2e-scope-probe', { mode: 'light' });
        await customElements.whenDefined('e2e-scope-probe');
        var p = document.createElement('e2e-scope-probe');
        p.innerHTML = '<span data-signals:n="1" data-text="$$n"></span>';
        document.querySelector(${JSON.stringify(stack)}).append(p);
        window.__e2eProbe = p;
        return { id: p.rocketInstanceId, path: p.rocketSignalPath };
    })()`);
    assert.equal(probe.path, `_rocket.e2e_scope_probe.${probe.id}`, `${label}: the probe host's Rocket scope`);
    await page.waitFor(`window.__e2eProbe.textContent === '1'`, { label: `${label}: the probe host to show its $$ signal` });
    assert.equal(await page.evaluate(hasSignalPath(probe.path)), true, `${label}: ${probe.path} exists while the probe host is connected`);
    await page.evaluate(`window.__e2eProbe.remove(); window.__e2eProbe = null`);
    assert.equal(await page.evaluate(hasSignalPath(probe.path)), false, `${label}: ${probe.path} is gone once the probe host is removed`);
}

/**
 * Shows a toast into whatever stack showMessage picks and checks it: a Rocket host in `stack` with the
 * literal message, dismissed by its close button. It has no $$ signals (6.1), so it never has a scope.
 */
async function toastIn(page, stack, label) {
    const message = `${label}: ${TRICKY}`;
    const shown = await page.evaluate(`(function(){
        var t = window.showMessage('warning', ${JSON.stringify(message)}, false);
        t.__e2e = ${JSON.stringify(label)};
        window.__e2eGone = new WeakRef(t);
        return { id: t.rocketInstanceId, path: t.rocketSignalPath, inStack: t.parentElement === document.querySelector(${JSON.stringify(stack)}) };
    })()`);
    assert.ok(shown.id, `${label}: the toast is a Rocket host`);
    assert.equal(shown.path, `_rocket.nfsen_toast.${shown.id}`, `${label}: the toast's Rocket scope`);
    assert.ok(shown.inStack, `${label}: the toast lands in ${stack}`);
    const toast = tagged(label);
    await page.waitFor(`${toast}?.querySelector('.notice .toast-message')?.textContent === ${JSON.stringify(message)}`, {
        label: `${label}: the message in the notice`,
    });
    const notice = await page.evaluate(`(function(){
        var n = ${toast}.querySelector('.notice');
        return { role: n.getAttribute('role'), level: n.dataset.level, markup: !!n.querySelector('b'), close: n.querySelector('button[data-variant=close]')?.getAttribute('aria-label') };
    })()`);
    assert.deepEqual(notice, { role: 'alert', level: 'warning', markup: false, close: 'Dismiss notification' }, `${label}: the notice`);
    assert.equal(await page.evaluate(hasSignalPath(shown.path)), false, `${label}: the shown toast has no ${shown.path}`);
    assertScan(await page.evaluate(SCAN), `${label}, toast shown`);

    const dismissed = page.evaluate(`new Promise(function(resolve){
        document.addEventListener('nfsen-toast-dismissed', function on(e){
            if (e.target.__e2e !== ${JSON.stringify(label)}) return;
            document.removeEventListener('nfsen-toast-dismissed', on);
            resolve(e.target.isConnected);
        });
    })`);
    await page.evaluate(`${toast}.querySelector('button[data-variant=close]').click()`);
    assert.equal(await dismissed, true, `${label}: nfsen-toast-dismissed fires before the toast is removed`);
    await page.waitFor(`!${toast}`, { label: `${label}: the toast to be removed` });
    assert.equal(await page.evaluate(hasSignalPath(shown.path)), false, `${label}: no ${shown.path} after the dismissal`);
    await page.waitFor(`(function(){ var t = window.__e2eGone.deref(); return !t || t.childNodes.length === 0; })()`, {
        label: `${label}: the removed toast to empty itself (K14)`,
    });
    await scopeProbe(page, stack, label);
}

/** The first rule's Test dialog; creates a disabled rule first when there is none. */
async function openTestDialog(page, cleanups) {
    await page.gotoPage('alerts');
    await page.waitFor(`!!document.querySelector('#page-alerts #alertRules')`, { label: 'the rules table' });
    const testButton = `[...document.querySelectorAll('#page-alerts #alertRules tbody tr button')].find(function(b){ return /^Test /.test(b.getAttribute('aria-label') || ''); })`;
    if (!(await page.evaluate(`!!${testButton}`))) {
        const name = `e2e-rocket-${Date.now()}`;
        await page.evaluate(`document.querySelector('#page-alerts .alert-new-rule').click()`);
        await page.setInputValue('#page-alerts input[placeholder="e.g. High traffic on gw1"]', name);
        await page.setInputValue('#alertFormThresholdValue', '1000');
        await page.evaluate(`(function(){ var s = document.getElementById('alertFormEnabled'); if (s.checked) s.click(); })()`);
        await page.evaluate(
            `[...document.querySelectorAll('#page-alerts button')].find(function(b){ return b.textContent.trim() === 'Create rule'; }).click()`
        );
        await page.waitFor(`!!${testButton}`, { label: 'the temporary rule' });
        const deleteButton = `[...document.querySelectorAll('#page-alerts #alertRules button')].find(function(b){ return b.getAttribute('aria-label') === ${JSON.stringify(`Delete ${name}`)}; })`;
        cleanups.push(async () => {
            page.autoAcceptDialogs();
            if (await page.evaluate(`!!document.querySelector('dialog:modal')`)) await press(page, 'Escape');
            await page.gotoPage('alerts');
            await page.waitFor(`!!${deleteButton}`, { timeout: 10000, label: `the Delete button of ${name}` });
            await page.evaluate(`${deleteButton}.click()`);
            await page.waitFor(`!${deleteButton}`, { timeout: 15000, label: `the temporary rule ${name} to be deleted` });
        });
    }
    await page.evaluate(`${testButton}.click()`);
    await page.waitFor(`document.getElementById('alertTestResult')?.open`, { timeout: 20000, label: 'the Test dialog to open' });
}

async function gc(page) {
    for (let i = 0; i < 3; i++) await page.send('HeapProfiler.collectGarbage');
    return (await page.send('Memory.getDOMCounters')).nodes;
}

/** Main-frame document loads; a hash change is not one. */
function countLoads(page) {
    const loads = { count: 0 };
    page.ws.addEventListener('message', (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.method === 'Page.frameNavigated' && !msg.params.frame.parentId) loads.count++;
    });
    return loads;
}

/**
 * Loads `url` and runs `run` on that one document. A dev server restart makes php-via reload the page
 * (SseHandler.php:90), which drops expandos, counters and toasts, so an attempt that saw a reload is void.
 */
async function onOneDocument(page, name, url, run, reset = () => {}) {
    const loads = countLoads(page);
    for (let attempt = 1; ; attempt++) {
        reset();
        // A navigation that only changes the hash would keep the reloaded document.
        if (attempt > 1) await page.navigate('about:blank', { timeout: 5000 });
        await page.navigate(url);
        await page.waitForBoot();
        const start = loads.count;
        let result;
        let failure = null;
        try {
            result = await run();
        } catch (e) {
            failure = e;
            // The reload follows the restart by several seconds; the failure it causes may come first.
            for (let i = 0; i < 60 && loads.count === start; i++) await sleep(250);
        }
        if (loads.count === start) {
            if (failure) throw failure;
            return result;
        }
        const outcome = failure ? `failed with: ${failure.message.split('\n')[0]}` : 'passed';
        if (attempt === 3) {
            throw new Error(`rocket ${name}: the page reloaded (dev server restart) in each of 3 attempts; the last ${outcome}`);
        }
        console.log(
            `  (rocket ${name}: the page reloaded (dev server restart), starting again; the attempt ${outcome}; ` +
                `dropped errors: ${JSON.stringify(page.realErrors())})`
        );
        page.errors = [];
    }
}

export default async function rocketTest() {
    await withPage(async (page) => {
        const requests = await recordRequests(page);
        const consoleText = recordConsole(page);
        const reset = () => {
            requests.length = 0;
            consoleText.length = 0;
        };
        await onOneDocument(page, 'toasts and walk', `${BASE}/#/overview`, () => pageCases(page, requests, consoleText), reset);
    });

    // ── A toast asked for before nfsen-toast.js ran: a fired alert's script on the first sync ──
    await withPage(async (page) => {
        await earlyToast(page);
    });

    // ── What removed Rocket hosts leave behind: DOM nodes per host after garbage collection ──
    const heapCases = [
        ['heap', `${BASE}/#/conversations`, measureHeap, HEAP_TOASTS + 2 * HEAP_RUNS, HEAP_BOUND],
        // Before patch 0010 an emptied light host kept 3 nodes (EXP e8); now every removed host is collected.
        ['heap of hosts removed with an ancestor', `${BASE}/#/overview`, measureAncestorHeap, HEAP_TOASTS, 3],
    ];
    for (const [name, url, measure, least, bound] of heapCases) {
        await withPage(async (page) => {
            const { removed, baseline, after, alive } = await onOneDocument(page, name, url, () => measure(page));
            const perHost = (after - baseline) / removed;
            console.log(
                `  rocket ${name}: ${removed} Rocket hosts removed, DOM nodes ${baseline} -> ${after}, ${perHost.toFixed(2)} per host; ` +
                    `${alive} of ${removed} still reachable through a WeakRef`
            );
            assert.equal(alive, 0, `${name}: every removed Rocket host is collected (patch 0010), ${alive} of ${removed} are not`);
            assert.ok(removed >= least, `${name}: at least ${least} Rocket hosts removed, got ${removed}`);
            assert.ok(perHost <= bound, `${name}: a removed Rocket host keeps at most ${bound} DOM nodes, got ${perHost.toFixed(2)}`);
            assert.deepEqual(page.realErrors(), [], 'no console errors');
        });
    }
}

/** Holds nfsen-toast.js back, calls showMessage the way a server script does, then lets the module in. */
async function earlyToast(page) {
    const paused = [];
    page.ws.addEventListener('message', (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.method === 'Fetch.requestPaused') paused.push(msg.params.requestId);
    });
    await page.send('Fetch.enable', { patterns: [{ urlPattern: '*/js/components/nfsen-toast.js*', requestStage: 'Request' }] });
    // The held module delays the load event, so the navigation is not awaited through navigate().
    page.expectNavigation();
    await page.send('Page.navigate', { url: `${BASE}/#/overview` });
    await page.waitForBoot({ timeout: 20000 });
    assert.ok(paused.length > 0, 'nfsen-toast.js is held back');
    assert.equal(await page.evaluate(`Array.isArray(window.showMessage.queue)`), true, 'the layout stand-in answers showMessage');
    await page.evaluate(`window.showMessage('warning', 'Alert fired: early', true)`);
    for (const requestId of paused.splice(0)) await page.send('Fetch.continueRequest', { requestId });
    await page.send('Fetch.disable');
    await page.waitFor(
        `[...document.querySelectorAll('#alerts-toast-container nfsen-toast')].some(function(t){ return t.querySelector('.toast-message')?.textContent === 'Alert fired: early'; })`,
        { timeout: 10000, label: 'the queued toast once nfsen-toast.js has run' }
    );
    assert.equal(await page.evaluate(`window.showMessage.queue`), undefined, 'nfsen-toast.js replaced the stand-in');
    assert.deepEqual(page.realErrors(), [], 'no console errors (no "showMessage is not a function")');
}

/** Engine, toasts in all four stacks, copying, identity, names and the page walk. */
async function pageCases(page, requests, consoleText) {
    const cleanups = [];
    await page.waitForPage('overview');

    try {
        // ── One engine: the bundle once, at the import map's URL, and never datastar.js ──
        const engine = await page.evaluate(`(function(){
            var map = JSON.parse(document.querySelector('script[type=importmap]').textContent).imports;
            return {
                mapped: new URL(map.datastar, document.baseURI).href,
                loaded: performance.getEntriesByType('resource').map(function(e){ return e.name; }).filter(function(n){ return ${ENGINE}.test(new URL(n).pathname + new URL(n).search); }),
            };
        })()`);
        assert.equal(engine.loaded.length, 1, `exactly one engine resource, got ${JSON.stringify(engine.loaded)}`);
        assert.equal(engine.loaded[0], engine.mapped, 'the engine is loaded from the import map URL');
        assert.ok(/\/js\/datastar-rocket\.js\?v=/.test(engine.loaded[0]), `the Rocket bundle, versioned: ${engine.loaded[0]}`);
        assert.deepEqual(
            requests.filter((r) => new URL(r.url).pathname.endsWith('/js/datastar.js')).map((r) => r.url),
            [],
            'no request for the old datastar.js'
        );

        // ── The shell stack; its pause on hover, and a move that is not atomic ──
        await toastIn(page, '#alerts-toast-container', 'shell');
        await page.evaluate(`window.showMessage('info', 'held by the pointer', true).__e2e = 'hover'`);
        const hover = tagged('hover');
        await page.waitFor(`${hover}?.textContent.includes('held by the pointer')`, { label: 'the auto-dismissing toast' });
        const box = await page.evaluate(
            `(function(){ var r = ${hover}.getBoundingClientRect(); return { x: r.x + r.width / 3, y: r.y + r.height / 2 }; })()`
        );
        await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y });
        await sleep(6000);
        assert.ok(await page.evaluate(`!!${hover}`), 'the pointer holds an auto-dismissing toast past its 5 s');
        const moved = await page.evaluate(`(function(){
            var t = ${hover};
            var notice = t.querySelector('.notice');
            var stack = t.parentNode;
            t.remove();
            stack.append(t);
            return new Promise(function(resolve){ setTimeout(function(){ resolve(t.querySelector('.notice') === notice && t.isConnected); }); });
        })()`);
        assert.ok(moved, 'a toast taken out and put back in one task keeps its notice');
        await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 5 });
        await page.waitFor(`!${hover}`, { timeout: 8000, label: 'the toast to time out once the pointer has left' });

        // A move while the timer runs resumes with the time left (6.1), not with a fresh 5 s.
        const counting = await page.evaluate(`new Promise(function(resolve){
            var t = window.showMessage('info', 'moved while counting', true);
            setTimeout(function(){
                var notice = t.querySelector('.notice');
                var stack = t.parentNode;
                t.remove();
                stack.append(t);
                var moved = performance.now();
                t.addEventListener('nfsen-toast-dismissed', function(){
                    resolve({
                        kept: t.querySelector('.notice') === notice,
                        delay: parseFloat(t.querySelector('.toast-progress').style.animationDelay),
                        afterMove: Math.round(performance.now() - moved),
                    });
                });
                setTimeout(function(){ resolve({ afterMove: null }); }, 8000);
            }, 3000);
        })`);
        assert.equal(counting.kept, true, `the moved toast keeps its notice (${JSON.stringify(counting)})`);
        assert.ok(counting.delay <= -2500, `its progress bar resumes about 3 s in (${JSON.stringify(counting)})`);
        assert.ok(
            counting.afterMove !== null && counting.afterMove >= 1500 && counting.afterMove <= 3500,
            `it goes about 2 s after the move, not 5 s (${JSON.stringify(counting)})`
        );

        // ── The four levels in forced colours (V-A11Y): the glyph keeps its colour, the close button shows ──
        // Tagged: a fired alert's toast (Shell.php:233) can land in the same stack at any time.
        const levels = `[...document.querySelectorAll('#alerts-toast-container nfsen-toast')].filter(function(t){ return t.__e2e === 'levels'; })`;
        await page.evaluate(`['success', 'info', 'warning', 'error'].forEach(function(level){
            window.showMessage(level, level + ': ' + ${JSON.stringify(TRICKY)}, false).__e2e = 'levels';
        })`);
        await page.waitFor(
            `${levels}.filter(function(t){ return t.querySelector('.toast-message')?.textContent.endsWith(${JSON.stringify(TRICKY)}); }).length === 4`,
            { label: 'a toast of each level' }
        );
        const forced = await page.withForcedColors(async () => {
            await sleep(200);
            await page.screenshot('/tmp/nfsen-rocket-toasts-forced.png');
            return page.evaluate(`${levels}.map(function(t){
                var n = t.querySelector('.notice'), dot = n.querySelector('.status-dot'), close = n.querySelector('button[data-variant=close]');
                return n.dataset.level + ':' + getComputedStyle(dot).forcedColorAdjust + ':' + (dot.getClientRects().length > 0) + ':' + (close.getBoundingClientRect().width > 0);
            }).sort()`);
        });
        assert.deepEqual(
            forced,
            ['error:none:true:true', 'info:none:true:true', 'success:none:true:true', 'warning:none:true:true'],
            'forced colours: every level keeps its glyph and its close button'
        );
        await page.evaluate(`${levels}.forEach(function(t){ t.dismiss(); })`);
        await page.waitFor(`${levels}.length === 0`, { label: 'the four toasts to go' });

        // ── nfsen/clipboard: a copy button in text and rows mode, its label, the announcement, nfsen-copy ──
        await page.evaluate(`(function(){
            var box = document.createElement('div');
            box.id = 'e2eCopy';
            box.style.cssText = 'position:fixed;inset-block-start:8px;inset-inline-start:8px;z-index:2147483647;background:Canvas';
            box.innerHTML = '<code id="e2eCopyText">copy &lt;b&gt;me&lt;/b&gt;</code>'
                + '<button type="button" id="e2eCopyTextButton" data-copy-source="e2eCopyText">Copy</button>'
                + '<table id="e2eCopyTable"><tbody><tr><td> a </td><td>b</td></tr><tr hidden><td>hidden</td></tr>'
                + '<tr data-empty><td>empty</td></tr><tr><td>c</td><td> d </td></tr></tbody></table>'
                + '<button type="button" id="e2eCopyRowsButton" data-copy-source="e2eCopyTable" data-copy-mode="rows" data-copy-announce="Rows copied.">Copy rows</button>';
            document.getElementById('client-root').append(box);
            window.__copies = [];
            box.addEventListener('nfsen-copy', function(e){ window.__copies.push(e.target.id + ':' + e.detail.ok + ':' + e.detail.text); });
        })()`);
        for (const [id, label, text, said] of [
            ['e2eCopyTextButton', 'Copy', 'copy <b>me</b>', 'Copied.'],
            ['e2eCopyRowsButton', 'Copy rows', 'a  b\nc  d', 'Rows copied.'],
        ]) {
            const button = `document.getElementById(${JSON.stringify(id)})`;
            const copied = page.evaluate(nextCopy);
            await clickAt(page, button);
            assert.equal(await copied, text, `${id}: what is copied`);
            await page.waitFor(`${button}.textContent === 'Copied'`, { label: `${id}: the label to say Copied` });
            await page.waitFor(`document.getElementById('nfsen-announcer').textContent === ${JSON.stringify(said)}`, {
                label: `${id}: #nfsen-announcer to say ${said}`,
            });
            await page.waitFor(`${button}.textContent === ${JSON.stringify(label)}`, {
                timeout: 4000,
                label: `${id}: its label again`,
            });
            await page.waitFor(`document.getElementById('nfsen-announcer').textContent === ''`, {
                timeout: 4000,
                label: `${id}: the announcer to empty, so a repeat is heard`,
            });
        }
        assert.deepEqual(
            await page.evaluate('window.__copies'),
            ['e2eCopyTextButton:true:copy <b>me</b>', 'e2eCopyRowsButton:true:a  b\nc  d'],
            'nfsen-copy carries ok and the text'
        );
        await page.evaluate(`document.getElementById('e2eCopy').remove()`);

        // ── Identity across a Flows run and two walks through every page ──
        await page.waitFor(`!!document.querySelector('#trafficGraph .chart-container canvas')`, {
            timeout: 15000,
            label: 'the traffic graph to draw',
        });
        await page.gotoPage('flows');
        await page.setRangePreset('1y');
        const MARKED = ['#trafficGraph', '#trafficGraph .chart-container', '#alerts-toast-container', '#modal-root', '#client-root'];
        await page.evaluate(`${JSON.stringify(MARKED)}.forEach(function(s){ document.querySelector(s).__keep = 1; })`);
        // A toast that stays through the walk, so a Rocket host is there on every page.
        await page.evaluate(`window.showMessage('info', ${JSON.stringify(`walk: ${TRICKY}`)}, false).__e2e = 'walk'`);
        const walkStart = requests.length;
        await page.runQuery('flows', { timeout: 60000 });
        let roles = 0;
        for (let pass = 1; pass <= 2; pass++) {
            for (const id of [...PAGES.filter((p) => p !== 'flows'), 'flows']) {
                await page.gotoPage(id);
                await sleep(400);
                const scan = await page.evaluate(SCAN);
                assert.ok(scan.hosts.length > 0, `${id}: at least one Rocket host to check`);
                assertScan(scan, `pass ${pass}, ${id}`);
                roles += scan.roles;
            }
        }
        assert.ok(roles > 0, 'the walk met nfsen- elements with a role to check');
        const kept = await page.evaluate(`${JSON.stringify(MARKED)}.filter(function(s){
            var el = document.querySelector(s);
            return !el || el.__keep !== 1;
        })`);
        assert.deepEqual(kept, [], 'a Flows run and the page switches keep these nodes');
        assert.ok(
            await page.evaluate(`!!document.querySelector('#trafficGraph .chart-container canvas')`),
            "the traffic graph's canvas is still in its container"
        );

        const walk = requests.slice(walkStart).filter((r) => r.method === 'POST');
        await Promise.all(walk.map((r) => r.pending));
        assert.ok(walk.length >= PAGES.length, `the walk posted (${walk.length} posts)`);
        assert.deepEqual(
            walk.filter((r) => r.body === null || r.body.includes('_rocket')).map((r) => r.url),
            [],
            'no post carries a _rocket signal'
        );
        assert.deepEqual(page.realErrors(), [], 'the walk raises no error');
        assert.deepEqual(
            consoleText.filter((t) => /Maximum call stack|UndefinedAction/.test(t)),
            [],
            'no runaway recursion and no undefined action'
        );
        await page.evaluate(`${tagged('walk')}.dismiss()`);
        await page.waitFor(`!${tagged('walk')}`, { label: 'the walk toast to go' });

        // ── nfsen/clipboard on Flows: the Raw tab's Copy of the command ──
        await page.evaluate(`document.getElementById('flowsTab-raw').click()`);
        const copyCommand = `document.querySelector('#flowsPanel-raw button[data-copy-source="flowsRawCommand"]')`;
        await page.waitFor(`!document.getElementById('flowsPanel-raw').hidden && !!${copyCommand}`, {
            timeout: 15000,
            label: "the Raw tab's command and its Copy button",
        });
        const command = await page.evaluate(`document.getElementById('flowsRawCommand').textContent`);
        const copiedCommand = page.evaluate(nextCopy);
        await clickAt(page, copyCommand);
        assert.equal(await copiedCommand, command, 'the Flows Copy button copies the command');
        await page.waitFor(`${copyCommand}.textContent === 'Copied'`, { label: 'the Flows Copy button to say Copied' });
        await page.waitFor(`${copyCommand}.textContent === 'Copy'`, {
            timeout: 4000,
            label: 'the Flows Copy button to read Copy again',
        });
        await page.evaluate(`document.getElementById('flowsTab-flows').click()`);
        await page.waitFor(`!document.getElementById('flowsPanel-flows').hidden`, { label: 'the Flows tab again' });

        // ── The IP modal's stack; the modal and its toast across a sync ──
        await page.waitFor(`!!document.querySelector('#page-flows .ip-link')`, {
            timeout: 20000,
            label: 'an IP link in the Flows result',
        });
        await page.evaluate(`document.querySelector('#page-flows .ip-link').click()`);
        await page.waitFor(`document.getElementById('ip-modal-inner')?.open`, { timeout: 15000, label: 'the IP modal to open' });
        await page.evaluate(`document.getElementById('ip-modal-inner').__keep = 1`);
        await page.evaluate(`window.showMessage('error', 'kept by the modal', false).__e2e = 'modal-sync'`);
        assert.equal(await page.evaluate(syncAs('flows')), 'synced', 'a sync arrives while the IP modal is open');
        assert.deepEqual(
            await page.evaluate(`({
                modal: document.getElementById('ip-modal-inner')?.__keep === 1,
                toast: !!${tagged('modal-sync')}?.closest('#ip-modal-inner > .toast-stack') && !!${tagged('modal-sync')}.querySelector('.notice'),
            })`),
            { modal: true, toast: true },
            'the IP modal and its toast survive the sync'
        );
        await page.evaluate(`${tagged('modal-sync')}.dismiss()`);
        await toastIn(page, '#ip-modal-inner > .toast-stack', 'ip-modal');
        await press(page, 'Escape');
        await page.waitFor(`!document.getElementById('ip-modal-inner').open`, { label: 'Escape to close the IP modal' });

        // ── The drawer's stack; its editor across a sync ──
        await page.evaluate(
            `(function(){ var b = document.querySelector('[data-filter-field="flows"] [data-open-drawer="builder"]'); b.focus(); b.click(); })()`
        );
        await page.waitFor(`document.getElementById('filter-drawer').open && !!document.getElementById('drawerFilterTextarea')`, {
            timeout: 10000,
            label: 'the drawer to open',
        });
        await page.evaluate(`document.getElementById('drawerFilterTextarea').__keep = 1`);
        assert.equal(await page.evaluate(syncAs('flows')), 'synced', 'a sync arrives while the drawer is open');
        assert.equal(
            await page.evaluate(`document.getElementById('drawerFilterTextarea')?.__keep`),
            1,
            'the drawer editor survives the sync'
        );
        await toastIn(page, '#filter-drawer > .toast-stack', 'drawer');
        await press(page, 'Escape');
        await page.waitFor(`!document.getElementById('filter-drawer').open`, { label: 'Escape to close the drawer' });

        // ── The alert Test dialog's stack; running a Test records an event ──
        if (SKIP_MUTATING) {
            console.log("  (rocket: the alert Test dialog's toast skipped, E2E_SKIP_MUTATING)");
        } else {
            await openTestDialog(page, cleanups);
            await toastIn(page, '#alertTestResult > .toast-stack', 'alert-test');
            await press(page, 'Escape');
            await page.waitFor(`!document.getElementById('alertTestResult').open`, { label: 'Escape to close the Test dialog' });
        }

        assert.deepEqual(page.realErrors(), [], 'no console errors');
    } finally {
        for (const cleanup of cleanups) await cleanup().catch((e) => console.warn(`  (cleanup incomplete: ${e.message})`));
    }
}

/** Every Rocket host seen, held weakly; a removed one is disconnected or already collected. */
const HEAP_HELPERS = `(function(){
    var seen = new WeakSet();
    window.__hosts = [];
    window.__snap = function(){
        for (var el of document.querySelectorAll('*')) {
            if (el.rocketInstanceId === undefined || seen.has(el)) continue;
            seen.add(el);
            window.__hosts.push(new WeakRef(el));
        }
    };
    window.__removed = function(){
        return window.__hosts.filter(function(r){ var h = r.deref(); return !h || !h.isConnected; }).length;
    };
    window.__alive = function(){
        return window.__hosts.filter(function(r){ var h = r.deref(); return !!h && !h.isConnected; }).length;
    };
    window.__toasts = async function(n){
        var shown = [];
        for (var i = 0; i < n; i++) {
            var t = window.showMessage('info', 'heap ' + i, false);
            t.__e2e = 'heap';
            shown.push(t);
        }
        window.__snap();
        await new Promise(function(r){ requestAnimationFrame(function(){ requestAnimationFrame(r); }); });
        shown.forEach(function(t){ t.dismiss(); });
        shown = null;
    };
})()`;

/** Runs `target` until the result host that `hostId` names is a new one, then records the hosts it brought. */
function resultRun(page, target, hostId) {
    return async () => {
        const before = await page.evaluate(hostId);
        await page.runQuery(target, { timeout: 60000 });
        await page.waitFor(`(${hostId}) !== ${JSON.stringify(before)}`, { timeout: 60000, label: `a new ${target} result` });
        await page.evaluate('window.__snap()');
    };
}

/** DOM nodes before and after `runs` more results, and the Rocket hosts removed in between. */
async function heapAround(page, run, runs, extra = async () => {}) {
    // Warm up, so what the first run creates for good is in the baseline.
    await run();
    await sleep(300);
    const removedBefore = await page.evaluate('window.__removed()');
    const baseline = await gc(page);
    const aliveBefore = await page.evaluate('window.__alive()');
    await extra();
    for (let i = 0; i < runs; i++) await run();
    await sleep(300);
    const removed = (await page.evaluate('window.__removed()')) - removedBefore;
    const after = await gc(page);
    const alive = (await page.evaluate('window.__alive()')) - aliveBefore;
    return { removed, baseline, after, alive };
}

/**
 * Shows and dismisses the toasts and runs Conversations: each run replaces the result hosts, and the
 * Sankey and Matrix hosts inside go with their ancestor.
 */
async function measureHeap(page) {
    await page.gotoPage('conversations');
    await page.setRangePreset('1y');
    await page.evaluate(HEAP_HELPERS);
    const heapToast = `[...document.querySelectorAll('nfsen-toast')].some(function(t){ return t.__e2e === 'heap'; })`;
    const run = resultRun(page, 'conversations', `document.querySelector('#convPanel-sankey .result-host')?.id ?? ''`);
    await page.evaluate('window.__toasts(5)');
    await page.waitFor(`!${heapToast}`, { label: 'the warm-up toasts to go' });
    return heapAround(page, run, HEAP_RUNS, async () => {
        await page.evaluate(`window.__toasts(${HEAP_TOASTS})`);
        await page.waitFor(`!${heapToast}`, { timeout: 15000, label: 'the toasts to go' });
    });
}

/** Toasts in a box that goes as a whole: no host is removed itself, each goes with its ancestor. */
async function measureAncestorHeap(page) {
    await page.waitForPage('overview');
    await page.evaluate(HEAP_HELPERS);
    const run = async () => {
        await page.evaluate(`(async function(){
            var box = document.createElement('div');
            for (var i = 0; i < ${HEAP_TOASTS / 2}; i++) {
                var t = document.createElement('nfsen-toast');
                t.level = 'info';
                t.message = 'boxed ' + i;
                box.append(t);
            }
            document.getElementById('client-root').append(box);
            await new Promise(function(r){ requestAnimationFrame(function(){ requestAnimationFrame(r); }); });
            window.__snap();
            box.remove();
        })()`);
    };
    return heapAround(page, run, 2);
}

if (import.meta.url === `file://${process.argv[1]}`) {
    rocketTest()
        .then(() => console.log('rocket: PASS'))
        .catch((e) => {
            console.error('rocket: FAIL\n', e);
            process.exit(1);
        });
}
