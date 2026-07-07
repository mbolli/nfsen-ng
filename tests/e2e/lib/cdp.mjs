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
import { mkdtempSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join } from 'node:path';

export const BASE = process.env.BASE || 'http://localhost:8080';

// Errors that are known-benign and unrelated to whatever a test is checking --
// see docs note in nfsen-chart.js / nfsen-sankey.js: rapid programmatic
// interaction (as E2E tests do) can abort a CSS view-transition mid-flight.
// This is cosmetic (the DOM update it wraps still applies) and reproducible
// with real ECharts + view-transition-name usage under fast enough
// interaction, not a regression to chase to zero.
const BENIGN_ERROR_PATTERNS = [/AbortError: Transition was skipped/];

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

    async navigate(url) {
        const loaded = new Promise((resolve) => this.loadWaiters.push(resolve));
        await this.send('Page.navigate', { url });
        await loaded;
    }

    /** Real, non-benign errors only -- filters BENIGN_ERROR_PATTERNS out. */
    realErrors() {
        return this.errors.filter((e) => !isBenignError(e));
    }

    async evaluate(expression) {
        const r = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) {
            throw new Error('page eval failed: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text) + '\n  expr: ' + expression);
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

    /**
     * Wait until some element has a `data-show` attribute exactly equal to
     * `signal == 'value'` (nfsen-ng's Datastar view-switch convention, e.g.
     * `$_currentView == 'flows'`) and is actually visible (offsetParent !==
     * null). Uses an *exact* attribute match rather than a substring one --
     * `[data-show*="import"]` looks tempting but nfsen-ng's hashed signal ids
     * (e.g. `import_running____<hash>`) routinely contain unrelated tab/section
     * names as substrings, silently matching the wrong (often hidden) element.
     */
    async waitForPanel(signal, value, opts = {}) {
        const attr = `${signal} == '${value}'`;
        await this.waitFor(
            `[...document.querySelectorAll('[data-show]')].some(function(e){
                return e.getAttribute('data-show') === ${JSON.stringify(attr)} && e.offsetParent !== null;
            })`,
            { label: `a visible panel with data-show="${attr}"`, ...opts }
        );
    }

    /**
     * Click the first element whose `data-on:click*` attribute contains `sub`
     * -- this is how nav tabs / sub-nav links are wired (see nav.html.twig,
     * settings.html.twig), so it exercises the exact path a real user click
     * would, the same technique book/_capture.mjs uses.
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

    /**
     * Click the tab's own "Process data" button and wait for its spinner to
     * appear then clear -- Flows/Statistics/Sankey don't auto-run their
     * nfdump query on filter/date change (see flow-filters.html.twig et al),
     * so a query has to be explicitly triggered and awaited before the
     * results table/chart exists to assert on.
     */
    async processData({ timeout = 20000 } = {}) {
        await this.clickByText('Process data', 'button');
        const isVisible = `(function(){var s=document.querySelector('.spinner-grow');return !!s&&s.offsetParent!==null;})()`;
        await this.waitFor(isVisible, { timeout: 5000, label: 'query to start' }).catch(() => {});
        await this.waitFor(`!(${isVisible})`, { timeout, label: 'query to finish' });
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
 * leak a Chrome process.
 */
export async function withPage(fn, { width = 1400, height = 1100, port } = {}) {
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
        return await fn(page);
    } finally {
        if (page) {
            try {
                await page.close();
            } catch {}
        } else {
            chrome.kill();
        }
    }
}
