// The book's screenshots (SPEC 5.6), light and dark side by side, taken through the UI. Needs ImageMagick.
// CHROME=/usr/bin/chromium BASE=http://localhost:8080 [OUT=/tmp/x] [ONLY=name,name] [FROM=.. TO=..] [ALERT_RULE=name] [ALLOW_NOTES=1] node book/_capture.mjs
// Read-only: it runs queries and stages state with CSS, and writes no rule, setting or saved filter.
// An image that would show a fault of the instance is held: the committed file stays and the run exits 1.
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, renameSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { BASE, withPage } from '../tests/e2e/lib/cdp.mjs';
import { CLEAR_TOASTS } from '../tests/e2e/lib/toasts.mjs';

const OUT = process.env.OUT || join(import.meta.dirname, 'src/images');
// Committed image over new image, for every image a run changes.
const DIFFS = process.env.DIFFS || join(import.meta.dirname, '.capture-diffs');
// A new capture replaces the committed image when more than DIFF_PIXELS pixels differ by more than FUZZ,
// outside the VOLATILE boxes: text that changes on every run (clock times, durations) is not a change.
const FUZZ = process.env.FUZZ || '2%';
const DIFF_PIXELS = Number(process.env.DIFF_PIXELS ?? '0');
// The filter of the Flows, drawer and filtered-graph images.
const FILTER_EXPR = process.env.FILTER_EXPR || 'proto tcp';
const QUERY_TIMEOUT = Number(process.env.QUERY_TIMEOUT ?? '300000');
// Image names: only the steps that make them run.
const ONLY = process.env.ONLY ? new Set(process.env.ONLY.split(',').map((s) => s.trim())) : null;
const DESKTOP = { width: 1440, height: 1000, scale: 1.5 };
const PHONE = { width: 390, height: 844, mobile: true };
const DAY = 86400;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const js = JSON.stringify;
const wanted = (name) => !ONLY || ONLY.has(name);

/** Outcome per image for the summary: new, changed or unchanged. */
const SHOTS = [];
/** Steps that threw; any of them fails the run. */
const FAILED = [];
/** Images left as committed because the instance could not show what they are about; they fail the run too. */
const HELD = [];
const ALLOW_NOTES = process.env.ALLOW_NOTES === '1';
/** The window every page is shot in: {preset} or {from, to}. */
let WINDOW = null;
/** The window of the images about the precomputed top-N, once overviewWithTopn() has chosen it. */
let OVERVIEW = null;

mkdirSync(OUT, { recursive: true });
rmSync(DIFFS, { recursive: true, force: true });
mkdirSync(DIFFS, { recursive: true });

// Text that differs on every run whatever the image is about: clock times, run durations and progress, the
// render time in the alert template preview, and the footer's connection count (other tabs on the instance).
const VOLATILE = [
    `document.querySelectorAll('time')`,
    `document.querySelectorAll('.status-footer .badge, .status-footer .badge ~ *')`,
    `document.querySelectorAll('.notice[data-level="success"], .query-progress-line, #statsCommand ~ small, .template-preview')`,
    `(function(){ return [...document.querySelectorAll('p.help')].filter(function(p){ return /\\([\\d.]+ s\\)$/.test(p.textContent.trim()); }); })()`,
];

// Notices no image is about: an error, a stale result, the shell's reconnect and error banners.
const NOTICES = '.notice[data-level="error"], .notice[data-kind="stale"], .shell-notices .notice';

// The Health checks table has no element per group, only a th[colspan] heading row per group.
const PAGE_HELPERS = `window.__capture = true;
window.__groupRect = function(label){
    var rows = [...document.querySelectorAll('#healthChecks tr')];
    var start = rows.findIndex(function(r){ var c = r.querySelector('[colspan]'); return c && c.textContent.trim() === label; });
    if (start === -1) return null;
    var end = rows.findIndex(function(r, i){ return i > start && r.querySelector('[colspan]'); });
    var first = rows[start].getBoundingClientRect(), last = rows[(end === -1 ? rows.length : end) - 1].getBoundingClientRect();
    return {x: first.x, y: first.y, width: first.width, height: last.y + last.height - first.y};
};`;

// ── Browser and session ─────────────────────────────────────────────────────

/** Same pixels on every machine: fixed timezone, locale and scale, no scrollbars, no motion. */
async function setup(page, { width, height, scale = 2, mobile = false }) {
    await page.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: scale, mobile });
    await page.send('Emulation.setScrollbarsHidden', { hidden: true });
    await page.send('Emulation.setTimezoneOverride', { timezoneId: process.env.CAPTURE_TZ || 'UTC' });
    await page.send('Emulation.setLocaleOverride', { locale: 'en-US' });
    await page.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    page.autoAcceptDialogs();
    await page.navigate(`${BASE}/#/overview`);
    await boot(page);
}

/** Once the page is loaded: helpers, light theme and the window. */
async function boot(page) {
    await page.waitForBoot({ timeout: 30000 });
    // After a restart the first sync waits for the startup import; until it comes, every Run is disabled.
    for (const start = Date.now(); typeof (await page.signalValue('query_running')) !== 'boolean'; ) {
        if (Date.now() - start > QUERY_TIMEOUT) throw new Error('no first sync from the app');
        await sleep(500);
    }
    await page.evaluate(PAGE_HELPERS);
    await setTheme(page, 'light');
    if (WINDOW) await applyWindow(page, WINDOW);
}

/** False once the page has reloaded, as it does when the dev server restarts. */
function alive(page) {
    return page.evaluate('window.__capture === true').catch(() => false);
}

/** Health warnings that are only about how this instance is configured, which stackNotes() found; they hold nothing. */
let HEALTH_TOLERATED = null;
// A settings.php next to the environment is how the dev stack runs (PHPStan loads it), not a fault of the data.
const TOLERATED_CHECKS = ['Config source'];

/** Faults of the instance that every full-page image shows: a footer status or a Health state other than success. */
function faults(page) {
    return page.evaluate(`(function(){
        var out = [];
        document.querySelectorAll('.status-footer .status-item').forEach(function(s){
            if (s.querySelector('.status-dot:not([data-level="success"])')) out.push(s.textContent.trim().replace(/\\s+/g, ' '));
        });
        document.querySelectorAll('.sidebar-nav .nav-dot:not([data-level="success"])').forEach(function(d){
            var text = d.textContent.trim().replace(/\\s+/g, ' ');
            if (!(d.dataset.level === 'warning' && text === ${js(HEALTH_TOLERATED)})) out.push(text);
        });
        return out;
    })()`);
}

/** Visible NOTICES inside the boxes of `clip` (anywhere for a full page or the viewport), by their text. */
function notices(page, clip) {
    const targets = !clip || clip === 'viewport' ? [] : exprs(clip.stack ?? clip);
    return page.evaluate(`(function(){
        var boxes = [${targets.join(', ')}].map(function(e){ return e && typeof e.getBoundingClientRect === 'function' ? e.getBoundingClientRect() : e; });
        return [...document.querySelectorAll(${js(NOTICES)})].filter(function(n){
            if (!n.getClientRects().length || n.closest('[hidden]')) return false;
            var r = n.getBoundingClientRect();
            return !boxes.length || boxes.some(function(b){ return b && r.left < b.x + b.width && r.right > b.x && r.top < b.y + b.height && r.bottom > b.y; });
        }).map(function(n){ return n.textContent.trim().replace(/\\s+/g, ' ').slice(0, 120); });
    })()`);
}

