// Pure-Node (Node 22 global WebSocket) Chrome DevTools Protocol screenshot driver.
// Launches headless Chrome, drives the real Datastar reactive UI (click -> SSE
// patch, same as a human), and captures named PNGs into OUT (default:
// src/images next to this script).
//
// Unlike a multi-route app, nfsen-ng is a single page (`/`) whose "tabs" are
// all client-local Datastar signals (`$_currentView`, `$_settingsSection` --
// see backend/templates/partials/nav.html.twig and settings.html.twig) that
// never round-trip to the server. So instead of navigating to different
// URLs, every shot after the first just clicks the real nav link / sub-nav
// link for that section, exactly as a user would, and lets Datastar's own
// data-on:click__prevent handler flip the signal and reveal the panel.
//
// Every shot is captured twice -- once with prefers-color-scheme forced to
// light, once to dark (Emulation.setEmulatedMedia; nfsen-ng's Bootstrap
// data-bs-theme + CSS variables respond to this directly) -- then stitched
// side by side into a single PNG via ImageMagick's `convert` (`+append`; must
// be on PATH).
//
// Each stitched shot is compared against the already-committed PNG before it
// replaces it: identical-looking captures (within FUZZ/DIFF_THRESHOLD, which
// absorb Chrome's run-to-run antialiasing jitter) are discarded so the
// working tree stays clean, and only genuinely-changed shots are
// overwritten -- each of those also getting a labelled old/new/diff image
// dropped in book/.capture-diffs/ for review. A run-end summary lists what
// changed, so `git add` only picks up real visual changes.
//
// Run against the dev stack (deploy/docker-compose.dev.yml): the app has no
// auth wall, so no login dance is needed -- just point BASE at wherever it's
// reachable (the bundled Caddy on :8080 by default; override BASE= if your
// setup differs, e.g. hitting the app container's own port directly).
//
//   docker compose -f deploy/docker-compose.dev.yml up -d
//   node book/_capture.mjs
//
// The shots are only as good as the data behind them: a freshly-started dev
// stack with no live traffic generator (see deploy/docker-compose.dev.yml's
// commented-out nfcapd/softflowd instructions) will render mostly-empty
// graphs/flows/stats. That's fine for documenting the UI's *shape*; for
// richer-looking shots, point BASE at an instance with real (or seeded)
// nfcapd data.
//
// Unlike a static seeded-demo-DB app, nfsen-ng is a live system: the graphs
// page's "LIVE last update" clock, the alerts page's "Recent Alert History"
// timestamps, and the health panel's "capture freshness" ages all genuinely
// change from one run to the next. Expect most shots to report CHANGED even
// when nothing structurally moved -- that's the diff mechanism doing its job
// (flagging real content differences for review), not FUZZ/DIFF_THRESHOLD
// needing recalibration. Skim .capture-diffs for actual layout regressions
// before committing; don't chase timestamp churn to zero.
import { spawn, spawnSync, execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, unlinkSync, renameSync, rmSync, existsSync, readdirSync, statSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE || 'http://localhost:8080';
const OUT = process.env.OUT || join(import.meta.dirname, 'src/images');
// Where per-shot visible diffs land when a capture actually changed (gitignored).
const DIFFS = process.env.DIFFS || join(import.meta.dirname, '.capture-diffs');
// A freshly captured shot only overwrites the committed PNG when it differs from
// it by more than DIFF_THRESHOLD (fraction of pixels), after ignoring per-pixel
// colour wobble within FUZZ. Chrome re-renders text/antialiasing with tiny
// run-to-run jitter, so a byte-for-byte overwrite would mark every image dirty
// in git on every run even when nothing visibly moved. Both are env-tunable; the
// summary prints each shot's measured diff so the thresholds are easy to calibrate.
const FUZZ = process.env.FUZZ || '2%';
const DIFF_THRESHOLD = Number(process.env.DIFF_THRESHOLD ?? '0.0002');
const PORT = 9334; // Chrome's own remote-debugging port, unrelated to the app's port
const W = 1440, H = 1000;
// Device pixel ratio for captured screenshots. Chrome is launched at
// --force-device-scale-factor=1 (so the CSS layout math above stays simple),
// then overridden to this via Emulation.setDeviceMetricsOverride right after
// connecting -- CSS layout is unaffected (same W x H viewport), only the
// output pixel density changes, same technique DevTools' own device toolbar
// uses. 1.5x sharpens text/lines on high-DPI displays without the ~4x file
// size hit of a full 2x (quantization already claws back most of the size
// difference -- see the palette-quantization commit).
const DPR = 1.5;

// Newest non-snap Playwright Chrome (snap chromium can't write screenshots to /tmp).
function resolveChrome() {
  if (process.env.CHROME) return process.env.CHROME;
  const base = join(homedir(), '.cache/ms-playwright');
  const found = readdirSync(base)
    .filter(d => d.startsWith('chromium-') && !d.includes('headless_shell'))
    .map(d => join(base, d, 'chrome-linux64/chrome'))
    .filter(p => { try { return statSync(p).isFile(); } catch { return false; } })
    .sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs);
  if (!found.length) throw new Error('no Playwright Chrome under ~/.cache/ms-playwright; set CHROME=');
  return found[0];
}
const CHROME = resolveChrome();

