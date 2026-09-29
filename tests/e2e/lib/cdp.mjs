// Shared driver for the E2E test suite: launches headless Chrome and drives
// it over the DevTools Protocol using Node's built-in WebSocket (Node >= 22),
// the same approach already proven in book/_capture.mjs for the screenshot
// pipeline. No Playwright/Puppeteer dependency -- this project already
// vendors its own frontend assets rather than pulling in libraries where a
// small amount of hand-written code does the job, and this is the same call.
//
// Each test file drives the real running app (docker-compose.dev.yml, or
// whatever BASE points at) exactly the way a human would: click the real
// nav link, wait for the real SSE-pushed re-render, assert on the real DOM.
// There is no mocked backend and no seeded test database -- see
// docs/features (superseded by the book) and book/src/development/testing.md
// for why nfsen-ng doesn't have one.
import { spawn } from 'node:child_process';
import { mkdtempSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join } from 'node:path';

export const BASE = process.env.BASE || 'http://localhost:8080';

// Errors that are known-benign and unrelated to whatever a test is checking. Empty since every
// view transition handles its own promises (nfsen-chart.js, layout.html.twig).
const BENIGN_ERROR_PATTERNS = [];

export function isBenignError(text) {
    return BENIGN_ERROR_PATTERNS.some((re) => re.test(text));
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

// Newest non-snap Chrome (matches book/_capture.mjs's resolver -- snap
// chromium can't write screenshots/profiles to /tmp in this sandbox).
function resolveChrome() {
    if (process.env.CHROME) return process.env.CHROME;
    const base = join(homedir(), '.cache/ms-playwright');
    const found = readdirSync(base)
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

async function waitForDevtoolsPort(port, timeout = 10000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        try {
            const res = await fetch(`http://127.0.0.1:${port}/json/version`);
            if (res.ok) return true;
        } catch {}
        await sleep(150);
    }
    return false;
}

class Page {
    constructor(ws, chrome) {
        this.ws = ws;
        this.chrome = chrome;
        this.nextId = 1;
        this.pending = new Map();
        this.errors = [];
        this.loadWaiters = [];
        ws.addEventListener('message', (ev) => {
            const msg = JSON.parse(ev.data);
            if (msg.id !== undefined && this.pending.has(msg.id)) {
                const { resolve, reject } = this.pending.get(msg.id);
                this.pending.delete(msg.id);
                if (msg.error) reject(new Error(msg.method + ': ' + JSON.stringify(msg.error)));
                else resolve(msg.result);
            } else if (msg.method === 'Runtime.exceptionThrown') {
                this.errors.push(msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text);
            } else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
                const args = (msg.params.args || []).map((a) => a.value ?? a.description ?? '').join(' ');
                this.errors.push('[console.error] ' + args);
            } else if (msg.method === 'Page.loadEventFired') {
                while (this.loadWaiters.length) this.loadWaiters.shift()();
            } else if (msg.method === 'Page.javascriptDialogOpening' && this._autoAcceptDialogs) {
                this.send('Page.handleJavaScriptDialog', { accept: true });
            } else if (msg.method === 'Network.requestWillBeSent' && this._requests) {
                const { request } = msg.params;
                if (request.method === 'POST') this._requests.push(request.url);
            }
        });
    }

    /**
     * Auto-accept any native confirm()/alert() dialog for the rest of this
     * page's life (e.g. the alert-rule delete button's `confirm('Delete rule
     * ...?')`). Off by default so a test that cares about dialog-cancellation
     * behaviour isn't silently short-circuited.
     */
    autoAcceptDialogs() {
        this._autoAcceptDialogs = true;
    }

    send(method, params = {}) {
        const id = this.nextId++;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    async init() {
        await this.send('Page.enable');
        await this.send('Runtime.enable');
    }

    /** Resolves on the load event; a same-document navigation fires none, so it gives up after `timeout`. */
    async navigate(url, { timeout = 30000 } = {}) {
        const loaded = this.loaded(timeout);
        await this.send('Page.navigate', { url });
        await loaded;
    }

    async reload({ timeout = 30000 } = {}) {
        const loaded = this.loaded(timeout);
        await this.send('Page.reload');
        await loaded;
    }

    /** The next load event, or on timeout whatever the document holds by then. */
    loaded(timeout) {
        return new Promise((resolve) => {
            const done = () => {
                clearTimeout(timer);
                this.loadWaiters = this.loadWaiters.filter((w) => w !== done);
                resolve();
            };
            const timer = setTimeout(done, timeout);
            this.loadWaiters.push(done);
        });
    }

    /** Real, non-benign errors only -- filters BENIGN_ERROR_PATTERNS out. */
    realErrors() {
        return this.errors.filter((e) => !isBenignError(e));
    }

    async evaluate(expression) {
        const r = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) {
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
            } catch {
                // keep polling -- the expression may reference something not yet on the page
            }
            await sleep(interval);
        }
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
                if (attempt >= attempts) throw e;
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

    /** Click `button[data-run=<target>]`, wait for its query control to go running (a fast query
        may skip that) and back to idle. */
    async runQuery(target, { timeout = 20000 } = {}) {
        const control = `document.querySelector('button[data-run="${target}"]')?.closest('.query-control')`;
        await this.waitFor(`!!document.querySelector('button[data-run="${target}"]:not(:disabled)')`, {
            label: `run button for ${target}`,
        });
        await this.evaluate(`document.querySelector('button[data-run="${target}"]').click()`);
        await this.waitFor(`${control}?.dataset.queryState === 'running'`, { timeout: 5000, label: `${target} query to start` }).catch(
            () => {}
        );
        await this.waitFor(`${control}?.dataset.queryState === 'idle'`, { timeout, label: `${target} query to finish` });
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

    /**
     * Click the first element whose `data-on:click*` attribute contains `sub`
     * -- the exact path a real user click takes, the same technique
     * book/_capture.mjs uses.
     */
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
 * Launch headless Chrome, open one page, and hand it to `fn`. Always tears
 * the browser down afterwards, even on failure, so a failing test doesn't
 * leak a Chrome process. `mobile: true` emulates a phone at width x height
 * (device metrics with mobile: true, and touch).
 */
export async function withPage(fn, { width = 1400, height = 1100, port, mobile = false } = {}) {
    const chromePort = port || 9400 + Math.floor(Math.random() * 500);
    const userDataDir = mkdtempSync(join(tmpdir(), 'nfsen-e2e-'));
    const chrome = spawn(
        resolveChrome(),
        [
            '--headless=new',
            '--no-sandbox',
            '--disable-gpu',
            `--remote-debugging-port=${chromePort}`,
            '--remote-allow-origins=*',
            '--no-first-run',
            '--no-default-browser-check',
            `--user-data-dir=${userDataDir}`,
            `--window-size=${width},${height}`,
        ],
        { stdio: ['ignore', 'ignore', 'pipe'] }
    );

    let page;
    try {
        const ready = await waitForDevtoolsPort(chromePort);
        if (!ready) throw new Error('Chrome DevTools port never came up');

        const tabsRes = await fetch(`http://127.0.0.1:${chromePort}/json/list`);
        const tabs = await tabsRes.json();
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
        return await fn(page);
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
        // A profile takes 100 to 200 MB; the ones left behind filled the disk.
        await Promise.race([exited, sleep(5000)]);
        try {
            rmSync(userDataDir, { recursive: true, force: true, maxRetries: 3 });
        } catch {}
    }
}