/** Before the tour: the faults that will hold the full-page images, and the firing rules they show. */
async function stackNotes(page) {
    const dot = await page.evaluate(
        `(function(){ var d = document.querySelector('.sidebar-nav .nav-dot[data-level="warning"]'); return d ? d.textContent.trim().replace(/\\s+/g, ' ') : null; })()`
    );
    if (dot) {
        await go(page, 'health');
        await page.waitFor(`document.querySelectorAll('#healthChecks tbody').length > 1`, { timeout: 30000, label: 'health checks' });
        const failing = await page.evaluate(
            `[...document.querySelectorAll('#healthChecks tr[data-level]')].map(function(r){ return r.dataset.level + ': ' + r.cells[0].textContent.trim().replace(/\\s+/g, ' '); })`
        );
        if (failing.length && failing.every((f) => f.startsWith('warning: ') && TOLERATED_CHECKS.some((t) => f.endsWith(` ${t}`)))) {
            HEALTH_TOLERATED = dot;
            console.warn(`note: Health shows ${dot} (${failing.join(', ')}); the images show it`);
        }
        await go(page, 'overview');
    }
    const found = await faults(page);
    const firing = await page.evaluate(
        `[...document.querySelectorAll('.sidebar-nav .nav-badge')].map(function(b){ return b.textContent.trim(); }).filter(Boolean)`
    );
    if (firing.length) console.warn(`note: the sidebar shows ${firing.join(', ')} for the Alerts page`);
    if (found.length)
        console.warn(
            `${ALLOW_NOTES ? 'note' : 'HOLD'}: the instance shows ${found.join('; ')}; ${ALLOW_NOTES ? 'the full-page images show it (ALLOW_NOTES=1)' : 'full-page images keep the committed file; fix the instance or set ALLOW_NOTES=1'}`
        );
}

