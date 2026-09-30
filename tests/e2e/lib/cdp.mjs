// Shared driver for the e2e suite: headless Chrome over the DevTools Protocol with Node's built-in
// WebSocket, against the real running app (BASE). No Playwright or Puppeteer, no mocked backend.
import { AsyncLocalStorage } from 'node:async_hooks';
import { spawn } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';

export const BASE = process.env.BASE || 'http://localhost:8080';

// Errors that are known-benign and unrelated to whatever a test is checking. Empty since every
// view transition handles its own promises (nfsen-chart.js, layout.html.twig).
const BENIGN_ERROR_PATTERNS = [];

export function isBenignError(text) {
    return BENIGN_ERROR_PATTERNS.some((re) => re.test(text));
}

/** The page loaded a new document that the test did not ask for: php-via reloads every tab when the app restarts. */
export class AppReloadedError extends Error {
    constructor(reloads) {
        const at = reloads.map((r) => new Date(r.at).toISOString().slice(11, 19)).join(', ');
        super(
            `THE APP RELOADED the page mid-test (at ${at} UTC; a dev-server restart, or php-via dropped this tab's context). ` +
                'The document the test was driving is gone; rerun the test once the app is quiet.'
        );
        this.name = 'AppReloadedError';
    }
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

// Every browser this process started. A normal exit, a signal (a timeout's SIGTERM) or an uncaught
// error kills them; if Node itself is killed, the closed debugging pipe makes Chrome exit.
const browsers = new Set();

// A killed browser may still write into its profile for a moment: the profile's watchdog removes it later.
function killBrowsers(signal = 'SIGKILL') {
    for (const b of browsers) {
        try {
            b.chrome.kill(signal);
        } catch {}
    }
    browsers.clear();
}

/** A detached shell that removes the profile once this process is gone, however it ended (SIGKILL included). */
function profileWatchdog(dir) {
    spawn('sh', ['-c', 'while kill -0 "$1" 2>/dev/null && [ -e "$0" ]; do sleep 2; done; sleep 1; rm -rf "$0"', dir, String(process.pid)], {
        detached: true,
        stdio: 'ignore',
    }).unref();
}

let guarded = false;

function guardProcess() {
    if (guarded) return;
    guarded = true;
    process.on('exit', () => killBrowsers());
    for (const [signal, code] of [
        ['SIGINT', 130],
        ['SIGTERM', 143],
        ['SIGHUP', 129],
    ]) {
        process.once(signal, () => {
            killBrowsers();
            process.exit(code);
        });
    }
}

// The test file each async chain belongs to, so a file the runner gave up on cannot start browsers.
const testFile = new AsyncLocalStorage();
const abandonedFiles = new Set();

/** Run `fn` as the named test file (run.mjs); closeAllBrowsers(name) later refuses its new browsers. */
export function runAsFile(name, fn) {
    return testFile.run(name, fn);
}

/** Kills every browser this process started, e.g. when a runner gives up on a hung test file. */
export function closeAllBrowsers(abandonedFile) {
    if (abandonedFile) abandonedFiles.add(abandonedFile);
    killBrowsers();
}

// Newest non-snap Chrome: snap chromium cannot write screenshots or profiles to /tmp in this sandbox.
function resolveChrome() {
    if (process.env.CHROME) return process.env.CHROME;
    const base = join(homedir(), '.cache/ms-playwright');
    let dirs = [];
    try {
        dirs = readdirSync(base);
    } catch {}
    const found = dirs
        .filter((d) => d.startsWith('chromium-') && !d.includes('headless_shell'))
        .map((d) => join(base, d, 'chrome-linux64/chrome'))
        .filter((p) => {
            try {
                return statSync(p).isFile();
            } catch {
                return false;
            }
        })
        .sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs);
    if (!found.length) throw new Error('no Playwright Chrome under ~/.cache/ms-playwright; set CHROME=');
    return found[0];
}

/** The port Chrome picked for --remote-debugging-port=0, from the file it writes into its profile. */
async function devtoolsPort(chrome, userDataDir, stderr, timeout = 15000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        if (chrome.exitCode !== null || chrome.signalCode !== null) break;
        try {
            const port = Number(readFileSync(join(userDataDir, 'DevToolsActivePort'), 'utf8').split('\n')[0]);
            if (port > 0 && (await fetch(`http://127.0.0.1:${port}/json/version`)).ok) return port;
        } catch {}
        await sleep(100);
    }
    throw new Error(`Chrome DevTools never came up; its last output: ${stderr().slice(-600) || '(none)'}`);
}