mkdirSync(OUT, { recursive: true });
// Start each run from an empty diff dir so leftover diffs from a previous run
// can't be mistaken for this run's changes.
rmSync(DIFFS, { recursive: true, force: true });
mkdirSync(DIFFS, { recursive: true });
const profile = mkdtempSync(join(tmpdir(), 'cdp-'));

// Per-shot outcome, tallied for the end-of-run summary.
// state: 'new' (no committed file yet) | 'changed' (overwrote, diff emitted) | 'unchanged' (kept committed file).
const SHOTS = [];

const chrome = spawn(CHROME, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
  `--remote-debugging-port=${PORT}`, '--remote-allow-origins=*',
  '--no-first-run', '--no-default-browser-check',
  `--user-data-dir=${profile}`, `--window-size=${W},${H}`,
  '--force-device-scale-factor=1', 'about:blank',
], { stdio: ['ignore', 'ignore', 'inherit'] });

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function wsEndpoint() {
  for (let i = 0; i < 50; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${PORT}/json/list`);
      const tabs = await r.json();
      const page = tabs.find(t => t.type === 'page');
      if (page?.webSocketDebuggerUrl) return page.webSocketDebuggerUrl;
    } catch {}
    await sleep(200);
  }
  throw new Error('Chrome CDP endpoint never came up');
}

// minimal CDP client over the page target socket
let ws, nextId = 1;
const pending = new Map();
const loadWaiters = [];
function send(method, params = {}) {
  const id = nextId++;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((res, rej) => pending.set(id, { res, rej }));
}
function connect(url) {
  return new Promise((resolve, reject) => {
    ws = new WebSocket(url);
    ws.onopen = () => resolve();
    ws.onerror = (e) => reject(new Error('ws error: ' + (e.message || e.type)));
    ws.onmessage = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.id && pending.has(m.id)) {
        const { res, rej } = pending.get(m.id); pending.delete(m.id);
        m.error ? rej(new Error(m.method + ': ' + JSON.stringify(m.error))) : res(m.result);
      } else if (m.method === 'Page.loadEventFired') {
        while (loadWaiters.length) loadWaiters.shift()();
      }
    };
  });
}

async function evaluate(expression) {
  const r = await send('Runtime.evaluate', {
    expression, returnByValue: true, awaitPromise: true,
  });
  if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
  return r.result.value;
}

async function navigate(url) {
  const loaded = new Promise(res => loadWaiters.push(res));
  await send('Page.navigate', { url });
  await loaded;
}

async function waitJs(expr, { timeout = 9000, label = expr } = {}) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) {
    try { if (await evaluate(`!!(${expr})`)) return; } catch {}
    await sleep(150);
  }
  throw new Error('timeout waiting for: ' + label);
}

// helper injected page-side: drive the real Datastar-bound controls the way
// a human would, rather than poking $_currentView/$_settingsSection directly.
const HELPERS = `
// Set a <select data-bind> element's value (found by CSS selector, since
// Datastar's bind() generates a hashed data-bind attribute, not a stable
// id) and dispatch input/change so Datastar's own binding notices, the same
// way a real user picking an option would -- used to cap "Limit Flows"
// before shooting the flows table so the screenshot isn't hundreds of rows
// tall.
window.__setSelect = function(selector, value){
  var e = document.querySelector(selector);
  if (!e) return false;
  var setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set;
  setter.call(e, String(value));
  e.dispatchEvent(new Event('input', {bubbles:true}));
  e.dispatchEvent(new Event('change', {bubbles:true}));
  return true;
};
// Same idea as __setSelect but for a Datastar-bound checkbox/switch (e.g. the
// Sankey "Show dst port" toggle, #sankeyShowPorts) -- set .checked through the
// native setter and fire input/change so bind() notices, exactly as a user
// clicking the switch would.
window.__setCheckbox = function(id, checked){
  var e = document.getElementById(id);
  if (!e) return false;
  var setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'checked').set;
  setter.call(e, !!checked);
  e.dispatchEvent(new Event('input', {bubbles:true}));
  e.dispatchEvent(new Event('change', {bubbles:true}));
  return true;
};
// Click the first element with a data-on:click* attribute (nfsen-ng uses both
// data-on:click and data-on:click__prevent) whose expression contains sub --
// this is how every nav tab / settings sub-nav link is wired (see
// nav.html.twig / settings.html.twig), so clicking it exercises the exact
// same code path a real user triggers instead of poking signal state.
window.__clickAttr = function(sub){
  var all = document.querySelectorAll('*');
  for (var i=0;i<all.length;i++){
    var attrs = all[i].attributes;
    for (var j=0;j<attrs.length;j++){
      if (attrs[j].name.indexOf('data-on:click') === 0 && attrs[j].value.indexOf(sub) >= 0){
        all[i].click();
        return true;
      }
    }
  }
  return false;
};
// Only considers VISIBLE matches (offsetParent !== null) -- nfsen-ng's
// immediate-mode rendering keeps every tab's markup in the DOM at once
// (hidden via data-show/display:none), so identical button text (e.g. every
// data tab's own "Process data" button) exists multiple times simultaneously
// and an unfiltered match would always hit the first (likely wrong, hidden)
// one regardless of which tab is actually showing.
window.__clickText = function(text, tag){
  var els = document.querySelectorAll(tag || 'button,a');
  var el = [...els].find(e => e.offsetParent !== null && e.textContent.trim().includes(text));
  if(el){ el.click(); return true; } return false;
};
// Returns the element right after the first heading whose text includes
// headingText -- used to crop a labelled form section (e.g. the alerts
// form's "Traffic filter" block) that has no id/class of its own.
window.__afterHeading = function(headingText, headingTag){
  var hs = document.querySelectorAll(headingTag || 'h6');
  var h = [...hs].find(e => e.textContent.includes(headingText));
  return h ? h.nextElementSibling : null;
};
// Returns a synthetic bounding rect spanning the health-check group-header
// row labelled groupLabel through the row before the next group header --
// the health table has no per-group wrapping element, just a flat list of
// <tr>s with an occasional colspan group-header row (see HealthChecker.php).
window.__groupRect = function(groupLabel){
  var rows = [...document.querySelectorAll('table tr')];
  var startIdx = rows.findIndex(r => { var td = r.querySelector('td[colspan]'); return td && td.textContent.trim() === groupLabel; });
  if (startIdx === -1) return null;
  var endIdx = rows.length;
  for (var i = startIdx + 1; i < rows.length; i++) {
    if (rows[i].querySelector('td[colspan]')) { endIdx = i; break; }
  }
  var first = rows[startIdx].getBoundingClientRect();
  var last = rows[endIdx - 1].getBoundingClientRect();
  return { x: first.x, y: first.y, width: first.width, height: (last.y + last.height) - first.y };
};`;

// nfsen-ng's dark mode is an application-level Datastar signal ($_darkMode,
// persisted to localStorage and reflected onto <html data-bs-theme>), toggled
// by clicking the moon/sun nav icon -- see layout.html.twig's data-init/
// data-attr:data-bs-theme and nav.html.twig's data-on:click__prevent="$_darkMode
// = !$_darkMode". Unlike a CSS light-dark()-token app, forcing the OS-level
// prefers-color-scheme media feature (Emulation.setEmulatedMedia) does nothing
// here -- so toggle the real control instead, exactly as a user would.
async function setDarkMode(on) {
  const isDark = await evaluate(`document.documentElement.getAttribute('data-bs-theme') === 'dark'`);
  if (isDark !== on) {
    const clicked = await evaluate(`__clickAttr("_darkMode = !")`);
    if (!clicked) throw new Error('dark-mode toggle not found');
  }
}

// clipSel is either a plain CSS selector ('#ip-modal-inner') or, for crops
// that need more than querySelector can express (e.g. a labelled form
// section or a health-check group with no wrapping element -- see the
// __afterHeading/__groupRect page-side helpers), a full JS expression string
// evaluating to either an Element or an already-plain {x,y,width,height}
// rect -- detected by the presence of '(' (a bare selector never has one).
async function captureRaw(clipSel) {
  let params = { format: 'png', captureBeyondViewport: true };
  if (clipSel) {
    const elExpr = clipSel.includes('(') ? clipSel : `document.querySelector(${JSON.stringify(clipSel)})`;
    const rect = await evaluate(`(()=>{var e=${elExpr};if(!e)return null;var r=(typeof e.getBoundingClientRect==='function')?e.getBoundingClientRect():e;return {x:r.x,y:r.y,w:r.width,h:r.height};})()`);
    if (!rect) throw new Error('clip selector not found: ' + clipSel);
    const m = 24;
    params.clip = {
      x: Math.max(0, rect.x - m), y: Math.max(0, rect.y - m),
      width: rect.w + m * 2, height: rect.h + m * 2, scale: 1,
    };
  } else {
    const { cssContentSize } = await send('Page.getLayoutMetrics');
    params.clip = { x: 0, y: 0, width: cssContentSize.width, height: cssContentSize.height, scale: 1 };
  }
  const { data } = await send('Page.captureScreenshot', params);
  return { data, width: params.clip.width, height: params.clip.height };
}

function imgDims(file) {
  const [w, h] = execFileSync('identify', ['-format', '%w %h', file], { encoding: 'utf8' }).trim().split(/\s+/);
  return [parseInt(w, 10) || 0, parseInt(h, 10) || 0];
}

// buildVisibleDiff stacks committed-over-new (over the highlighted pixel diff,
// when one exists) into a single labelled DIFFS/name.png you can just open. The
// shots are already wide (light+dark stitched), so a vertical 1-column tile
// keeps the width and lets you scroll old vs new vs diff.
function buildVisibleDiff(name, oldFile, newFile, rawDiff) {
  const args = ['-label', 'committed', oldFile, '-label', 'new', newFile];
  if (rawDiff) args.push('-label', 'diff', rawDiff);
  args.push('-tile', '1x', '-geometry', '+0+8', '-background', 'white', '-fill', 'black', join(DIFFS, name + '.png'));
  execFileSync('montage', args);
}

// commitShot decides whether a freshly captured shot (newFile) should replace
// the committed OUT/name.png. An unchanged shot (within FUZZ/DIFF_THRESHOLD, or
// byte-identical) is discarded so the working tree stays exactly as git had it;
// a real change overwrites the committed file and drops a visible diff in DIFFS.
function commitShot(name, newFile) {
  const finalFile = join(OUT, name + '.png');

  if (!existsSync(finalFile)) {
    renameSync(newFile, finalFile);
    SHOTS.push({ name, state: 'new' });
    console.log('  new', name);
    return;
  }

  const [ow, oh] = imgDims(finalFile);
  const [nw, nh] = imgDims(newFile);
  const total = (ow * oh) || 1;

  // A dimension change (a taller table, a reflowed page) is unambiguously a
  // change, and IM6 `compare` doesn't flag it -- it composites over the first
  // image's geometry and returns a meaningless count -- so short-circuit before
  // compare and show old-vs-new with no (misleading) per-pixel diff pane.
  if (ow !== nw || oh !== nh) {
    buildVisibleDiff(name, finalFile, newFile, null);
    renameSync(newFile, finalFile);
    SHOTS.push({ name, state: 'changed', sizeMismatch: true });
    console.log('  CHANGED', name, `(size ${ow}x${oh} -> ${nw}x${nh}) -> diff in`, DIFFS);
    return;
  }

  const rawDiff = join(DIFFS, `.${name}.rawdiff.png`);
  const cmp = spawnSync('compare', ['-metric', 'AE', '-fuzz', FUZZ, finalFile, newFile, rawDiff], { encoding: 'utf8' });
  if (cmp.error) throw cmp.error;
  // -metric AE writes the differing-pixel count to stderr -- often in scientific
  // notation ("5.29e+06"), so parseFloat, not parseInt (which stops at the '.').
  // Exit 0 = within fuzz everywhere, 1 = some pixels differ.
  const diffPx = Math.round(parseFloat((cmp.stderr || '').trim().split(/\s+/)[0]) || 0);
  const ratio = diffPx / total;

  if (ratio <= DIFF_THRESHOLD) {
    unlinkSync(newFile);
    try { unlinkSync(rawDiff); } catch {}
    SHOTS.push({ name, state: 'unchanged', diffPx, ratio });
    console.log('  unchanged', name, `(${diffPx}px, ${(ratio * 100).toFixed(3)}%)`);
    return;
  }

  // Build the visible diff while both old and new still exist, then promote.
  buildVisibleDiff(name, finalFile, newFile, rawDiff);
  try { if (existsSync(rawDiff)) unlinkSync(rawDiff); } catch {}
  renameSync(newFile, finalFile);
  SHOTS.push({ name, state: 'changed', diffPx, ratio });
  console.log('  CHANGED', name, `(${diffPx}px, ${(ratio * 100).toFixed(3)}%) -> diff in`, DIFFS);
}

function printSummary() {
  if (!SHOTS.length) return;
  const pick = s => SHOTS.filter(x => x.state === s);
  const changed = pick('changed'), created = pick('new'), same = pick('unchanged');
  console.log(`\nSummary: ${changed.length} changed, ${created.length} new, ${same.length} unchanged (of ${SHOTS.length})`);
  if (created.length) console.log('  new:      ' + created.map(s => s.name).join(', '));
  if (changed.length) {
    console.log('  changed:  ' + changed.map(s => s.name + (s.sizeMismatch ? ' (size)' : ` (${(s.ratio * 100).toFixed(3)}%)`)).join(', '));
    console.log('  review the visible diffs in ' + DIFFS + ', then commit only the changed/new *.png in ' + OUT);
  } else if (!created.length) {
    console.log('  nothing to commit -- every shot matched its committed image.');
  }
}

// shot captures name/clipSel once with light forced, once with dark forced,
// and stitches the pair side by side (light left, dark right) via `convert
// +append`. The 300ms settle after each dark-mode toggle gives Bootstrap's
// data-bs-theme + ECharts theme listeners a beat to repaint before
// the capture. The stitched result is written to a temp file and handed to
// commitShot, which only overwrites the committed name.png when it actually
// differs (see the DIFF_THRESHOLD/FUZZ notes up top).
async function shot(name, clipSel) {
  await setDarkMode(false);
  await sleep(300);
  const light = await captureRaw(clipSel);

  await setDarkMode(true);
  await sleep(300);
  const dark = await captureRaw(clipSel);

  await setDarkMode(false); // leave the page in light mode for whatever runs next

  const lightFile = join(OUT, `.${name}.light.png`);
  const darkFile = join(OUT, `.${name}.dark.png`);
  const newFile = join(OUT, `.${name}.new.png`);
  writeFileSync(lightFile, Buffer.from(light.data, 'base64'));
  writeFileSync(darkFile, Buffer.from(dark.data, 'base64'));

  // Quantize to a 256-color palette (like TinyPNG) while stitching -- these
  // are flat-color UI screenshots, not photos, so this is a ~65-75% size
  // cut with no visible loss (checked against the Sankey diagram's gradient
  // bands specifically, the case most likely to show banding: none seen).
  execFileSync('convert', [lightFile, darkFile, '+append', '+dither', '-colors', '256', '-strip', newFile]);
  unlinkSync(lightFile);
  unlinkSync(darkFile);

  console.log('  captured', name, `${Math.round(light.width)}x${Math.round(light.height)} (light+dark, stitched)`);
  commitShot(name, newFile);
}

// go clicks a nav element (via __clickAttr) and settles -- SSE-morphed
// content needs a beat to render (ECharts re-init, table re-fetch),
// same reasoning as shot()'s color-scheme settle.
async function go(clickSub, { settle = 700 } = {}) {
  const clicked = await evaluate(`__clickAttr(${JSON.stringify(clickSub)})`);
  if (!clicked) throw new Error('nav element not found for: ' + clickSub);
  await sleep(settle);
}

// selectYearRange clicks the "Year" quick-range preset. A freshly started dev
// stack with no live traffic generator has nothing in the last 24h (the
// default range) but may well have real historical nfcapd data further
// back -- Year reaches a full year behind "now", which is enough to surface
// it for user-guide shots that need to show actual flow rows/IPs rather than
// an empty table.
async function selectYearRange() {
  const clicked = await evaluate(`__clickText('Year', 'button')`);
  if (!clicked) throw new Error('Year preset button not found');
  await sleep(900);
}

// Flows/Statistics/Sankey don't auto-run their nfdump query on filter/date
// change like Graphs does -- running nfdump is comparatively expensive, so
// it's opt-in via each tab's own "Process data" button (see flow-filters.html.twig
// et al). Click it and wait for the spinner to clear before shooting, or the
// screenshot just shows whatever the tab last had (typically nothing).
async function processData({ timeout = 20000 } = {}) {
  const clicked = await evaluate(`__clickText('Process data', 'button')`);
  if (!clicked) throw new Error('"Process data" button not found');
  // The spinner element is always in the DOM (Datastar toggles display:none
  // via data-show, never removes it) -- check visibility, not existence.
  // Wait for it to APPEAR first: there's a beat between click() and the
  // fetch actually starting, and a query that finishes fast enough (or an
  // indicator that hasn't flipped yet) can otherwise look indistinguishable
  // from "already done", letting the shot fire mid-query.
  const isVisible = `(function(){var s=document.querySelector('.spinner-grow');return !!s&&s.offsetParent!==null;})()`;
  await waitJs(isVisible, { timeout: 5000, label: 'Process data query to start' }).catch(() => {});
  await waitJs(`!(${isVisible})`, { timeout, label: 'Process data query to finish' });
  await sleep(300);
}

async function main() {
  console.log('connecting to Chrome...');
  await connect(await wsEndpoint());
  await send('Page.enable');
  await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: DPR, mobile: false });

  console.log('loading', BASE);
  await navigate(BASE + '/');
  await evaluate(HELPERS);
  await sleep(800); // initial SSE connect + first graph render

  // Select a full year: a freshly started dev stack's live nfcapd files are
  // ~empty (no traffic generator running), but real historical captures may
  // exist further back on disk -- Year reaches far enough to surface them,
  // which makes every data-bearing shot below (tables, IP links, charts)
  // show something real instead of an empty state. Harmless if there's
  // truly nothing to find; the tabs just render their empty state instead.
  console.log('selecting Year date range');
  await selectYearRange();

  // ---- 00: Graphs -- the default landing view ----
  console.log('shot 00 graphs');
  await shot('00-page-graphs');

  // ---- guide: the shared control bar (date range + the active tab's filter
  //      panel), cropped -- a detail shot for the user guide, not the full page.
  console.log('shot guide-controls-bar');
  await shot('guide-controls-bar', '.filter.row .card');

  // ---- 01: Flows -- the flow table browser ----
  console.log('shot 01 flows');
  await go(`_currentView = 'flows'`);
  // Cap the result count before running the query -- real historical data
  // (via the Year range above) can mean hundreds of rows, which makes for
  // an unreasonably tall documentation screenshot.
  await evaluate(`__setSelect('#filterFlowsLimit select', 20)`);
  await processData(); // Flows doesn't auto-query on date/filter change -- see processData()'s doc comment
  await shot('01-page-flows');

  console.log('shot guide-flows-aggregation');
  await shot('guide-flows-aggregation', '#filterFlowAggregation');

  // ---- 02: Statistics -- top-N talkers/ports/protocols ----
  console.log('shot 02 statistics');
  await go(`_currentView = 'statistics'`);
  await processData();
  await shot('02-page-statistics');

  // ---- 03: Sankey -- flow-volume diagram (in development, see #152) ----
  console.log('shot 03 sankey');
  await go(`_currentView = 'sankey'`);
  await processData();
  await shot('03-page-sankey');

  // ---- guide: Sankey with the optional ports column enabled (#152 follow-up)
  //      -- flip the "Show dst port" switch and re-run so the diagram gains the
  //      middle src IP -> dst port -> dst IP column, then shoot the richer view.
  console.log('shot guide-sankey-ports');
  await evaluate(`__setCheckbox('sankeyShowPorts', true)`);
  await processData();
  await shot('guide-sankey-ports');
  await evaluate(`__setCheckbox('sankeyShowPorts', false)`); // restore default for any later re-render

  // ---- Settings sub-sections (all under $_currentView === 'settings') ----
  console.log('nav to settings');
  await go(`_currentView = 'settings'`);

  console.log('shot 04 settings/preferences');
  await go(`_settingsSection = 'preferences'`, { settle: 400 });
  await shot('04-settings-preferences');

  console.log('shot 05 settings/health');
  await go(`_settingsSection = 'health'`, { settle: 400 });
  await shot('05-settings-health');

  console.log('shot guide-health-nfdump');
  await shot('guide-health-nfdump', `__groupRect('nfdump')`);

  console.log('shot 06 settings/alerts');
  await go(`_settingsSection = 'alerts'`, { settle: 400 });
  await shot('06-settings-alerts');

  console.log('shot guide-alerts-traffic-filter');
  await shot('guide-alerts-traffic-filter', `__afterHeading('Traffic filter')`);

  // Notification templates (issue #153 follow-up): a global default card
  // (collapsed by default, above the rule list) plus a per-rule override
  // section nested under each of the Email/Webhook fields in the rule form
  // (also collapsed by default) -- expand each via its own toggle button
  // before shooting, same reasoning as processData()'s spinner wait: the
  // interesting content is hidden until a real user click reveals it.
  console.log('shot guide-alerts-default-templates');
  await evaluate(`__clickText('Customize', 'button')`); // global card's own toggle (its label has no "email"/"webhook" suffix)
  await sleep(300);
  await shot('guide-alerts-default-templates', '#alert-default-templates-card');

  console.log('shot guide-alerts-template-override');
  await evaluate(`__clickText('Customize webhook message', 'button')`);
  await sleep(300);
  await shot('guide-alerts-template-override', '#alert-form-webhook-template');

  console.log('shot 07 settings/import');
  await go(`_settingsSection = 'import'`, { settle: 400 });
  await shot('07-settings-import');

  console.log('shot 08 settings/system');
  await go(`_settingsSection = 'system'`, { settle: 400 });
  await shot('08-settings-system');

  // ---- IP info modal -- best-effort: needs at least one flow row with a
  //      clickable IP cell (rendered as <a class="ip-link">, see
  //      TableFormatter.php). The Year range selected above should surface
  //      real rows if any historical data exists; skip gracefully rather
  //      than fail the whole run if this specific instance has none.
  console.log('shot guide-ip-info-modal (best-effort)');
  try {
    await go(`_currentView = 'flows'`);
    await sleep(300);
    const openedModal = await evaluate(`(() => {
      var a = document.querySelector('.ip-link');
      if (!a) return false;
      a.click();
      return true;
    })()`);
    if (!openedModal) throw new Error('no .ip-link found in the flow table');
    await waitJs(`document.getElementById('ip-modal-inner') && document.getElementById('ip-modal-inner').open`, { label: 'ip-info modal open' });
    await sleep(300);
    await shot('guide-ip-info-modal', '#ip-modal-inner');
  } catch (e) {
    console.warn('  skipped guide-ip-info-modal:', e.message);
  }

  console.log('DONE');
}

main()
  .then(() => { printSummary(); chrome.kill(); process.exit(0); })
  .catch((e) => { console.error(e); chrome.kill(); process.exit(1); });