/** Open a page through the hash, as a link or a bookmark does. */
async function go(page, id) {
    await page.evaluate(`location.hash = ${js(`#/${id}`)}`);
    await page.waitForPage(id, { timeout: 15000 });
    await page.evaluate('window.scrollTo(0, 0); document.activeElement?.blur()');
    await sleep(600);
}

async function click(page, selector) {
    const ok = await page.evaluate(
        `(function(){ var e = document.querySelector(${js(selector)}); if (!e) return false; e.click(); return true; })()`
    );
    if (!ok) throw new Error(`nothing to click at ${selector}`);
}

/** A theme choice from the sidebar menu's own API; the charts re-theme on its event. */
async function setTheme(page, theme) {
    await page.evaluate(`window.__nfsenTheme.choose(${js(theme)})`);
    await page.waitFor(`document.documentElement.dataset.theme === ${js(theme)}`, { label: `html[data-theme=${theme}]` });
    await sleep(500);
}

// ── Window ──────────────────────────────────────────────────────────────────

/** Epoch seconds of the newest point with traffic in the traffic graph, or null. */
function lastTraffic(page) {
    return page.evaluate(`(function(){
        var d = JSON.parse(document.getElementById('trafficGraph')?.dataset.chartData || 'null');
        if (!d || !d.data) return null;
        var ts = Object.keys(d.data).map(Number).filter(function(t){ return d.data[t].some(function(v){ return Number(v) > 0; }); });
        return ts.length ? Math.max.apply(null, ts) : null;
    })()`);
}

/** Wait until the traffic graph has drawn the window at both ends, so what it holds is about that window. */
function graphShows(page, from, to) {
    const slack = Math.max(3600, (to - from) / 50);
    return page.waitFor(
        `(function(){
            var d = JSON.parse(document.getElementById('trafficGraph')?.dataset.chartData || 'null');
            var ts = d && d.data ? Object.keys(d.data).map(Number) : [];
            return ts.length > 0 && Math.abs(Math.min.apply(null, ts) - ${from}) <= ${slack}
                && Math.abs(Math.max.apply(null, ts) - ${to}) <= ${slack};
        })()`,
        { timeout: 20000, label: 'the traffic graph to show the window' }
    );
}

/** Set an absolute window through the range display's From and To fields; `clamp` accepts a later start the app moved it to. */
async function setAbsolute(page, from, to, { clamp = false } = {}) {
    // On phones the range display sits behind the More controls toggle.
    await page.evaluate(`(function(){
        var t = document.querySelector('#rangeDisplay .menu-toggle');
        if (!t.getClientRects().length) document.getElementById('controlsMore').click();
    })()`);
    await page.waitFor(`!!document.querySelector('#rangeDisplay .menu-toggle')?.getClientRects().length`, { label: 'range display' });
    await page.evaluate(
        `(function(){ var t = document.querySelector('#rangeDisplay .menu-toggle'); if (t.getAttribute('aria-expanded') !== 'true') t.click(); })()`
    );
    await page.waitFor(`!!document.getElementById('rangePicker')?.getClientRects().length`, { label: 'range entry' });
    // The window as the picker's Apply sends it: exact instants, so no wall time to convert.
    await page.evaluate(`document.getElementById('rangePicker').dispatchEvent(new CustomEvent('sb-change', { bubbles: true, composed: true,
        detail: { name: '', value: { start: new Date(${from} * 1000).toISOString(), end: new Date(${to} * 1000).toISOString() } } })), true`);
    for (let i = 0; ; i++) {
        const { datestart, dateend } = await page.signalValues(['datestart', 'dateend']);
        if ((Math.abs(datestart - from) <= 300 || (clamp && datestart > from)) && Math.abs(dateend - to) <= 300) break;
        if (i === 75) throw new Error(`window ${from}..${to} not applied (${datestart}..${dateend})`);
        await sleep(200);
    }
    await page.evaluate(`(function(){
        document.getElementById('rangeDisplay')?.hide?.();
        if (document.getElementById('controlsMore').getAttribute('aria-expanded') === 'true') document.getElementById('controlsMore').click();
    })()`);
    await sleep(1500);
}

/** FROM and TO (epoch seconds or a date string), or the last day of stored traffic: found on a year, then placed on a few days. */
async function pickWindow(page) {
    if (process.env.FROM && process.env.TO) {
        const at = (v) => (/^\d+$/.test(v) ? Number(v) : Math.floor(Date.parse(v) / 1000));
        const win = { from: at(process.env.FROM), to: at(process.env.TO) };
        if (!(win.from < win.to)) throw new Error(`FROM and TO do not make a window: ${process.env.FROM}, ${process.env.TO}`);
        return win;
    }
    const now = Math.floor(Date.now() / 1000);
    await page.setRangePreset('1y');
    await graphShows(page, now - 365 * DAY, now);
    const day = await lastTraffic(page);
    if (day === null || now - day < 3600) return { preset: '24h' };
    // A year is drawn in days, each stamped at its end: the traffic lies in the day before `day`.
    const from = day - 2 * DAY;
    const to = Math.min(now, day + DAY);
    // The app starts the window no earlier than its oldest data.
    await setAbsolute(page, from, to, { clamp: true });
    const { datestart } = await page.signalValues(['datestart']);
    await graphShows(page, datestart, to);
    const last = await lastTraffic(page);
    if (last === null)
        throw new Error(
            `no traffic in the stored series between ${new Date(from * 1000).toISOString()} and ${new Date(to * 1000).toISOString()}`
        );
    // Past the last sample, so the capture files of the last intervals are inside too.
    const end = Math.min(now, Math.ceil(last / 300) * 300 + 1200);
    return { from: end - DAY, to: end };
}

async function applyWindow(page, win) {
    if (win.preset) await page.setRangePreset(win.preset);
    else await setAbsolute(page, win.from, win.to);
    await page.evaluate('document.activeElement?.blur()');
}

// ── Capture ─────────────────────────────────────────────────────────────────

/** Selectors or JS expressions (a leading call or parenthesis) as JS expressions. */
const exprs = (targets) =>
    (Array.isArray(targets) ? targets : [targets]).map((t) => (/^(\(|[\w$.]+\()/.test(t) ? t : `document.querySelector(${js(t)})`));

// clip: nothing (full page), 'viewport', or targets (selectors or JS expressions) whose boxes are united.
// A box on screen stays in the viewport: capturing beyond it resizes the page and closes open menus.
async function areaFor(page, clip, margin) {
    if (clip === 'viewport') {
        const { sx, sy, w, h } = await page.evaluate('({sx: scrollX, sy: scrollY, w: innerWidth, h: innerHeight})');
        return { viewport: true, clip: { x: sx, y: sy, width: w, height: h, scale: 1 } };
    }
    if (!clip) {
        const { cssContentSize } = await page.send('Page.getLayoutMetrics');
        return { beyond: true, clip: { x: 0, y: 0, width: cssContentSize.width, height: cssContentSize.height, scale: 1 } };
    }
    const targets = exprs(clip);
    const box = await page.evaluate(`(function(){
        var boxes = [${targets.join(', ')}].map(function(e){
            if (!e) return null;
            var r = typeof e.getBoundingClientRect === 'function' ? e.getBoundingClientRect() : e;
            return r.width > 0 && r.height > 0 ? r : null;
        });
        if (boxes.some(function(b){ return b === null; })) return null;
        var x1 = Math.min.apply(null, boxes.map(function(b){ return b.x; }));
        var y1 = Math.min.apply(null, boxes.map(function(b){ return b.y; }));
        var x2 = Math.max.apply(null, boxes.map(function(b){ return b.x + b.width; }));
        var y2 = Math.max.apply(null, boxes.map(function(b){ return b.y + b.height; }));
        var main = document.querySelector('.app-main')?.getBoundingClientRect();
        var root = document.documentElement;
        var onScreen = y1 >= 0 && y2 <= innerHeight;
        return {x1: x1, y1: y1, x2: x2, y2: y2, onScreen: onScreen,
                left: main && x1 >= main.left ? main.left : 0,
                right: onScreen ? innerWidth : root.scrollWidth, bottom: onScreen ? innerHeight : root.scrollHeight,
                sx: scrollX, sy: scrollY};
    })()`);
    if (!box) throw new Error(`nothing visible to capture at ${targets.join(' + ')}`);
    const [top, right, bottom, left] = Array.isArray(margin) ? margin : [margin, margin, margin, margin];
    // Whole CSS pixels, so a box a fraction wider on the next run gives an image of the same size.
    const x = Math.floor(Math.max(box.left, box.x1 - left));
    const y = Math.floor(Math.max(0, box.y1 - top));
    const width = Math.ceil(Math.min(box.right, box.x2 + right)) - x;
    const height = Math.ceil(Math.min(box.bottom, box.y2 + bottom)) - y;
    return { beyond: !box.onScreen, clip: { x: x + box.sx, y: y + box.sy, width, height, scale: 1 } };
}

/** The VOLATILE boxes inside `clip` (page coordinates), in image pixels from its corner. */
async function masksFor(page, clip, targets) {
    return page.evaluate(`(function(){
        var c = ${js(clip)}, k = devicePixelRatio, out = [];
        [${targets.join(', ')}].forEach(function(e){
            var list = !e ? [] : e instanceof Element ? [e] : [...e];
            list.forEach(function(el){
                var r = el.getBoundingClientRect();
                if (!r.width || !r.height || !el.getClientRects().length) return;
                var x1 = Math.max(r.left + scrollX, c.x) - c.x, y1 = Math.max(r.top + scrollY, c.y) - c.y;
                var x2 = Math.min(r.right + scrollX, c.x + c.width) - c.x, y2 = Math.min(r.bottom + scrollY, c.y + c.height) - c.y;
                if (x2 > x1 && y2 > y1) out.push([Math.floor(x1 * k) - 4, Math.floor(y1 * k) - 4, Math.ceil(x2 * k) + 4, Math.ceil(y2 * k) + 4]);
            });
        });
        return out;
    })()`);
}

/** One image of `clip`, or of each clip in `clip.stack` placed under each other; returns its VOLATILE boxes. */
async function capture(page, clip, file, margin, volatile) {
    // A toast is not part of any page; one left over from a run would cover what the image shows.
    await page.evaluate(CLEAR_TOASTS);
    const targets = exprs([...VOLATILE, ...volatile]);
    if (clip?.stack) {
        const parts = [];
        const masks = [];
        let top = 0;
        try {
            const [t, r, b, l] = Array.isArray(margin) ? margin : [margin, margin, margin, margin];
            // Parts are clipped without margin between them, which would take in a neighbouring card's border.
            const gap = await page.evaluate('Math.round(16 * devicePixelRatio)');
            for (const [i, part] of clip.stack.entries()) {
                const partFile = `${file}.${i}.png`;
                const [first, last] = [i === 0, i === clip.stack.length - 1];
                const partMasks = await capture(page, part, partFile, [first ? t : 0, r, last ? b : 0, l], volatile);
                if (!first) top += gap;
                masks.push(...partMasks.map(([x1, y1, x2, y2]) => [x1, y1 + top, x2, y2 + top]));
                top += imgDims(partFile)[1];
                parts.push(partFile);
            }
            // The page colour as sRGB through a canvas, since ImageMagick cannot read oklch().
            const background = await page.evaluate(`(function(){
                var c = [document.querySelector('.app-main'), document.body, document.documentElement]
                    .map(function(e){ return e && getComputedStyle(e).backgroundColor; })
                    .find(function(v){ return v && v !== 'rgba(0, 0, 0, 0)' && v !== 'transparent'; }) || 'white';
                var x = document.createElement('canvas').getContext('2d');
                x.fillStyle = c;
                x.fillRect(0, 0, 1, 1);
                var d = x.getImageData(0, 0, 1, 1).data;
                return 'rgb(' + d[0] + ',' + d[1] + ',' + d[2] + ')';
            })()`);
            const spaced = parts.flatMap((p, i) => (i === 0 ? [p] : ['(', p, '-gravity', 'North', '-splice', `0x${gap}`, ')']));
            execFileSync('convert', ['-background', background, ...spaced, '-gravity', 'NorthWest', '-append', '+repage', file]);
        } finally {
            for (const p of parts) rmSync(p, { force: true });
        }
        return masks;
    }
    const area = await areaFor(page, clip, margin);
    const params = area.viewport ? { format: 'png' } : { format: 'png', captureBeyondViewport: area.beyond, clip: area.clip };
    const { data } = await page.send('Page.captureScreenshot', params);
    writeFileSync(file, Buffer.from(data, 'base64'));
    return masksFor(page, area.clip, targets);
}

/** Capture-only CSS in <head>, which the server's morphs of <body> leave alone; null removes it. */
async function stage(page, css) {
    await page.evaluate(`(function(){
        var s = document.getElementById('captureStage'), css = ${js(css)};
        if (css === null) { if (s) s.remove(); return; }
        if (!s) { s = document.createElement('style'); s.id = 'captureStage'; document.head.appendChild(s); }
        s.textContent = css;
    })()`);
}

// `prepare` restores state a theme switch loses (an open menu); a false `verify` retakes the theme.
// `margin` is one number or [top, right, bottom, left]; 12 stays inside the 16 px gap between cards.
// `hold` names why the page cannot show what the image is about: the committed image stays.
// `instance` marks a clipped image about the instance's state, held on its faults as a full page is.
async function shot(page, name, clip, { prepare, verify, margin = 12, volatile = [], hold = null, instance = false } = {}) {
    if (!wanted(name)) return;
    if (!hold && (!clip || clip === 'viewport' || instance) && !ALLOW_NOTES) {
        const found = await faults(page);
        if (found.length) hold = `the instance shows ${found.join('; ')}`;
    }
    if (!hold) {
        const shown = await notices(page, clip);
        if (shown.length) hold = `the page shows ${shown.join('; ')}`;
    }
    if (hold) {
        HELD.push({ name, why: hold });
        console.warn(`  HELD ${name}: ${hold}`);
        return;
    }
    const files = [];
    const masks = [];
    try {
        let left = 0;
        for (const theme of ['light', 'dark']) {
            await setTheme(page, theme);
            const file = join(OUT, `.${name}.${theme}.png`);
            let themeMasks;
            for (let attempt = 1; ; attempt++) {
                if (prepare) await prepare();
                const shown = await notices(page, clip);
                if (shown.length) throw new Error(`${name}: the page shows ${shown.join('; ')}`);
                themeMasks = await capture(page, clip, file, margin, volatile);
                if (!verify || (await verify())) break;
                if (attempt === 3) throw new Error(`${name}: the page kept undoing the staged state`);
            }
            masks.push(...themeMasks.map(([x1, y1, x2, y2]) => [x1 + left, y1, x2 + left, y2]));
            left += imgDims(file)[0];
            files.push(file);
        }
        const stitched = join(OUT, `.${name}.new.png`);
        // A 256 colour palette cuts flat UI screenshots by about two thirds with no visible loss.
        execFileSync('convert', [...files, '+append', '+dither', '-colors', '256', '-strip', stitched]);
        const [w, h] = imgDims(stitched);
        console.log(`  captured ${name} ${w}x${h}`);
        commitShot(name, stitched, masks);
    } finally {
        for (const f of files) rmSync(f, { force: true });
        await setTheme(page, 'light');
    }
}

function imgDims(file) {
    return execFileSync('identify', ['-format', '%w %h', file], { encoding: 'utf8' }).trim().split(/\s+/).map(Number);
}

/** Committed image over new image (over the pixel diff), labelled where montage has a font. */
function buildVisibleDiff(name, oldFile, newFile, rawDiff) {
    const panes = rawDiff ? [oldFile, newFile, rawDiff] : [oldFile, newFile];
    const labelled = ['-label', 'committed', oldFile, '-label', 'new', newFile, ...(rawDiff ? ['-label', 'diff', rawDiff] : [])];
    const out = join(DIFFS, `${name}.png`);
    try {
        execFileSync('montage', [...labelled, '-tile', '1x', '-geometry', '+0+8', '-background', 'white', '-fill', 'black', out], {
            stdio: 'pipe',
        });
    } catch {
        execFileSync('convert', [...panes, '-append', out], { stdio: 'pipe' });
    }
}

/** A copy of `file` with the `masks` boxes painted over, as both images of a compare get the same boxes. */
function masked(file, masks, out) {
    const draw = masks.map(([x1, y1, x2, y2]) => `rectangle ${x1},${y1} ${x2},${y2}`).join(' ');
    execFileSync('convert', [file, ...(draw ? ['-fill', '#ff00ff', '-draw', draw] : []), `PNG24:${out}`]);
    return out;
}

/** Keep the committed image when only VOLATILE text differs; otherwise replace it and leave a diff in DIFFS. */
function commitShot(name, newFile, masks) {
    const finalFile = join(OUT, `${name}.png`);
    if (!existsSync(finalFile)) {
        renameSync(newFile, finalFile);
        SHOTS.push({ name, state: 'new' });
        return;
    }
    const [ow, oh] = imgDims(finalFile);
    const [nw, nh] = imgDims(newFile);
    // compare cannot diff two sizes; a new size is a change anyway.
    if (ow !== nw || oh !== nh) {
        buildVisibleDiff(name, finalFile, newFile, null);
        renameSync(newFile, finalFile);
        SHOTS.push({ name, state: 'changed', note: `size ${ow}x${oh} to ${nw}x${nh}` });
        return;
    }
    const rawDiff = join(DIFFS, `.${name}.rawdiff.png`);
    const scratch = [join(DIFFS, `.${name}.old.png`), join(DIFFS, `.${name}.new.png`)];
    let cmp;
    try {
        cmp = spawnSync(
            'compare',
            ['-metric', 'AE', '-fuzz', FUZZ, masked(finalFile, masks, scratch[0]), masked(newFile, masks, scratch[1]), rawDiff],
            { encoding: 'utf8' }
        );
    } finally {
        for (const f of scratch) rmSync(f, { force: true });
    }
    if (cmp.error) throw cmp.error;
    // AE prints the count of differing pixels to stderr, sometimes as 5.29e+06; exit status 2 is an error.
    const pixels = parseFloat((cmp.stderr || '').trim().split(/\s+/)[0]);
    if (cmp.status === 2 || Number.isNaN(pixels)) {
        rmSync(rawDiff, { force: true });
        renameSync(newFile, finalFile);
        SHOTS.push({ name, state: 'changed', note: `compare failed, kept the new image: ${(cmp.stderr || '').trim().split('\n')[0]}` });
        return;
    }
    const note = `${Math.round(pixels)} px differ, ${masks.length} volatile boxes left out`;
    if (pixels <= DIFF_PIXELS) {
        // Below a raised DIFF_PIXELS a few pixels may still be a changed figure: the summary lists them.
        if (pixels > 0) buildVisibleDiff(name, finalFile, newFile, rawDiff);
        unlinkSync(newFile);
        rmSync(rawDiff, { force: true });
        SHOTS.push({ name, state: 'unchanged', note, pixels });
        return;
    }
    buildVisibleDiff(name, finalFile, newFile, rawDiff);
    rmSync(rawDiff, { force: true });
    renameSync(newFile, finalFile);
    SHOTS.push({ name, state: 'changed', note });
}

// One part of the tour, from a page with no dialog, menu or hover left open. A part that a dev server
// restart broke runs again (restarts come in bursts while files are edited); other failures fail the run.
async function step(page, names, fn, { optional = false } = {}) {
    const retry = (attempt) => attempt < 4 && !optional;
    if (!names.some(wanted)) return;
    const label = names.join(', ');
    for (let attempt = 1; ; attempt++) {
        try {
            // A fresh load: the page a restart reloads keeps signals from the context that expired.
            if (!(await alive(page))) {
                await page.navigate(`${BASE}/#/overview`);
                await boot(page);
            }
            await page.evaluate(`(function(){
                document.querySelectorAll('sb-modal, sb-drawer').forEach(function(m){ if (m.isOpen) m.close(); });
                document.querySelectorAll('sb-popover').forEach(function(p){ if (p.open) p.hide(); });
            })()`);
            await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1, y: 1 });
            await fn();
            return;
        } catch (e) {
            // An expired context reloads the page at the next SSE reconnect, within 15 s.
            let reloaded = !(await alive(page));
            for (let i = 0; i < 20 && !reloaded && retry(attempt); i++) {
                await sleep(1000);
                reloaded = !(await alive(page));
            }
            if (reloaded && retry(attempt)) {
                console.warn(`  the app reloaded during ${label}; again`);
                continue;
            }
            console.warn(`  ${optional ? 'skipped' : 'FAILED'} ${label}: ${e.message.split('\n')[0]}`);
            if (!optional) FAILED.push(label);
            return;
        }
    }
}

// ── Helpers for the pages ───────────────────────────────────────────────────

async function run(page, target) {
    // One query runs at a time per tab; the one before may still be finishing.
    await page.waitFor(`!!document.querySelector('button[data-run="${target}"]:not(:disabled)')`, {
        timeout: 60000,
        label: `run button for ${target}`,
    });
    await page.runQuery(target, { timeout: QUERY_TIMEOUT });
    await sleep(800);
}

/** Type into a filter field and wait for nfdump's verdict. */
async function setFilter(page, textarea, target, expr) {
    await page.setInputValue(`#${textarea}`, expr);
    await page.waitFor(`document.querySelector('[data-filter-status="${target}"]')?.dataset.state === 'valid'`, {
        timeout: 15000,
        label: `"${expr}" to validate on ${target}`,
    });
}

/** Flows with FILTER_EXPR, 20 rows and a result, unless the tab has one. */
async function flowsResult(page) {
    await go(page, 'flows');
    if (await page.evaluate(`!!document.querySelector('sb-virtual-scroll[id^="flowRows-"] [role="row"]:not([slot])')`)) return;
    await page.setSelectValue('#flowsLimit', '20');
    await setFilter(page, 'filterNfdumpTextarea', 'flows', FILTER_EXPR);
    await run(page, 'flows');
    await page.waitFor(`!!document.querySelector('sb-virtual-scroll[id^="flowRows-"] [role="row"]:not([slot])')`, { timeout: 15000, label: 'flow rows' });
}

const TOPN_STATE = `document.querySelector('#ovTopnPanel[data-state]:not([data-state="loading"])')?.dataset.state`;

async function topnState(page) {
    await page.waitFor(TOPN_STATE, { timeout: 30000, label: 'top-N' });
    await sleep(800);
    return page.evaluate(TOPN_STATE);
}

/** The top-N retention in days, from the Top-N retention row on Settings, Storage. */
async function retentionDays(page) {
    await go(page, 'settings');
    await click(page, '#settingsTab-storage');
    await page.waitFor(`!document.getElementById('settingsPanel-storage').hidden`, { label: 'settings storage' });
    const days = await page.evaluate(`(function(){
        var dt = [...document.querySelectorAll('#settingsPanel-storage dt')].find(function(d){ return d.textContent.trim() === 'Top-N retention'; });
        var m = dt && dt.nextElementSibling ? dt.nextElementSibling.textContent.trim().match(/^(\\d+) days?/) : null;
        return m ? Number(m[1]) : null;
    })()`);
    await click(page, '#settingsTab-general');
    if (days === null) throw new Error('no Top-N retention row on Settings, Storage');
    return days;
}

/** A day inside the top-N retention whose stored series holds at least 6 h of WINDOW's traffic, or null. */
async function retentionWindow(page) {
    if (WINDOW.preset) return null;
    const days = await retentionDays(page);
    if (days === 0) return null;
    const now = Math.floor(Date.now() / 1000);
    // Past the boundary by a margin, so the pruner cannot overtake the images while they are taken.
    const from = Math.ceil((now - days * DAY) / 300) * 300 + 1800;
    await go(page, 'overview');
    await applyWindow(page, WINDOW);
    await graphShows(page, WINDOW.from, WINDOW.to);
    // Points with traffic after `from`, each worth the series' step.
    const covered = await page.evaluate(`(function(){
        var d = JSON.parse(document.getElementById('trafficGraph')?.dataset.chartData || 'null');
        var ts = d && d.data ? Object.keys(d.data).map(Number).sort(function(a, b){ return a - b; }) : [];
        if (ts.length < 2) return 0;
        var step = ts[1] - ts[0];
        return ts.filter(function(t){ return t >= ${from} && d.data[t].some(function(v){ return Number(v) > 0; }); }).length * step;
    })()`);
    if (covered < 6 * 3600) return null;
    return from + DAY > now ? { preset: '24h' } : { from, to: from + DAY };
}

// Overview with KPI figures and a filled top-N. A WINDOW older than the top-N retention moves to a day
// inside it; with no such day the page's own exact nfdump top-N fills the table and the result says
// why the KPI images are held.
async function overviewWithTopn(page) {
    await go(page, 'overview');
    let state = await topnState(page);
    if (state === 'outside') {
        OVERVIEW ??= (await retentionWindow(page)) ?? WINDOW;
        await go(page, 'overview');
        if (OVERVIEW !== WINDOW) {
            await applyWindow(page, OVERVIEW);
            await page.waitFor(`!['outside', 'loading', undefined].includes(${TOPN_STATE})`, { timeout: 20000 }).catch(() => {});
            state = await topnState(page);
            if (state !== 'table') {
                OVERVIEW = WINDOW;
                await applyWindow(page, WINDOW);
                state = await topnState(page);
            }
        }
    }
    const exact = state === 'outside' && (await page.evaluate(`!!document.querySelector('button[data-run="overview-topn"]')`));
    if (exact) {
        if (!(await page.evaluate(`!!document.querySelector('#ovTopnTable tbody tr')`))) await run(page, 'overview-topn');
    } else if (state !== 'table') {
        throw new Error(`the precomputed top-N is "${state}" for this window: set FROM and TO to a day with traffic`);
    }
    await page.waitFor(`!!document.querySelector('#ovTopnTable tbody tr')`, { timeout: 15000, label: 'top-N rows' });
    const empty = await page.evaluate(
        `[...new Set([...document.querySelectorAll('#overviewKpis .kpi-value[data-kind="state"]')].map(function(v){ return v.textContent.trim(); }))]`
    );
    if (!empty.length) return null;
    const why = `no stored top-N for a window with traffic, so the KPI cards read "${empty.join('; ')}" (make capture data inside the top-N retention)`;
    if (!exact) throw new Error(why);
    return why;
}

/** Back to WINDOW for the pages after an Overview image, if the Overview moved away from it. */
async function leaveOverview(page) {
    if (OVERVIEW && OVERVIEW !== WINDOW && (await alive(page))) await applyWindow(page, WINDOW);
}

// `mouse` opens it with a pointer click, as a mouse user does, so the item it focuses shows no focus ring.
async function openMenu(page, toggle, list, { mouse = false } = {}) {
    if (mouse) {
        const at = await page.evaluate(`(function(){
            var t = document.querySelector(${js(toggle)});
            if (t.getAttribute('aria-expanded') === 'true') return null;
            var r = t.getBoundingClientRect();
            return {x: r.x + r.width / 2, y: r.y + r.height / 2};
        })()`);
        if (at) {
            for (const type of ['mousePressed', 'mouseReleased'])
                await page.send('Input.dispatchMouseEvent', { type, x: at.x, y: at.y, button: 'left', clickCount: 1 });
        }
    } else {
        await page.evaluate(
            `(function(){ var t = document.querySelector(${js(toggle)}); if (t.getAttribute('aria-expanded') !== 'true') t.click(); })()`
        );
    }
    await page.waitFor(`!!document.querySelector(${js(list)})?.getClientRects().length`, { label: `${list} open` });
    // A popover moves focus into its panel on open; the images show no focus ring.
    await page.evaluate(`(function(){ var a = document.activeElement; if (a && a.closest('sb-popover')) a.blur(); })()`);
    await sleep(200);
}

/** The panel of an open popover, from its host's selector, as a capture target. */
const panelOf = (host) =>
    `(function(){ var h = [...document.querySelectorAll(${js(host)})].find(function(p){ return p.open; }); return h ? h.shadowRoot.querySelector('[part~="panel"]') : null; })()`;

/** Point at the traffic graph so its tooltip and the cursor legend show the values there. */
async function hoverGraph(page, fraction = 0.55) {
    const r = await page.evaluate(
        `(function(){ var r = document.querySelector('#trafficGraph .chart-canvas').getBoundingClientRect(); return {x: r.x, y: r.y, w: r.width, h: r.height}; })()`
    );
    // Near the bottom: a tall tooltip opens upwards, and from here it stays on the page.
    const y = r.y + r.h * 0.92;
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: r.x + r.w * fraction - 20, y });
    await sleep(100);
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: r.x + r.w * fraction, y });
    await sleep(400);
}