class Page {
    constructor(ws, chrome) {
        this.ws = ws;
        this.chrome = chrome;
        this.nextId = 1;
        this.pending = new Map();
        this.errors = [];
        this.loadWaiters = [];
        // Main-frame documents the test asked for (navigate, reload) and the ones it did not.
        this.expectedLoads = 0;
        this.reloads = [];
        this.reloadLog = [];
        // POSTs by request id, once the Network domain is on (runQuery turns it on).
        this.posts = new Map();
        ws.addEventListener('message', (ev) => {
            const msg = JSON.parse(ev.data);
            if (msg.id !== undefined && this.pending.has(msg.id)) {
                const { resolve, reject, method } = this.pending.get(msg.id);
                this.pending.delete(msg.id);
                if (msg.error) reject(new Error(`${method}: ${msg.error.message ?? JSON.stringify(msg.error)}`));
                else resolve(msg.result);
            } else if (msg.method === 'Runtime.exceptionThrown') {
                this.errors.push(msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text);
            } else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
                const args = (msg.params.args || []).map((a) => a.value ?? a.description ?? '').join(' ');
                this.errors.push('[console.error] ' + args);
            } else if (msg.method === 'Page.frameNavigated' && !msg.params.frame.parentId) {
                if (this.expectedLoads > 0) {
                    this.expectedLoads--;
                } else {
                    const reload = { at: Date.now(), url: msg.params.frame.url };
                    this.reloads.push(reload);
                    this.reloadLog.push(reload);
                }
            } else if (msg.method === 'Page.loadEventFired') {
                while (this.loadWaiters.length) this.loadWaiters.shift()();
            } else if (msg.method === 'Page.javascriptDialogOpening' && this._autoAcceptDialogs) {
                this.send('Page.handleJavaScriptDialog', { accept: true });
            } else if (msg.method === 'Network.requestWillBeSent') {
                const { request, requestId } = msg.params;
                if (request.method === 'POST') {
                    this._requests?.push(request.url);
                    this.posts.set(requestId, { url: request.url, sentAt: Date.now(), done: false });
                }
            } else if (msg.method === 'Network.loadingFinished' || msg.method === 'Network.loadingFailed') {
                const post = this.posts.get(msg.params.requestId);
                if (post) post.done = true;
            }
        });
        // A closed browser answers nothing: fail the calls in flight instead of hanging the test.
        ws.addEventListener('close', () => {
            for (const { reject, method } of this.pending.values()) reject(new Error(`${method}: the browser connection closed`));
            this.pending.clear();
        });
    }

    /**
     * Auto-accept any native confirm()/alert() dialog for the rest of this page's life (e.g. the
     * alert-rule delete button's confirm). Off by default so a test of cancelling is not short-circuited.
     */
    autoAcceptDialogs() {
        this._autoAcceptDialogs = true;
    }

    send(method, params = {}) {
        const id = this.nextId++;
        return new Promise((resolve, reject) => {
            if (this.ws.readyState !== WebSocket.OPEN) {
                reject(new Error(`${method}: the browser connection is closed`));
                return;
            }
            this.pending.set(id, { resolve, reject, method });
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    async init() {
        await this.send('Page.enable');
        await this.send('Runtime.enable');
    }

    /** Throws AppReloadedError once the page has loaded a document the test did not ask for. */
    assertNoReload() {
        if (this.reloads.length) throw new AppReloadedError(this.reloads);
    }

    /** Main-frame documents loaded without the test asking, over the page's whole life. */
    get reloadCount() {
        return this.reloadLog.length;
    }

    /** Announces a navigation the test causes itself (a link, location.reload()), so it does not count as an app reload. */
    expectNavigation() {
        this.expectedLoads++;
    }

    /** Resolves on the load event, or at once for a same-document (hash) navigation; gives up after `timeout`. */
    async navigate(url, { timeout = 30000 } = {}) {
        this.reloads = [];
        const load = this.nextLoad(timeout);
        this.expectedLoads++;
        const result = await this.send('Page.navigate', { url }).catch((e) => {
            load.done();
            throw e;
        });
        // Only a navigation to a new document has a loader; a hash change commits nothing.
        if (!result.loaderId || result.errorText) this.expectedLoads = Math.max(0, this.expectedLoads - 1);
        if (!result.loaderId) load.done();
        await load.promise;
    }

    async reload({ timeout = 30000 } = {}) {
        this.reloads = [];
        const load = this.nextLoad(timeout);
        this.expectedLoads++;
        await this.send('Page.reload');
        await load.promise;
    }

    /** The next load event, or on timeout whatever the document holds by then. */
    loaded(timeout) {
        return this.nextLoad(timeout).promise;
    }

    nextLoad(timeout) {
        let done;
        const promise = new Promise((resolve) => {
            done = () => {
                clearTimeout(timer);
                this.loadWaiters = this.loadWaiters.filter((w) => w !== done);
                resolve();
            };
            const timer = setTimeout(done, timeout);
            this.loadWaiters.push(done);
        });
        return { promise, done };
    }

    /** Real, non-benign errors only -- filters BENIGN_ERROR_PATTERNS out. */
    realErrors() {
        return this.errors.filter((e) => !isBenignError(e));
    }

    async evaluate(expression) {
        this.assertNoReload();
        let r;
        try {
            r = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        } catch (e) {
            this.assertNoReload();
            throw e;
        }
        if (r.exceptionDetails) {
            // A reload during an awaited evaluation destroys its context; say so instead.
            this.assertNoReload();
            throw new Error(
                'page eval failed: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text) + '\n  expr: ' + expression
            );
        }
        return r.result.value;
    }

    /** Poll `expr` (a JS boolean expression, evaluated as `!!(expr)`) until truthy or timeout. */
    async waitFor(expr, { timeout = 8000, interval = 150, label = expr } = {}) {
        const start = Date.now();
        while (Date.now() - start < timeout) {
            try {
                if (await this.evaluate(`!!(${expr})`)) return;
            } catch (e) {
                if (e instanceof AppReloadedError) throw e;
                // keep polling: the expression may reference something not yet on the page
            }
            await sleep(interval);
        }
        this.assertNoReload();
        throw new Error('timeout waiting for: ' + label);
    }

    /** Wait until Datastar has booted: only it marks the current sidebar item from `page`. */
    async waitForBoot(opts = {}) {
        await this.waitFor(`!!document.querySelector('.sidebar-nav a[aria-current="page"]')`, { label: 'Datastar to boot', ...opts });
    }

    /** Whether the viewport is below the 48em breakpoint, where the tab bar replaces the sidebar. */
    async isMobile() {
        return this.evaluate(`window.matchMedia('(max-width: 47.98em)').matches`);
    }

    /** The page's section has arrived (`data-ready`) and is visible. */
    async waitForPage(id, opts = {}) {
        await this.waitFor(
            `(function(){ var s = document.querySelector('#page-' + ${JSON.stringify(id)} + '[data-ready]'); return !!s && !s.hidden && s.getClientRects().length > 0; })()`,
            { label: `page "${id}" to be ready and visible`, ...opts }
        );
    }

    /** Click the page's link (tab bar or More menu on phones) and wait for its content. Retries:
        a click before Datastar is wired changes the hash with nobody listening. */
    async gotoPage(id, { attempts = 10, timeout = 3000 } = {}) {
        const href = JSON.stringify(`#/${id}`);
        for (let attempt = 1; ; attempt++) {
            const clicked = await this.evaluate(`(function(){
                var href = ${href};
                var visible = function(el){ return !!el && el.getClientRects().length > 0; };
                var link = [...document.querySelectorAll('.sidebar-nav a, .tabbar a')].find(function(a){ return a.getAttribute('href') === href && visible(a); });
                if (!link) {
                    var more = document.querySelector('.tabbar .menu-toggle');
                    var item = [...document.querySelectorAll('.tabbar .menu-list a')].find(function(a){ return a.getAttribute('href') === href; });
                    if (visible(more) && item) {
                        if (!visible(item)) more.click();
                        link = item;
                    }
                }
                if (!link) return false;
                link.click();
                return true;
            })()`);
            if (!clicked) throw new Error(`no navigation link to #/${id}`);
            try {
                await this.waitForPage(id, { timeout });
                return;
            } catch (e) {
                if (attempt >= attempts || e instanceof AppReloadedError) throw e;
            }
        }
    }

    /** A signal's value from Datastar's own store, by wire id prefix `<name>____` or exact name. */
    async signalValue(name) {
        return (await this.signalValues([name]))[name];
    }

    /** Several signals read in one evaluate, so a patch between the reads cannot tear them apart. */
    async signalValues(names) {
        return this.evaluate(`(async function(){
            var root = (await import('datastar')).root;
            var keys = Object.keys(root);
            var out = {};
            ${JSON.stringify(names)}.forEach(function(name){
                var key = keys.find(function(k){ return k === name || k.startsWith(name + '____'); });
                out[name] = key === undefined ? undefined : JSON.parse(JSON.stringify(root[key]));
            });
            return out;
        })()`);
    }

    /** Post refresh-graphs as page `pageId` and resolve once the sync it causes has morphed the page. */
    async syncNow(pageId, { timeout = 10000 } = {}) {
        const result = await this.evaluate(`(async function(){
            var html = document.documentElement.outerHTML;
            var ctx = (html.match(/via_ctx&quot;:&quot;([^&]+)&quot;/) || html.match(/via_ctx":"([^"]+)"/) || [])[1];
            var pageSignal = (html.match(/\\bpage____[a-z0-9]+/) || [])[0];
            var url = (html.match(/[^'"\\s]*_action\\/refresh-graphs[A-Za-z0-9-]*/) || [])[0];
            if (!ctx || !pageSignal || !url) return 'no context, page signal or refresh-graphs action in the page';
            var probe = document.getElementById('page-content');
            probe.setAttribute('data-e2e-sync', '');
            var body = { via_ctx: ctx };
            body[pageSignal] = ${JSON.stringify(pageId)};
            var res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Datastar-Request': 'true' }, body: JSON.stringify(body) });
            if (!res.ok) return 'refresh-graphs answered ' + res.status + ' ' + (await res.text()).slice(0, 200);
            var until = Date.now() + ${Number(timeout)};
            while (Date.now() < until && probe.isConnected && probe.hasAttribute('data-e2e-sync')) await new Promise(function(r){ setTimeout(r, 50); });
            return probe.hasAttribute('data-e2e-sync') && probe.isConnected ? 'no sync within ${Number(timeout)} ms' : 'synced';
        })()`);
        if (result === 'synced') return;
        // A restarted app answers the old context with 400 and reloads the tab a moment later.
        if (/ 400 Invalid context/.test(result)) {
            for (const until = Date.now() + 5000; !this.reloads.length && Date.now() < until; ) await sleep(100);
        }
        this.assertNoReload();
        throw new Error(`syncNow(${pageId}): ${result}`);
    }

    /** Pick a range preset from #rangeMenu and wait for its width. */
    async setRangePreset(id, { timeout = 8000 } = {}) {
        const seconds = { '1h': 3600, '24h': 86400, '7d': 604800, '30d': 2592000, '1y': 31536000 }[id];
        if (!seconds) throw new Error('unknown range preset: ' + id);
        await this.evaluate(`(function(){
            var toggle = document.querySelector('#rangeMenu .menu-toggle');
            if (toggle && toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
        })()`);
        await this.waitFor(`!!document.querySelector('[data-range-preset="${id}"]')?.getClientRects().length`, {
            label: `preset ${id} in the range menu`,
        });
        await this.evaluate(`document.querySelector('[data-range-preset="${id}"]').click()`);
        const start = Date.now();
        for (;;) {
            const { datestart: from, dateend: to } = await this.signalValues(['datestart', 'dateend']);
            if (Math.abs(to - from - seconds) <= 300) return;
            if (Date.now() - start > timeout) throw new Error(`range preset ${id}: window is ${to - from} s, expected ${seconds} s`);
            await sleep(150);
        }
    }

    /**
     * Click `button[data-run=<target>]` and wait for its query to start and finish, or for its post
     * to be answered without a run (a result the server already has, a refusal).
     */
    async runQuery(target, { timeout = 20000, readyTimeout = 30000, startTimeout = 15000, settle = 1500 } = {}) {
        const button = `document.querySelector('button[data-run="${target}"]')`;
        const control = `${button}?.closest('.query-control')`;
        await this.send('Network.enable');
        await this.waitFor(`!!document.querySelector('button[data-run="${target}"]:not(:disabled)')`, {
            timeout: readyTimeout,
            label: `run button for ${target}`,
        });
        const clickedAt = Date.now();
        const action = await this.evaluate(`(function(){
            var c = ${control};
            c.__e2eStarts = 0;
            c.__e2eObserver?.disconnect();
            c.__e2eObserver = new MutationObserver(function(records){
                if (records.some(function(r){ return r.oldValue === 'running' || c.dataset.queryState === 'running'; })) c.__e2eStarts++;
            });
            c.__e2eObserver.observe(c, { attributeFilter: ['data-query-state'], attributeOldValue: true });
            var b = ${button};
            b.click();
            return ((b.getAttribute('data-on:click') || '').match(/@post\\('([^']+)'/) || [])[1] || '';
        })()`);
        const started = () => this.evaluate(`${control}?.__e2eStarts > 0`);
        // Panel buttons post with a query string ('?panel=proto'); match on the path.
        const actionPath = action && new URL(action, BASE).pathname;
        const answered = () =>
            [...this.posts.values()].find((p) => p.done && p.sentAt >= clickedAt && actionPath && new URL(p.url).pathname === actionPath);
        try {
            const start = Date.now();
            let answeredAt = null;
            for (;;) {
                this.assertNoReload();
                if (await started()) {
                    await this.waitFor(`${control}?.dataset.queryState === 'idle'`, { timeout, label: `${target} query to finish` });
                    return;
                }
                if (answeredAt === null && answered()) answeredAt = Date.now();
                if (answeredAt !== null && Date.now() - answeredAt > settle) return;
                if (Date.now() - start > startTimeout) {
                    throw new Error(`the ${target} query did not start: its post to ${action || '?'} got no answer`);
                }
                await sleep(100);
            }
        } finally {
            await this.evaluate(`${control}?.__e2eObserver?.disconnect()`).catch(() => {});
        }
    }

    /** Choose a theme from the sidebar's theme menu: light, dark, system or default. */
    async chooseTheme(choice) {
        await this.evaluate(`(function(){
            var toggle = document.querySelector('#themeMenu .menu-toggle');
            if (toggle && toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
        })()`);
        await this.waitFor(`!!document.querySelector('[data-theme-choice="${choice}"]')`, { label: `theme choice ${choice}` });
        await this.evaluate(`document.querySelector('[data-theme-choice="${choice}"]').click()`);
    }

    /** Record the POSTs sent from now on; names() gives action names without the random suffix. */
    async requestLog() {
        await this.send('Network.enable');
        this._requests = [];
        const nameOf = (url) => {
            const path = new URL(url).pathname;
            const i = path.indexOf('/_action/');
            return i === -1 ? null : path.slice(i + '/_action/'.length).replace(/-[0-9a-f]{8,}$/, '');
        };
        return {
            names: () => this._requests.map(nameOf).filter((n) => n !== null),
            count: (name) => this._requests.map(nameOf).filter((n) => n === name).length,
            clear: () => {
                this._requests.length = 0;
            },
        };
    }

    /** Run `fn` with forced colors emulated, then restore the media features. */
    async withForcedColors(fn) {
        await this.send('Emulation.setEmulatedMedia', { features: [{ name: 'forced-colors', value: 'active' }] });
        try {
            return await fn(this);
        } finally {
            await this.send('Emulation.setEmulatedMedia', { features: [] });
        }
    }

    /** Click the first element whose `data-on:click*` attribute contains `sub`, the path a real click takes. */
    async clickByAttr(sub) {
        const clicked = await this.evaluate(`(function(){
            var all = document.querySelectorAll('*');
            for (var i = 0; i < all.length; i++) {
                var attrs = all[i].attributes;
                for (var j = 0; j < attrs.length; j++) {
                    if (attrs[j].name.indexOf('data-on:click') === 0 && attrs[j].value.indexOf(${JSON.stringify(sub)}) >= 0) {
                        all[i].click();
                        return true;
                    }
                }
            }
            return false;
        })()`);
        if (!clicked) throw new Error('no element found with data-on:click* containing: ' + sub);
    }

    /** Click the first VISIBLE element matching `tag` (default button,a) whose text includes `text`. */
    async clickByText(text, tag = 'button,a') {
        const clicked = await this.evaluate(`(function(){
            var els = document.querySelectorAll(${JSON.stringify(tag)});
            var el = [...els].find(function(e){ return e.offsetParent !== null && e.textContent.trim().includes(${JSON.stringify(text)}); });
            if (el) { el.click(); return true; }
            return false;
        })()`);
        if (!clicked) throw new Error(`no visible ${tag} found containing text: ${text}`);
    }

    async setSelectValue(selector, value) {
        const ok = await this.evaluate(`(function(){
            var e = document.querySelector(${JSON.stringify(selector)});
            if (!e) return false;
            var setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
            setter.call(e, String(${JSON.stringify(value)}));
            e.dispatchEvent(new Event('input', {bubbles:true}));
            e.dispatchEvent(new Event('change', {bubbles:true}));
            return true;
        })()`);
        if (!ok) throw new Error('select not found: ' + selector);
    }

    /** Set a Datastar `{{ bind(signal) }}`-bound <input>'s value the way real typing would. */
    async setInputValue(selector, value) {
        const ok = await this.evaluate(`(function(){
            var e = document.querySelector(${JSON.stringify(selector)});
            if (!e) return false;
            var proto = e.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            var setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
            setter.call(e, String(${JSON.stringify(value)}));
            e.dispatchEvent(new Event('input', {bubbles:true}));
            e.dispatchEvent(new Event('change', {bubbles:true}));
            return true;
        })()`);
        if (!ok) throw new Error('input not found: ' + selector);
    }

    async screenshot(path) {
        const { data } = await this.send('Page.captureScreenshot', { format: 'png' });
        writeFileSync(path, Buffer.from(data, 'base64'));
    }

    async close() {
        this.ws.close();
        this.chrome.kill();
    }
}

/**
 * Launch headless Chrome, open one page, and hand it to `fn`. The browser always goes afterwards,
 * also on failure. `mobile: true` emulates a phone at width x height (mobile metrics and touch).
 */
export async function withPage(fn, { width = 1400, height = 1100, port = 0, mobile = false } = {}) {
    const file = testFile.getStore();
    if (abandonedFiles.has(file)) throw new Error(`${file} failed or timed out; it may not open another browser`);
    guardProcess();
    const userDataDir = mkdtempSync(join(tmpdir(), 'nfsen-e2e-'));
    profileWatchdog(userDataDir);
    // fd 3 and 4 are the debugging pipe: nothing is sent on it, but Chrome exits once it closes.
    const chrome = spawn(
        resolveChrome(),
        [
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            `--remote-debugging-port=${port}`,
            '--remote-debugging-pipe',
            '--remote-allow-origins=*',
            '--no-first-run',
            '--no-default-browser-check',
            `--user-data-dir=${userDataDir}`,
            `--window-size=${width},${height}`,
        ],
        { stdio: ['ignore', 'ignore', 'pipe', 'pipe', 'pipe'] }
    );
    const browser = { chrome, userDataDir };
    browsers.add(browser);
    let stderrTail = '';
    chrome.stdio[2].on('data', (chunk) => {
        stderrTail = (stderrTail + chunk).slice(-4096);
    });
    chrome.stdio[4].resume();
    chrome.on('error', () => {});

    let page;
    try {
        const chromePort = await devtoolsPort(chrome, userDataDir, () => stderrTail);
        const tabs = await (await fetch(`http://127.0.0.1:${chromePort}/json/list`)).json();
        const tab = tabs.find((t) => t.type === 'page');
        if (!tab) throw new Error('no page target found');

        const ws = new WebSocket(tab.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => {
            ws.addEventListener('open', resolve);
            ws.addEventListener('error', (e) => reject(new Error('ws error: ' + (e.message || e.type))));
        });

        page = new Page(ws, chrome);
        await page.init();
        if (mobile) {
            await page.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 2, mobile: true });
            await page.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
        }
        try {
            return await fn(page);
        } catch (e) {
            if (e instanceof Error && page.reloadCount > 0 && !(e instanceof AppReloadedError)) {
                const at = page.reloadLog.map((r) => new Date(r.at).toISOString().slice(11, 19)).join(', ');
                const note = `\n  (THE APP RELOADED the page during this test at ${at} UTC; a dev-server restart voids the run)`;
                // Node prints the stack, which was written before the note.
                e.message += note;
                if (typeof e.stack === 'string') e.stack += note;
            }
            throw e;
        }
    } finally {
        const exited = new Promise((resolve) =>
            chrome.exitCode !== null || chrome.signalCode !== null ? resolve() : chrome.once('exit', resolve)
        );
        if (page) {
            try {
                await page.close();
            } catch {}
        } else {
            chrome.kill();
        }
        await Promise.race([exited, sleep(5000)]);
        if (chrome.exitCode === null && chrome.signalCode === null) chrome.kill('SIGKILL');
        browsers.delete(browser);
        // A profile takes 100 to 200 MB; the ones left behind filled the disk.
        try {
            rmSync(userDataDir, { recursive: true, force: true, maxRetries: 3 });
        } catch {}
    }
}