/** ECharts' tooltip box, or the graph section when it is not showing. */
const TOOLTIP = `([...document.querySelectorAll('#trafficGraph .chart-canvas div')].find(function(d){
    return getComputedStyle(d).zIndex === '9999999' && getComputedStyle(d).display !== 'none' && d.getClientRects().length;
}) || document.getElementById('trafficGraphSection'))`;

// Forty synthetic port series for the images about many series, as the dev data has three ports.
// The display must be Ports already, so the header matches; the next redraw replaces them.
async function syntheticSeries(page) {
    const { datestart, dateend } = await page.signalValues(['datestart', 'dateend']);
    await page.evaluate(`(function(){
        var el = document.getElementById('trafficGraph');
        var ports = [80, 443, 22, 53, 25, 110, 143, 993, 995, 587, 3306, 5432, 6379, 8080, 8443, 3389, 5060, 1701, 6443, 51820,
                     1521, 853, 8883, 636, 389, 123, 161, 514, 1194, 1812, 2049, 27017, 9200, 5672, 11211, 3000, 9090, 5900, 1433, 873];
        var rows = {};
        for (var t = Math.ceil(${datestart} / 300) * 300, n = 0; t <= ${dateend}; t += 300, n++) {
            rows[t] = ports.map(function(p, i){
                var wave = Math.sin(n / (18 + (i * 7) % 40) + i) * 0.5 + 0.5;
                return Math.round((1200 + (i * 7919) % 9000) * (0.35 + wave));
            });
        }
        var config = JSON.parse(el.dataset.chartConfig);
        config.display = 'ports';
        config.seriesNames = ports.map(String);
        config.seriesSlots = ports.map(function(p, i){ return i + 1; });
        el.dataset.chartConfig = JSON.stringify(config);
        el.dataset.chartData = JSON.stringify({data: rows, legend: ports.map(function(p){ return p + '_bytes_any'; })});
    })()`);
}

/** Display by in the graph options, waiting for the chart to redraw with it. */
async function setDisplay(page, display) {
    await page.setSelectValue('#filterDisplaySelect', display);
    await page.waitFor(`JSON.parse(document.getElementById('trafficGraph')?.dataset.chartConfig || '{}').display === ${js(display)}`, {
        timeout: 15000,
        label: `the graph to display ${display}`,
    });
    await sleep(800);
}

// SPEC 5.6 shows one rule and its history. The instance's rule with the most events stands for it (or
// ALERT_RULE by name), and CSS hides the rest, so the capture writes no rule and no event.
async function alertStage(page) {
    return page.evaluate(`(function(){
        var rules = [...document.querySelectorAll('#alertRules tbody tr')].map(function(r){
            return {id: r.id, name: r.querySelector('th')?.firstChild?.textContent.trim()};
        });
        var events = [...document.querySelectorAll('#alertHistory .alert-event')];
        var of = function(name){ return events.filter(function(e){ return e.querySelector('.alert-event-rule')?.textContent.trim() === name; }); };
        var want = ${js(process.env.ALERT_RULE || '')};
        var rule = want ? rules.find(function(r){ return r.name === want; })
            : rules.sort(function(a, b){ return of(b.name).length - of(a.name).length; })[0];
        if (!rule || !rule.id) return null;
        var keep = of(rule.name).map(function(e){ return '#' + e.id; });
        var firing = /Firing/.test(document.getElementById(rule.id).textContent);
        return {rule: rule.name, events: keep.length, firing: firing,
                css: (firing ? '' : '.sidebar-nav .nav-badge { display: none; } ') + '#alertRules tbody tr:not(#' + rule.id + '), #alertHistory .alert-event' + keep.map(function(k){ return ':not(' + k + ')'; }).join('') + ' { display: none; }'};
    })()`);
}

// ── The tour ────────────────────────────────────────────────────────────────

async function desktop(page) {
    await step(page, ['00-page-overview', 'guide-overview-topn'], async () => {
        try {
            const hold = await overviewWithTopn(page);
            await shot(page, '00-page-overview', undefined, { hold });
            await shot(page, 'guide-overview-topn', ['#overviewKpis', '#overviewTopn'], { hold });
        } finally {
            await leaveOverview(page);
        }
    });

    await step(page, ['guide-controls-bar'], async () => {
        await go(page, 'overview');
        const live = () => openMenu(page, '#liveMenu .menu-toggle', '#liveMenuList');
        await live();
        // The menu hangs over the graph; with the plot hidden no cut band of it shows around the menu.
        try {
            await shot(page, 'guide-controls-bar', ['.controls-bar', '#trafficGraphSection .traffic-graph-header', panelOf('#liveMenu')], {
                prepare: async () => {
                    await stage(page, '#trafficGraphSection .traffic-graph-body { visibility: hidden; }');
                    await live();
                },
                verify: () => page.evaluate(`!!document.getElementById('captureStage')`),
            });
        } finally {
            await stage(page, null);
        }
    });

    await step(page, ['guide-graph-options'], async () => {
        await go(page, 'overview');
        await click(page, '#graphOptionsToggle');
        await page.waitFor(`!document.getElementById('graphOptions').hidden`, { label: 'graph options' });
        try {
            await shot(page, 'guide-graph-options', ['#trafficGraphSection .traffic-graph-header', '#graphOptions']);
        } finally {
            await click(page, '#graphOptionsToggle');
        }
    });

    await step(page, ['guide-ip-info-modal'], async () => {
        try {
            await overviewWithTopn(page);
            await page.waitFor(`!!document.querySelector('#ovTopnTable .ip-link, #overviewKpis .ip-link')`, {
                timeout: 30000,
                label: 'an address',
            });
            await click(page, '#ovTopnTable .ip-link, #overviewKpis .ip-link');
            await page.waitFor(`document.getElementById('ip-modal-inner')?.isOpen === true`, { timeout: 20000, label: 'IP info modal' });
            // As a mouse click leaves it: no focus ring on Close.
            await page.evaluate('document.activeElement?.blur()');
            await sleep(800);
            await shot(page, 'guide-ip-info-modal', `document.getElementById('ip-modal-inner').shadowRoot.querySelector('[part~="panel"]')`, { margin: 0 });
        } finally {
            await leaveOverview(page);
        }
    });

    await step(page, ['guide-sidebar-collapsed'], async () => {
        const collapsed = `document.body.dataset.sidebar === 'collapsed'`;
        try {
            const hold = await overviewWithTopn(page);
            await click(page, '.sidebar-toggle');
            await page.waitFor(collapsed, { label: 'collapsed sidebar' });
            await sleep(500);
            await shot(page, 'guide-sidebar-collapsed', 'viewport', { hold });
        } finally {
            if (await page.evaluate(collapsed).catch(() => false)) await click(page, '.sidebar-toggle');
            await leaveOverview(page);
        }
    });

    const building = `document.getElementById('graphFilterRun')?.dataset.queryState === 'running'`;
    // A build takes a second or two, so each theme starts its own. The filter differs every time,
    // or the cache (same filter and window within 10 minutes) answers before it can be caught.
    await step(
        page,
        ['guide-query-progress'],
        async () => {
            await go(page, 'overview');
            await click(page, '#graphModeFiltered');
            try {
                await page.waitFor(`!!document.getElementById('graphNfdumpTextarea')`, { timeout: 15000, label: 'filtered mode' });
                // The Builder and Saved buttons end 8 px above the run row.
                await shot(page, 'guide-query-progress', '#graphFilterRun', {
                    margin: [6, 12, 12, 12],
                    prepare: async () => {
                        await page.waitFor(`!(${building})`, { timeout: QUERY_TIMEOUT, label: 'the build before to finish' });
                        const expr = `(${FILTER_EXPR}) and bytes > ${Math.floor(Math.random() * 1000)}`;
                        await setFilter(page, 'graphNfdumpTextarea', 'overview', expr);
                        await click(page, 'button[data-run="overview"]');
                        await page.waitFor(building, { timeout: 8000, label: 'the filtered graph to start' });
                        await sleep(400);
                    },
                });
            } finally {
                await page.waitFor(`!(${building})`, { timeout: QUERY_TIMEOUT, label: 'the build to finish' }).catch(() => {});
                await click(page, '#graphModeRrd');
            }
        },
        { optional: true }
    );

    await step(page, ['guide-graphs-filtered'], async () => {
        await go(page, 'overview');
        await click(page, '#graphModeFiltered');
        try {
            await page.waitFor(`!!document.getElementById('graphNfdumpTextarea')`, { timeout: 15000, label: 'filtered mode' });
            await setFilter(page, 'graphNfdumpTextarea', 'overview', FILTER_EXPR);
            await run(page, 'overview');
            await sleep(1500);
            await shot(page, 'guide-graphs-filtered', '#trafficGraphSection');
        } finally {
            await click(page, '#graphModeRrd');
        }
    });

    await step(page, ['guide-flows-estimate', '01-page-flows', 'guide-flows-aggregation'], async () => {
        await go(page, 'flows');
        await page.setSelectValue('#flowsLimit', '20');
        await setFilter(page, 'filterNfdumpTextarea', 'flows', FILTER_EXPR);
        await page.waitFor(`document.querySelector('[data-estimate="flows"]')?.innerText.includes('file')`, {
            timeout: 15000,
            label: 'estimate',
        });
        await shot(page, 'guide-flows-estimate', '.flows-query');
        await flowsResult(page);
        await shot(page, '01-page-flows');
        await page.evaluate(`document.getElementById('filterFlowAggregation').open = true`);
        try {
            await shot(page, 'guide-flows-aggregation', '#filterFlowAggregation');
        } finally {
            await page.evaluate(`document.getElementById('filterFlowAggregation').open = false`);
        }
    });

    await step(page, ['guide-flows-summary'], async () => {
        await flowsResult(page);
        await click(page, '#flowsTab-summary');
        try {
            await run(page, 'flows-summary');
            await page.waitFor(`!!document.querySelector('#flowsFiltered .flows-figures') && !document.querySelector('#flowsPanel-summary [aria-busy="true"]')`, {
                timeout: 15000,
                label: 'range and filtered totals',
            });
            // A filtered subset larger than the unfiltered range means the stored series misses part of the range.
            const [range, filtered] = await page.evaluate(`(function(){
                var bytes = function(t){
                    var m = (t || '').replace(/,/g, '').match(/([\\d.]+)\\s*(B|KiB|MiB|GiB|TiB)\\b/);
                    return m ? Number(m[1]) * Math.pow(1024, ['B', 'KiB', 'MiB', 'GiB', 'TiB'].indexOf(m[2])) : null;
                };
                var all = [...document.querySelectorAll('[aria-labelledby="flowsRangeTitle"] tr')].find(function(r){ return /All protocols/.test(r.textContent); });
                var dd = [...document.querySelectorAll('#flowsFiltered .flows-figures div')].find(function(d){ return d.querySelector('dt').textContent.trim() === 'Bytes'; });
                return [bytes(all && all.cells[all.cells.length - 1].textContent), bytes(dd && dd.querySelector('dd').textContent)];
            })()`);
            const hold =
                range === null || filtered === null
                    ? 'no Range totals or Filtered totals to compare'
                    : filtered > range * 1.01
                      ? `the filtered totals (${filtered} B) exceed the unfiltered range totals (${range} B): the stored series misses part of the range (force a rescan)`
                      : null;
            await shot(page, 'guide-flows-summary', '#flowResults', { hold });
        } finally {
            await click(page, '#flowsTab-flows');
        }
    });

    await step(page, ['09-page-flows-graph'], async () => {
        await flowsResult(page);
        await page.evaluate(
            `(function(){ var b = document.querySelector('#flowsGraph .flows-disclosure'); if (b.getAttribute('aria-expanded') !== 'true') b.click(); })()`
        );
        try {
            await run(page, 'flows-graph');
            await page.waitFor(`!!document.querySelector('#flowsGraph nfsen-chart')`, { timeout: 15000, label: 'flows graph' });
            await sleep(1000);
            await shot(page, '09-page-flows-graph');
        } finally {
            await click(page, '#flowsGraph .flows-disclosure');
        }
    });

    await step(page, ['guide-filter-drawer', 'guide-saved-filters'], async () => {
        await flowsResult(page);
        await click(page, '[data-filter-field="flows"] [data-open-drawer="builder"]');
        await page.waitFor(`document.getElementById('filter-drawer')?.isOpen && !!document.getElementById('drawerFilterTextarea')`, {
            timeout: 15000,
            label: 'drawer',
        });
        await page.waitFor(`document.querySelector('[data-filter-status="drawer"]')?.dataset.state === 'valid'`, {
            timeout: 15000,
            label: 'drawer verdict',
        });
        await page.evaluate(`document.activeElement?.blur()`);
        await sleep(1200);
        await shot(page, 'guide-filter-drawer', `document.getElementById('filter-drawer').shadowRoot.querySelector('[part~="panel"]')`, { margin: 0 });
        const menu = () =>
            openMenu(page, '#drawerSavedList .saved-filter [slot="trigger"]', '#drawerSavedList sb-popover.saved-menu .popover-list', {
                mouse: true,
            });
        await menu();
        await shot(
            page,
            'guide-saved-filters',
            ['#drawerSavedTitle', '#drawerSavedList .saved-filter:nth-child(6)', panelOf('#drawerSavedList sb-popover.saved-menu')],
            { prepare: menu, margin: [12, 12, 0, 12] }
        );
    });

    await step(page, ['guide-talkers-tabs', '02-page-talkers'], async () => {
        await go(page, 'talkers');
        await click(page, '#talkersTab-talkers');
        await sleep(400);
        // Chrome draws an open native select outside the page, so a listbox of its options stands in for it.
        // The side panels under the open menu are hidden, so none shows half covered around it.
        const more = `(function(){
            var s = document.getElementById('statsFilterForSelection'), r = s.getBoundingClientRect();
            var side = document.querySelector('.talkers-side').getBoundingClientRect();
            var l = document.getElementById('captureMoreList') || document.body.appendChild(document.createElement('select'));
            l.id = 'captureMoreList';
            l.innerHTML = s.innerHTML;
            l.value = s.value;
            var top = r.bottom + 4;
            l.style.cssText = 'position: absolute; z-index: 50; left: ' + (r.left + scrollX) + 'px; top: ' + (top + scrollY) + 'px; width: '
                + r.width + 'px; box-shadow: var(--shadow-2)';
            // Whole rows down to the bottom of the side column, so no row is cut at the list's edge.
            for (l.size = 2; l.size < l.options.length + 8 && l.getBoundingClientRect().bottom <= side.bottom; l.size++);
            l.size -= 1;
        })()`;
        try {
            // The direction sits in the Query card, beside the side panels: a band through them would cut both.
            await shot(page, 'guide-talkers-tabs', ['.stat-bar', '.talkers-layout', '#captureMoreList'], {
                prepare: async () => {
                    await stage(page, '.talkers-side > * { visibility: hidden; }');
                    await page.evaluate(more);
                },
                verify: () => page.evaluate(`!!document.getElementById('captureMoreList') && !!document.getElementById('captureStage')`),
            });
        } finally {
            await page.evaluate(`document.getElementById('captureMoreList')?.remove()`);
            await stage(page, null);
        }
        await run(page, 'talkers');
        await run(page, 'talkers-proto');
        await run(page, 'talkers-as');
        await page.waitFor(`document.querySelectorAll('.talkers-panel .bar-list').length === 2`, {
            timeout: 15000,
            label: 'both side panels',
        });
        await shot(page, '02-page-talkers');
    });

    await step(page, ['guide-statistics-aggregation', 'guide-statistics-bidirectional'], async () => {
        await go(page, 'talkers');
        await page.setSelectValue('#statsFilterForSelection', 'record');
        await page.waitFor(`!document.getElementById('filterStatsAggregation').hidden`, { label: 'aggregation controls' });
        await shot(page, 'guide-statistics-aggregation', '#filterStatsAggregation', { margin: [8, 12, 12, 12] });
        // nfdump prints -B as a fixed-width table whatever -o asks for; the app reads it back into columns (#174).
        await click(page, '#filterStatsAggBi');
        try {
            await run(page, 'talkers');
            await shot(page, 'guide-statistics-bidirectional', '#statsResults', { margin: [8, 12, 12, 12] });
        } finally {
            await click(page, '#filterStatsAggBi');
        }
    });

    await step(page, ['03-page-conversations', 'guide-conversations-matrix', 'guide-conversations-pairs'], async () => {
        await go(page, 'conversations');
        await run(page, 'conversations');
        await page.waitFor(`!!document.querySelector('#convPanel-sankey nfsen-sankey canvas')`, { timeout: 15000, label: 'sankey' });
        await sleep(1000);
        await shot(page, '03-page-conversations');
        try {
            for (const view of ['matrix', 'pairs']) {
                await click(page, `#convView-${view}`);
                await sleep(1000);
                await shot(page, `guide-conversations-${view}`, '#conversationsResults');
            }
        } finally {
            await click(page, '#convView-sankey');
        }
    });

    await step(page, ['guide-sankey-ports'], async () => {
        await go(page, 'conversations');
        await page.setSelectValue('#convGroupBy', 'port');
        try {
            await run(page, 'conversations');
            await sleep(1000);
            await shot(page, 'guide-sankey-ports', [
                `document.getElementById('conversationsQueryTitle').closest('section')`,
                '#conversationsResults',
            ]);
        } finally {
            await page.setSelectValue('#convGroupBy', 'ip');
        }
    });

    await step(
        page,
        ['04-page-alerts', 'guide-alerts-traffic-filter', 'guide-alerts-template-override', 'guide-alerts-default-templates'],
        async () => {
            await go(page, 'alerts');
            await page.evaluate(
                `(function(){ var t = document.querySelector('.alert-templates-toggle'); if (t.getAttribute('aria-expanded') === 'true') t.click(); })()`
            );
            if (wanted('04-page-alerts')) {
                const staged = await alertStage(page);
                if (!staged) throw new Error(`no alert rule to show${process.env.ALERT_RULE ? ` named "${process.env.ALERT_RULE}"` : ''}`);
                if (!staged.events) console.warn(`note: the rule "${staged.rule}" has no events, so the history is empty`);
                const badge = '#alertRulesTitle + .badge';
                try {
                    await shot(page, '04-page-alerts', undefined, {
                        prepare: async () => {
                            await stage(page, staged.css);
                            // Only the events that fit the history's scroll region whole, so no row is cut at its edge.
                            await page.evaluate(`(function(){
                                var s = document.getElementById('captureStage'), box = document.querySelector('.alert-events-scroll').getBoundingClientRect();
                                var cut = [...document.querySelectorAll('#alertHistory .alert-event')].filter(function(e){
                                    return e.getClientRects().length && e.getBoundingClientRect().bottom > box.bottom + 0.5;
                                });
                                if (cut.length) s.textContent += cut.map(function(e){ return '#' + e.id; }).join(', ') + ' { display: none; }';
                            })()`);
                            // The sidebar counts firing rules, of which the image shows at most this one.
                            await page.evaluate(`(function(){
                                document.querySelector(${js(badge)}).textContent = '1 rule';
                                var n = document.querySelector('.sidebar-nav .nav-badge');
                                if (n && n.firstChild) n.firstChild.nodeValue = '1';
                            })()`);
                        },
                        verify: () =>
                            page.evaluate(
                                `!!document.getElementById('captureStage') && document.querySelector(${js(badge)}).textContent === '1 rule'`
                            ),
                    });
                } finally {
                    await stage(page, null).catch(() => {});
                }
            }
            await setFilter(page, 'alertNfdumpFilter', 'alert', 'dst port 22');
            await shot(page, 'guide-alerts-traffic-filter', `document.getElementById('alertNfdumpFilter').closest('fieldset')`, {
                margin: [8, 12, 12, 12],
            });
            await page.setInputValue('#alertNfdumpFilter', '');
            const override = `document.getElementById('alertFormWebhookTitle').closest('details')`;
            await page.evaluate(`${override}.open = true`);
            await shot(
                page,
                'guide-alerts-template-override',
                `document.getElementById('alertFormWebhook').closest('[data-token-scope]')`,
                { margin: 8 }
            );
            await page.evaluate(`${override}.open = false`);
            await openMenu(page, '.alert-templates-toggle', '#alertTemplatesBody');
            try {
                await shot(page, 'guide-alerts-default-templates', '#alert-default-templates-card');
            } finally {
                await click(page, '.alert-templates-toggle');
            }
        }
    );

    await step(page, ['05-page-health', 'guide-health-nfdump', 'guide-health-sources'], async () => {
        await go(page, 'health');
        await page.waitFor(`document.querySelectorAll('#healthChecks tbody').length > 1`, { timeout: 30000, label: 'health checks' });
        await shot(page, '05-page-health');
        await shot(page, 'guide-health-nfdump', `__groupRect('nfdump')`, { margin: 0 });
        // Disk usage sits beside Import and above System: one box around both cards would take those in too.
        await shot(page, 'guide-health-sources', { stack: ['#healthDisks', '#healthSources'] }, { margin: [12, 12, 8, 12], instance: true });
    });

    const tabs = { general: '06-page-settings', integrations: '07-page-settings-integrations', system: '08-page-settings-system' };
    await step(page, Object.values(tabs), async () => {
        await go(page, 'settings');
        try {
            for (const [tab, name] of Object.entries(tabs)) {
                await click(page, `#settingsTab-${tab}`);
                await page.waitFor(`!document.getElementById('settingsPanel-${tab}').hidden`, { label: `settings ${tab}` });
                await sleep(500);
                await shot(page, name);
            }
        } finally {
            await click(page, '#settingsTab-general');
        }
    });

    // Last, so the synthetic graph can reach no other image.
    await step(page, ['guide-graphs-series-tooltip', 'guide-graphs-series-panels'], async () => {
        await go(page, 'overview');
        const display = (await page.signalValue('graph_display')) || 'sources';
        const hover = () => hoverGraph(page);
        try {
            if (display !== 'ports') await setDisplay(page, 'ports');
            await page.waitFor(`document.getElementById('trafficGraphTitle')?.textContent.includes('by port')`, {
                label: 'the graph title to say port',
            });
            // A server redraw can replace the synthetic series at any time: each theme puts them back.
            const prepare = async () => {
                await syntheticSeries(page);
                await sleep(900);
                await hover();
            };
            const verify = () =>
                page.evaluate(
                    `document.querySelectorAll('.traffic-graph-side input[type=checkbox]').length >= 40 && JSON.parse(document.getElementById('trafficGraph').dataset.chartConfig).seriesNames.length === 40`
                );
            await shot(page, 'guide-graphs-series-tooltip', ['#trafficGraphSection', TOOLTIP], { prepare, verify });
            await shot(page, 'guide-graphs-series-panels', '.traffic-graph-side', { prepare, verify });
        } finally {
            await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 1, y: 1 });
            // Back to the real series: another display redraws the graph from the server.
            await setDisplay(page, display === 'ports' ? 'sources' : display);
            if (display === 'ports') await setDisplay(page, 'ports');
        }
    });
}

async function phone(page) {
    await step(page, ['10-page-mobile'], async () => {
        const hold = await overviewWithTopn(page);
        await page.evaluate('window.scrollTo(0, 0)');
        await sleep(500);
        await shot(page, '10-page-mobile', 'viewport', { hold });
    });
}

async function main() {
    await withPage(
        async (page) => {
            await setup(page, DESKTOP);
            WINDOW = await pickWindow(page);
            await applyWindow(page, WINDOW);
            const { datestart, dateend } = await page.signalValues(['datestart', 'dateend']);
            console.log(
                `window ${new Date(datestart * 1000).toISOString()} to ${new Date(dateend * 1000).toISOString()}${WINDOW.preset ? ' (live)' : ''}`
            );
            await stackNotes(page);
            await desktop(page);
            await page.evaluate('window.__nfsenTheme.choose(null)');
        },
        { width: DESKTOP.width, height: DESKTOP.height }
    );
    if (ONLY && !ONLY.has('10-page-mobile')) return;
    await withPage(
        async (page) => {
            await setup(page, PHONE);
            await phone(page);
            await page.evaluate('window.__nfsenTheme.choose(null)');
        },
        { width: PHONE.width, height: PHONE.height, mobile: true }
    );
}

function summary() {
    const of = (state) => SHOTS.filter((s) => s.state === state);
    console.log(`\n${of('changed').length} changed, ${of('new').length} new, ${of('unchanged').length} unchanged`);
    for (const s of SHOTS.filter((s) => s.state !== 'unchanged' || s.pixels > 0))
        console.log(`  ${s.state.padEnd(9)} ${s.name}${s.note ? ` (${s.note})` : ''}`);
    if (SHOTS.some((s) => s.state === 'changed' || s.pixels > 0)) console.log(`  diffs in ${DIFFS}`);
    for (const h of HELD) console.log(`  held      ${h.name} (committed image kept: ${h.why})`);
    if (!ONLY && !FAILED.length) {
        const made = new Set([...SHOTS, ...HELD].map((s) => `${s.name}.png`));
        const stale = readdirSync(OUT).filter((f) => f.endsWith('.png') && !f.startsWith('.') && !made.has(f));
        if (stale.length) console.log(`  in ${OUT} but not made by this run: ${stale.join(', ')}`);
    }
    if (FAILED.length) console.log(`  failed: ${FAILED.join('; ')}`);
}

main()
    .then(() => {
        summary();
        process.exit(FAILED.length || HELD.length ? 1 : 0);
    })
    .catch((e) => {
        console.error(e);
        summary();
        process.exit(1);
    });
