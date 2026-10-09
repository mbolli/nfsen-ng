// Conversations (4.4): one run shown as a Sankey, a Matrix and ranked IP pairs; switching views
// posts nothing; Kill cancels a run and keeps the result; a filter applied from the drawer marks
// the result stale; group by /24 labels subnets; Both is disabled for the port grouping; a second
// run replaces the charts; the Export popover, the keyboard walk and a forced-colors screenshot.
// Needs flows in the last year of the dev stack.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const payload = `JSON.parse(document.querySelector('#convPanel-sankey nfsen-sankey')?.dataset.conversation ?? 'null')`;
const visible = (selector) =>
    `(function(){ var e = document.querySelector(${JSON.stringify(selector)}); return !!e && e.getClientRects().length > 0; })()`;
const formatModule = `import('nfsen/format')`;
const hostId = `document.querySelector('#convPanel-sankey .result-host')?.id ?? ''`;
const themeText = `import('nfsen/theme-colors').then(function(m){ return m.chartTheme().text; })`;
const sankeyNames = `document.querySelector('nfsen-sankey').chart.getOption().series[0].data.map(function(n){ return n.name; })`;
const sankeyLabelColour = `document.querySelector('nfsen-sankey').chart.getOption().series[0].label.color`;
const matrixLabelColour = `document.querySelector('nfsen-matrix').chart.getOption().yAxis[0].axisLabel.color`;

/** The chart of `tag` is drawn at its canvas's current width. */
const fits = (tag) => `(function(){
    var host = document.querySelector('#conversationsResults ${tag}');
    var canvas = host && host.querySelector('.${tag.replace('nfsen-', '')}-canvas');
    var chart = host && host.chart;
    return !!chart && canvas.clientWidth > 0 && Math.abs(chart.getWidth() - canvas.clientWidth) <= 1;
})()`;
const canvasWidth = (tag) => `document.querySelector('#conversationsResults .${tag.replace('nfsen-', '')}-canvas').clientWidth`;

/** The Sankey chart's own tooltip for a 1 MB link, at the result's length and the host's unit. */
const chartRate = `document.querySelector('nfsen-sankey').chart.getOption().tooltip[0].formatter({ dataType: 'edge', data: { source: 'a', target: 'b', bytes: 1000000, packets: 1, flows: 1 } })`;
const RATE_UNIT = { bytes: /\d (?:[KMGTP]i)?B\/s on average/, bits: /\d [kMGTP]?b\/s on average/ };

/** Saves the PNG of the chart at `selector` and returns its file name and the start of its data URL. */
const png = (selector) => `(function(){
    var before = window.__downloads.length;
    document.querySelector(${JSON.stringify(selector)}).downloadPng();
    var d = window.__downloads[before];
    return d ? d.download + '|' + d.href.slice(0, 22) : null;
})()`;
const PNG_URL = 'data:image/png;base64,';

/**
 * Texts drawn by a Matrix: legend steps that touch an axis label or the axis name, and texts
 * outside the chart, are reported in `bad`.
 */
const matrixOverlaps = (selector) => `(function(){
    var chart = document.querySelector(${JSON.stringify(selector)}).chart;
    var zr = chart.getZr();
    var steps = new Set(chart.getOption().visualMap[0].pieces.map(function(p){ return p.label; }));
    var texts = zr.storage.getDisplayList(true).filter(function(el){ return el.style && typeof el.style.text === 'string' && el.style.text !== '' && !el.invisible && !el.ignore; }).map(function(el){
        var r = el.getBoundingRect().clone();
        r.applyTransform(el.getComputedTransform());
        return { text: el.style.text, step: steps.has(el.style.text), r: r };
    });
    var bad = [];
    texts.forEach(function(a){
        if (a.r.x < -1 || a.r.y < -1 || a.r.x + a.r.width > zr.getWidth() + 1 || a.r.y + a.r.height > zr.getHeight() + 1) bad.push('outside: ' + a.text);
        if (!a.step) return;
        texts.forEach(function(b){ if (!b.step && a.r.intersect(b.r)) bad.push(a.text + ' on ' + b.text); });
    });
    return { bad: bad, legend: texts.filter(function(t){ return t.step; }).length, labels: texts.filter(function(t){ return !t.step; }).map(function(t){ return t.text; }) };
})()`;

/** Thirty pairs between the hosts of two IPv6 networks. */
function ipv6Payload() {
    const pairs = Array.from({ length: 30 }, (_, i) => {
        const bytes = Math.round(1e9 / (i + 1) ** 1.5);
        return {
            rank: i + 1,
            src: `2a02:1210:5e0c:f300:1c3d:82ff:fe4b:${(0x100 + (i % 12)).toString(16)}`,
            dst: `2001:db8:abcd:12:34:56:78:${(0x200 + ((i * 7) % 15)).toString(16)}`,
            port: null,
            bytes,
            packets: Math.round(bytes / 900),
            flows: 10 + i,
            share: null,
            reverse: null,
            series: i < 8 ? i + 1 : null,
        };
    });
    return {
        meta: { metric: 'bytes', groupBy: 'ip', direction: 'forward', topN: 30, approximate: false, command: '' },
        totals: null,
        pairs,
        others: null,
    };
}

/** Opens the filter drawer for Conversations, writes `text` and presses Apply. */
async function applyFromDrawer(page, text) {
    await page.evaluate(
        `(function(){ var b = document.querySelector('[data-filter-field="conversations"] [data-open-drawer="builder"]'); b.focus(); b.click(); })()`
    );
    await page.waitFor(
        `document.getElementById('filter-drawer').open && document.getElementById('drawerTitle').textContent.includes('for ') && !!document.getElementById('drawerFilterTextarea')`,
        {
            timeout: 10000,
            label: 'the drawer for Conversations',
        }
    );
    await page.evaluate(
        `(function(){ var t = document.getElementById('drawerFilterTextarea'); t.value = ${JSON.stringify(text)}; t.dispatchEvent(new Event('input', {bubbles: true})); })()`
    );
    await sleep(200);
    await page.evaluate(`document.getElementById('drawerApply').click()`);
    await page.waitFor(`!document.getElementById('filter-drawer').open`, { label: 'the drawer to close' });
    await page.waitFor(`document.getElementById('filterNfdumpTextareaSankey').value === ${JSON.stringify(text)}`, {
        label: 'the applied filter',
    });
}

async function key(page, name, code = name, keyCode = 0) {
    // Enter activates a button only with its text on the key down.
    const text = name === 'Enter' ? '\r' : undefined;
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', key: name, code, windowsVirtualKeyCode: keyCode, text });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key: name, code, windowsVirtualKeyCode: keyCode });
}

/** A mouse click on the middle of `selector`, so the press goes through sb-popover's outside-press listener. */
async function press(page, selector) {
    const at = await page.evaluate(`(function(){
        var el = document.querySelector(${JSON.stringify(selector)});
        var r = el.getBoundingClientRect();
        var x = r.left + r.width / 2, y = r.top + r.height / 2;
        return { x: x, y: y, hit: el.contains(document.elementFromPoint(x, y)) };
    })()`);
    assert.ok(at.hit, `${selector} is on top at ${at.x}, ${at.y}`);
    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x: at.x, y: at.y, button: 'left', clickCount: 1 });
    }
}

/** Clicks node `index` of the shown Sankey with the mouse, so the chart's own click handler runs; returns its name. */
async function clickNode(page, index) {
    const node = `(function(){
        var host = document.querySelector('#convPanel-sankey nfsen-sankey');
        var series = host.chart.getModel().getSeriesByIndex(0);
        var item = series.getData().getItemLayout(${index});
        var box = host.querySelector('.sankey-canvas').getBoundingClientRect();
        var x = box.left + series.layoutInfo.x + item.x + item.dx / 2;
        var y = box.top + series.layoutInfo.y + item.y + item.dy / 2;
        return { name: series.getData().getName(${index}), x: x, y: y, hit: host.contains(document.elementFromPoint(x, y)) };
    })()`;
    await page.waitFor(`${visible('#convPanel-sankey')} && ${fits('nfsen-sankey')}`, { label: 'the Sankey drawn' });
    const before = await page.evaluate(node);
    await page.evaluate(`window.scrollBy({ top: ${before.y} - innerHeight / 2, behavior: 'instant' })`);
    const point = await page.evaluate(node);
    assert.ok(point.hit, `node ${index} (${point.name}) is on screen at ${point.x}, ${point.y}`);
    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x: point.x, y: point.y, button: 'left', clickCount: 1 });
    }
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 0, y: 0 });
    return point.name;
}

async function choose(page, selector, value) {
    await page.setSelectValue(selector, value);
    await sleep(300);
}

async function show(page, view) {
    await page.evaluate(`document.getElementById('convView-${view}').click()`);
    await page.waitFor(visible(`#convPanel-${view}`), { label: `the ${view} view` });
}

/** Runs `check(mode)` in the stack's theme, in the other theme and in forced colours, each once the Sankey is drawn in it. */
async function inEachMode(page, check) {
    const before = await page.evaluate(`({ choice: window.__nfsenTheme.choice ?? null, dark: window.__nfsenTheme.dark })`);
    const [stack, other] = before.dark ? ['dark', 'light'] : ['light', 'dark'];
    const drawnIn = async (mode) => {
        await show(page, 'sankey');
        const text = await page.evaluate(themeText);
        await page.waitFor(`${sankeyLabelColour} === ${JSON.stringify(text)}`, { label: `the Sankey in ${mode}` });
    };
    await drawnIn(stack);
    await check(stack);
    await page.evaluate(`window.__nfsenTheme.choose('${other}')`);
    try {
        await page.waitFor(`document.documentElement.dataset.theme === '${other}'`, { label: `the ${other} theme` });
        await drawnIn(other);
        await check(other);
    } finally {
        await page.evaluate(`window.__nfsenTheme.choose(${JSON.stringify(before.choice)})`);
    }
    await page.withForcedColors(async () => {
        await drawnIn('forced colours');
        await check('forced colours');
    });
    await drawnIn(stack);
}

/** A width change while both charts are hidden reaches each once its tab shows it. */
async function resizeOnTabSwitch(page, narrow, mode) {
    for (const [width, compare] of [
        [`${narrow}px`, `< ${narrow}`],
        ['', `> ${narrow}`],
    ]) {
        await show(page, 'pairs');
        await page.evaluate(`document.querySelector('.conversations').style.maxInlineSize = '${width}'`);
        for (const view of ['sankey', 'matrix']) {
            await show(page, view);
            await page.waitFor(`${fits(`nfsen-${view}`)} && ${canvasWidth(`nfsen-${view}`)} ${compare}`, {
                label: `the ${view} ${width ? 'narrower' : 'at full width'} in ${mode}`,
            });
        }
    }
}

/** Three pairs from `n.0.0.x`, every payload the same size, so a redraw keeps the chart's height. */
const smallPayload = (n) =>
    JSON.stringify({
        meta: { metric: 'bytes', groupBy: 'ip', direction: 'forward', topN: 3, approximate: false, command: '' },
        totals: null,
        pairs: [1, 2, 3].map((i) => ({
            rank: i,
            src: `${n}.0.0.${i}`,
            dst: `192.0.2.${i}`,
            port: null,
            bytes: 4000 - i * 1000,
            packets: 4 - i,
            flows: 1,
            share: null,
            reverse: null,
            series: i,
        })),
        others: null,
    });
const drawnSources = {
    'nfsen-sankey': `chart.getOption().series[0].data.filter(function(n){ return n.kind === 'source'; })
        .map(function(n){ return n.text; })`,
    'nfsen-matrix': `chart.getOption().yAxis[0].data`,
};

/**
 * On a live `tag` host, two data-conversation writes in one task give one redraw after the task, with the
 * second payload (K15); a bad payload shows the message and a good one draws again.
 */
async function propBurst(page, tag) {
    const view = tag.replace('nfsen-', '');
    const probe = `document.querySelector('#convBurst ${tag}')`;
    await show(page, view);
    await page.evaluate(`(function(){
        var box = document.createElement('div');
        box.id = 'convBurst';
        box.innerHTML = '<${tag}><div class="${view}-container"><div class="${view}-canvas"></div></div></${tag}>';
        box.firstChild.dataset.conversation = ${JSON.stringify(smallPayload(1))};
        document.getElementById('convPanel-${view}').append(box);
    })()`);
    try {
        await page.waitFor(`!!${probe}?.chart`, { label: `the ${tag} fixture drawn` });
        const burst = await page.evaluate(`(async function(){
            var host = ${probe}, chart = host.chart, calls = 0, setOption = chart.setOption;
            chart.setOption = function(){ calls++; return setOption.apply(this, arguments); };
            host.dataset.conversation = ${JSON.stringify(smallPayload(2))};
            host.dataset.conversation = ${JSON.stringify(smallPayload(3))};
            var inTask = calls;
            // Queued after the observer's microtask; a ResizeObserver draw would wait for a frame.
            await null;
            var afterMicrotask = calls;
            await new Promise(function(r){ requestAnimationFrame(function(){ requestAnimationFrame(r); }); });
            var sources = ${drawnSources[tag]};
            return { inTask: inTask, afterMicrotask: afterMicrotask, calls: calls, sameChart: host.chart === chart, sources: sources };
        })()`);
        assert.deepEqual(
            burst,
            { inTask: 0, afterMicrotask: 1, calls: 1, sameChart: true, sources: ['3.0.0.1', '3.0.0.2', '3.0.0.3'] },
            `${tag}: one redraw after a burst of prop writes, with the last payload`
        );
        await page.evaluate(`${probe}.dataset.conversation = 'not json'`);
        await page.waitFor(
            `${probe}.chart === null && ${probe}.querySelector('.conv-chart-message')?.textContent === 'No conversations in this result.'`,
            { label: `${tag}: the message for a bad payload` }
        );
        await page.evaluate(`${probe}.dataset.conversation = ${JSON.stringify(smallPayload(4))}`);
        await page.waitFor(`!!${probe}.chart && !${probe}.querySelector('.conv-chart-message')`, {
            label: `${tag}: drawn again after a good payload`,
        });
    } finally {
        await page.evaluate(`document.getElementById('convBurst')?.remove()`);
    }
}

/** page.runQuery waits 8 s for the Run button, which stays disabled while any query of the tab runs or waits for a slot. */
async function runWhenReady(page) {
    await page.waitFor(`!!document.querySelector('button[data-run="conversations"]:not(:disabled)')`, {
        timeout: 30000,
        label: 'run button for conversations',
    });
    await page.runQuery('conversations', { timeout: 30000 });
}

async function run(page) {
    const before = await page.evaluate(hostId);
    await runWhenReady(page);
    await page.waitFor(`(${hostId}) !== ${JSON.stringify(before)} || !!document.querySelector('#conversationsResults .empty-state')`, {
        label: 'a new result',
        timeout: 10000,
    });
    const message = await page.evaluate(`document.getElementById('conversationsMessage').textContent`);
    assert.match(message, /nfdump: done in/, `the run reports its command, got: ${message}`);
    assert.doesNotMatch(message, /error/i, `no error, got: ${message}`);
    return page.evaluate(payload);
}

export default async function conversationsTest() {
    await withPage(async (page) => {
        await page.navigate(`${BASE}/#/conversations`);
        await page.waitForBoot();
        await page.gotoPage('conversations');

        // Before any run: the empty state, and no view tabs.
        const empty = await page.evaluate(`document.querySelector('#conversationsResults .empty-state')?.textContent ?? ''`);
        if (!(await page.evaluate(`!!document.querySelector('#convView-sankey')`))) {
            assert.match(empty, /Choose how to group conversations and press Run\./);
        }

        // Keyboard: the query card's controls are reachable and the radios move with the arrows.
        await page.evaluate(`document.getElementById('convGroupBy').focus()`);
        await key(page, 'Tab', 'Tab', 9);
        assert.equal(await page.evaluate(`document.activeElement.name`), 'convDirection', 'Tab moves from Group by to Direction');
        await page.evaluate(`document.getElementById('convDirBoth').focus()`);
        await key(page, 'ArrowRight', 'ArrowRight', 39);
        await sleep(200);
        assert.equal(await page.signalValue('conv_direction'), 'forward', 'an arrow key picks the other direction');
        await key(page, 'ArrowLeft', 'ArrowLeft', 37);
        await sleep(200);
        assert.equal(await page.signalValue('conv_direction'), 'both', 'and back');

        await page.setRangePreset('1y');
        const log = await page.requestLog();

        // ── IP address, both directions ───────────────────────────────────────
        await choose(page, '#convGroupBy', 'ip');
        const first = await run(page);
        assert.equal(log.count('conversations-run'), 1, 'one run posted');
        assert.ok(first?.pairs.length, 'the dev stack has flows in the last year, so the run returns pairs');
        console.log(`  (conversations: ${first.pairs.length} pairs, direction ${first.meta.direction})`);
        assert.equal(first.meta.groupBy, 'ip');
        assert.equal(first.meta.direction, 'both');
        assert.ok(
            first.pairs.every((p) => typeof p.src === 'string' && typeof p.dst === 'string' && p.src !== p.dst),
            'every pair names two different ends'
        );
        assert.deepEqual(
            first.pairs.map((p) => p.rank),
            first.pairs.map((_, i) => i + 1),
            'ranked 1..n'
        );
        assert.match(
            await page.evaluate(`document.getElementById('convSummary').textContent.trim()`),
            /^Top (pair|\d[\d,]* pairs) (=|by) /,
            'the summary line'
        );
        // The query control announces the pair count.
        await page.waitFor(
            `/pairs? returned\\. Done in/.test(document.querySelector('#conversationsRun [role=status][data-ignore-morph]')?.textContent ?? '')`,
            { label: 'the completion announcement' }
        );
        // A large read runs as time slices in parallel nfdump processes (PERF-SPEC P4), and says how many.
        const done = await page.evaluate(`document.querySelector('#conversationsRun .query-progress-line > span').textContent.trim()`);
        assert.match(done, /^Done in [\d.]+s(?: with \d+ nfdump processes)?\.$/, `the outcome names the processes of a split, got: ${done}`);
        console.log(`  (conversations: ${done.includes('processes') ? done.replace(/^Done in [\d.]+s with /, 'split into ').replace(/\.$/, '') : 'one nfdump process'})`);

        const sankey = await page.evaluate(`(function(){
            var o = document.querySelector('nfsen-sankey').chart.getOption();
            return { type: o.series[0].type, nodes: o.series[0].data.length, names: o.series[0].data.map(function(n){ return n.name; }),
                     others: o.series[0].data.filter(function(n){ return n.others; }).map(function(n){ return n.text; }) };
        })()`);
        assert.equal(sankey.type, 'sankey');
        const ends = new Set(first.pairs.flatMap((p) => [`src:${p.src}`, `dst:${p.dst}`]));
        const hasOthers = (first.others?.[first.meta.metric] ?? 0) > 0;
        assert.equal(sankey.nodes, ends.size + (hasOthers ? 2 : 0), 'one node per source and per destination, and Others in each column');
        assert.deepEqual(
            sankey.others,
            hasOthers ? ['Others', 'Others'] : [],
            'the traffic outside the top N is an Others node on both sides'
        );
        assert.ok(
            sankey.names.every((n) => n.startsWith('src:') || n.startsWith('dst:')),
            'two columns without the port grouping'
        );
        assert.equal(
            await page.evaluate(
                `document.querySelector('nfsen-sankey').getAttribute('role') + '|' + document.querySelector('nfsen-matrix').getAttribute('role')`
            ),
            'img|img',
            'both charts are images with a name'
        );
        assert.match(
            await page.evaluate(`document.querySelector('nfsen-sankey').getAttribute('aria-label')`),
            /^Sankey of the top .* The IP pairs view lists the same pairs as a table\.$/
        );

        // Both charts are Rocket hosts whose shadow root only slots the light DOM (shape A).
        const hosts = await page.evaluate(`['nfsen-sankey', 'nfsen-matrix'].map(function(tag){
            var el = document.querySelector('#conversationsResults ' + tag);
            return { tag: tag, id: el.rocketInstanceId, shadow: el.shadowRoot ? [...el.shadowRoot.childNodes].map(function(n){ return n.nodeName; }).join() : null,
                     json: JSON.stringify({ chart: el }) };
        })`);
        for (const host of hosts) {
            assert.ok(typeof host.id === 'string' && host.id !== '', `${host.tag} is a Rocket host, got ${host.id}`);
            assert.equal(host.shadow, 'SLOT', `${host.tag}'s shadow root holds only its slot`);
            assert.equal(host.json, `{"chart":"${host.tag.toUpperCase()}"}`, `${host.tag} serialises to its tag name`);
        }

        // A move keeps the chart: moveBefore is atomic, insertBefore disconnects and connects again (K14).
        const moves = await page.evaluate(`(async function(){
            var host = document.querySelector('#convPanel-sankey nfsen-sankey');
            var chart = host.chart;
            var canvas = host.querySelector('.sankey-canvas canvas');
            var parent = host.parentNode;
            var out = [];
            for (var how of ['moveBefore', 'insertBefore']) {
                if (typeof parent[how] !== 'function') continue;
                parent[how](host, host.nextSibling);
                await new Promise(function(r){ setTimeout(r, 200); });
                out.push({ how: how, chart: host.chart === chart && !chart.isDisposed(), canvas: host.querySelector('.sankey-canvas canvas') === canvas,
                           shadow: host.shadowRoot.childNodes.length });
            }
            return out;
        })()`);
        assert.ok(
            moves.some((m) => m.how === 'insertBefore'),
            'the reconnect case ran'
        );
        for (const move of moves) {
            assert.deepEqual(
                { chart: move.chart, canvas: move.canvas, shadow: move.shadow },
                { chart: true, canvas: true, shadow: 1 },
                `after ${move.how} the Sankey keeps its chart and canvas`
            );
        }

        const wide = await page.evaluate(canvasWidth('nfsen-sankey'));

        // Downloads and copies are recorded from here on instead of saved.
        await page.evaluate(`(function(){
            window.__downloads = [];
            HTMLAnchorElement.prototype.click = function(){
                var entry = { download: this.download, href: this.href, text: null };
                window.__downloads.push(entry);
                if (this.href.startsWith('blob:')) fetch(this.href).then(function(r){ return r.text(); }).then(function(t){ entry.text = t; });
            };
            document.execCommand = function(){ window.__copied = document.activeElement && document.activeElement.value; return true; };
        })()`);
        assert.equal(
            await page.evaluate(png('#convPanel-sankey nfsen-sankey')),
            `conversations-sankey.png|${PNG_URL}`,
            'the Sankey saves a PNG'
        );

        // Rates in the traffic graph's units, and long labels shortened from the middle.
        const rate = await page.evaluate(`(function(){
            var s = document.querySelector('nfsen-sankey');
            return { unit: s.dataset.unit, text: s.tooltip({ dataType: 'edge', data: { source: 'a', target: 'b', bytes: 1000000, packets: 1, flows: 1 } }, 'bytes', 8) };
        })()`);
        assert.match(
            rate.text,
            rate.unit === 'bytes' ? /\(122 KiB\/s on average\)/ : /\(1 Mb\/s on average\)/,
            `the tooltip rate, got ${rate.text}`
        );
        // A unit switch reaches both hosts' prop through data-attr, and the chart's tooltip follows it.
        const chartTip = await page.evaluate(chartRate);
        assert.match(chartTip, RATE_UNIT[rate.unit], `the chart tooltip in the graph unit, got ${chartTip}`);
        const unitSignal = await page.evaluate(
            `import('datastar').then(function(m){ return Object.keys(m.root).find(function(k){ return k.startsWith('graph_trafficUnit____'); }); })`
        );
        const setUnit = (value) =>
            page.evaluate(`import('datastar').then(function(m){ m.root[${JSON.stringify(unitSignal)}] = ${JSON.stringify(value)}; })`);
        const otherUnit = rate.unit === 'bytes' ? 'bits' : 'bytes';
        try {
            await setUnit(otherUnit);
            await page.waitFor(
                `['nfsen-sankey', 'nfsen-matrix'].every(function(tag){ return document.querySelector('#conversationsResults ' + tag).dataUnit === ${JSON.stringify(otherUnit)}; })`,
                { label: `the ${otherUnit} unit on both hosts` }
            );
            const switched = await page.evaluate(chartRate);
            assert.match(switched, RATE_UNIT[otherUnit], `the chart tooltip in ${otherUnit}, got ${switched}`);
        } finally {
            await setUnit(rate.unit);
        }
        await page.waitFor(`document.querySelector('nfsen-sankey').dataUnit === ${JSON.stringify(rate.unit)}`, {
            label: 'the unit restored',
        });
        assert.match(await page.evaluate(chartRate), RATE_UNIT[rate.unit], 'and back');
        await propBurst(page, 'nfsen-sankey');
        const fitted = await page.evaluate(
            `${formatModule}.then(function(m){ return m.fitLabel('2a02:1210:5e0c:f300:1c3d:82ff:fe4b:9a1', 110); })`
        );
        assert.ok(
            fitted.includes('…') && fitted.endsWith('9a1') && fitted.startsWith('2a02'),
            `an IPv6 label keeps its start and end, got ${fitted}`
        );

        // ── Views switch on the client ───────────────────────────────────────
        await page.evaluate(`document.getElementById('convView-matrix').click()`);
        await page.waitFor(visible('#convPanel-matrix'), { label: 'the Matrix view' });
        await page.waitFor(`!!document.querySelector('nfsen-matrix')?.chart`, { label: 'the Matrix chart' });
        const matrix = await page.evaluate(`(function(){
            var o = document.querySelector('nfsen-matrix').chart.getOption();
            return { types: o.series.map(function(s){ return s.type; }), rows: o.yAxis[0].data.length, columns: o.xAxis[0].data.length, pieces: o.visualMap[0].pieces.length };
        })()`);
        assert.deepEqual(matrix.types, ['heatmap', 'heatmap'], 'the heat cells and the hatched ones');
        assert.ok(matrix.rows > 0 && matrix.rows <= 25 && matrix.columns <= 25, 'at most 25 sources and destinations');
        assert.ok(matrix.pieces >= 1 && matrix.pieces <= 6, 'the neutral ramp in at most six steps');
        assert.equal(await page.evaluate(visible('#convPanel-sankey')), false, 'one view at a time');
        const pageOverlaps = await page.evaluate(matrixOverlaps('#convPanel-matrix nfsen-matrix'));
        assert.deepEqual(pageOverlaps.bad, [], 'the legend, the axis name and the labels do not overlap');
        assert.ok(pageOverlaps.legend >= 1, 'the legend is drawn');
        for (const width of [1200, 380]) {
            await page.evaluate(`(function(){
                var host = document.createElement('div');
                host.id = 'convProbe';
                host.style.inlineSize = '${width}px';
                host.innerHTML = '<nfsen-matrix><div class="matrix-container"><div class="matrix-canvas"></div></div></nfsen-matrix>';
                host.firstChild.dataset.conversation = ${JSON.stringify(JSON.stringify(ipv6Payload()))};
                document.getElementById('convPanel-matrix').append(host);
            })()`);
            await page.waitFor(`!!document.querySelector('#convProbe nfsen-matrix').chart`, { label: 'the IPv6 Matrix' });
            const probe = await page.evaluate(matrixOverlaps('#convProbe nfsen-matrix'));
            await page.evaluate(`document.getElementById('convProbe').remove()`);
            assert.deepEqual(probe.bad, [], `IPv6 labels at ${width} px stay clear of the legend and inside the chart`);
            assert.ok(
                probe.labels.some((l) => l.includes('…')),
                `long IPv6 labels are shortened, got ${probe.labels.slice(0, 3).join(', ')}`
            );
            assert.equal(
                probe.labels.filter((l) => l.startsWith('2001:')).length,
                new Set(probe.labels.filter((l) => l.startsWith('2001:'))).size,
                'and stay distinct'
            );
            assert.equal(probe.legend, 6, 'every step of the ramp is in the legend');
        }
        await propBurst(page, 'nfsen-matrix');

        assert.equal(
            await page.evaluate(png('#convPanel-matrix nfsen-matrix')),
            `conversations-matrix.png|${PNG_URL}`,
            'the Matrix saves a PNG'
        );

        // A theme switch redraws the shown Sankey, and reaches the hidden Matrix once it is shown again.
        const themeBefore = await page.evaluate(`({ choice: window.__nfsenTheme.choice ?? null, dark: window.__nfsenTheme.dark })`);
        const shownColour = await page.evaluate(matrixLabelColour);
        await page.evaluate(`document.getElementById('convView-sankey').click()`);
        await page.waitFor(visible('#convPanel-sankey'), { label: 'the Sankey view' });
        await page.evaluate(`window.__nfsenTheme.choose(${JSON.stringify(themeBefore.dark ? 'light' : 'dark')})`);
        await page.waitFor(`document.documentElement.dataset.theme === ${JSON.stringify(themeBefore.dark ? 'light' : 'dark')}`, {
            label: 'the other theme',
        });
        const otherText = await page.evaluate(themeText);
        assert.notEqual(otherText, shownColour, 'the two themes have different text colours');
        await page.waitFor(`${sankeyLabelColour} === ${JSON.stringify(otherText)}`, { label: 'the Sankey in the new theme' });
        assert.equal(await page.evaluate(png('#convPanel-sankey nfsen-sankey')), `conversations-sankey.png|${PNG_URL}`, 'and its PNG');
        await sleep(200);
        assert.equal(await page.evaluate(matrixLabelColour), shownColour, 'the hidden Matrix has not been drawn again');
        await page.evaluate(`document.getElementById('convView-matrix').click()`);
        await page.waitFor(`${matrixLabelColour} === ${JSON.stringify(otherText)}`, { label: 'the Matrix in the new theme' });
        assert.equal(await page.evaluate(png('#convPanel-matrix nfsen-matrix')), `conversations-matrix.png|${PNG_URL}`, 'and its PNG');
        await page.evaluate(`window.__nfsenTheme.choose(${JSON.stringify(themeBefore.choice)})`);
        await page.waitFor(`${matrixLabelColour} === ${JSON.stringify(shownColour)}`, { label: 'the Matrix back in the first theme' });

        // Arrow keys move between the view tabs (automatic activation).
        await page.evaluate(`document.getElementById('convView-matrix').focus()`);
        await key(page, 'ArrowRight', 'ArrowRight', 39);
        await page.waitFor(visible('#convPanel-pairs'), { label: 'the IP pairs view' });
        assert.equal(await page.evaluate(`document.activeElement.id`), 'convView-pairs');
        const rows = await page.evaluate(`document.querySelectorAll('#conversationsTable tbody tr').length`);
        assert.ok(rows >= Math.min(first.pairs.length, 25), `the IP pairs table lists the pairs (${rows} rows)`);
        const share = await page.evaluate(`(function(){
            var th = document.querySelector('#conversationsTable th[data-original-title="share_pct"]');
            var index = th ? Array.prototype.indexOf.call(th.parentNode.children, th) : -1;
            var td = index < 0 ? null : document.querySelector('#conversationsTable tbody tr').children[index];
            return { num: !!th && th.hasAttribute('data-num'), cell: td ? { num: td.hasAttribute('data-num'), raw: td.dataset.raw ?? null, text: td.textContent } : null };
        })()`);
        if (first.pairs[0].share !== null) {
            assert.ok(share.num && share.cell?.num, 'the share column is numeric');
            assert.ok(Math.abs(Number(share.cell.raw) - first.pairs[0].share) < 1e-5, `the share exports raw, got ${share.cell.raw}`);
            assert.match(share.cell.text, /^\d+\.\d\d%$/, 'and shows a percentage');
        }
        const bytesCell = await page.evaluate(
            `document.querySelector('#conversationsTable td[data-raw="${first.pairs[0].bytes}"]')?.textContent ?? ''`
        );
        const others = await page.evaluate(`document.getElementById('convOthers')?.textContent.trim() ?? ''`);
        if (others !== '') {
            const unit = (text) => text.match(/\b(B|KiB|MiB|GiB|TiB|PiB)\b/)?.[1] ?? null;
            assert.ok(
                unit(others) !== null && unit(bytesCell) !== null,
                `the footer prints bytes as the table does, got "${others}" and "${bytesCell}"`
            );
        }
        await key(page, 'Home', 'Home', 36);
        await page.waitFor(visible('#convPanel-sankey'), { label: 'Home back to the Sankey' });
        const narrow = 720;
        assert.ok(wide > narrow, `the page is wider than ${narrow} px (${wide})`);
        await inEachMode(page, (mode) => resizeOnTabSwitch(page, narrow, mode));
        // A scrollbar in the Sankey's container narrows the canvas but not the host; the chart follows the canvas.
        const sankeyContainer = `document.querySelector('#conversationsResults .sankey-container')`;
        const hostWidth = `document.querySelector('#conversationsResults nfsen-sankey').clientWidth`;
        const unscrolled = await page.evaluate(canvasWidth('nfsen-sankey'));
        await page.evaluate(`${sankeyContainer}.style.maxBlockSize = '200px'`);
        await page.waitFor(`${fits('nfsen-sankey')} && ${canvasWidth('nfsen-sankey')} < ${hostWidth}`, {
            label: "the Sankey beside its container's scrollbar",
        });
        await page.evaluate(`${sankeyContainer}.style.maxBlockSize = ''`);
        await page.waitFor(`${fits('nfsen-sankey')} && ${canvasWidth('nfsen-sankey')} === ${unscrolled}`, {
            label: 'the Sankey at its width before',
        });
        assert.equal(log.count('conversations-run'), 1, 'switching views posts no run');

        // ── Export popover ───────────────────────────────────────────────────
        const exportState = `(function(){
            var host = document.getElementById('convExport'), trigger = document.getElementById('convExportToggle');
            return { open: host.open, expanded: trigger.getAttribute('aria-expanded'), active: document.activeElement.id || document.activeElement.dataset.export || document.activeElement.localName };
        })()`;
        const closedOnTrigger = { open: false, expanded: 'false', active: 'convExportToggle' };
        assert.deepEqual(
            await page.evaluate(`(function(){
                var host = document.getElementById('convExport');
                var trigger = document.getElementById('convExportToggle');
                return { popover: host.localName, rocket: typeof host.rocketInstanceId === 'string', slotted: host.shadowRoot.querySelector('slot[name=trigger]').assignedElements().length,
                         trigger: trigger.getAttribute('slot') + '|' + trigger.getAttribute('aria-haspopup') + '|' + trigger.hasAttribute('aria-controls'),
                         items: [...document.querySelectorAll('#convExportMenu [data-export]')].map(function(b){ return b.dataset.export; }),
                         menuMarkup: document.querySelectorAll('#convExportMenu :is([role], [tabindex], [data-command])').length,
                         command: host.contains(document.getElementById('convCommand')) ? 'inside' : document.getElementById('convCommand').hidden ? 'hidden outside' : 'shown' };
            })()`),
            {
                popover: 'sb-popover',
                rocket: true,
                slotted: 1,
                trigger: 'trigger|dialog|false',
                items: ['csv', 'json', 'png', 'command'],
                menuMarkup: 0,
                command: 'hidden outside',
            },
            'Export is an sb-popover with the slotted trigger, four plain items and the command outside it'
        );
        await page.evaluate(`window.__downloads.length = 0`);
        await page.evaluate(`document.getElementById('convExportToggle').focus()`);
        await key(page, 'Enter', 'Enter', 13);
        await page.waitFor(`document.getElementById('convExport').open === true && document.activeElement.dataset.export === 'csv'`, {
            label: 'Enter to open Export on its first item',
        });
        assert.equal(await page.evaluate(`document.getElementById('convExportToggle').getAttribute('aria-expanded')`), 'true');
        await key(page, 'ArrowDown', 'ArrowDown', 40);
        assert.equal(await page.evaluate(`document.activeElement.dataset.export`), 'json', 'ArrowDown moves to JSON');
        // A sync morphs the page around the open popover.
        await page.syncNow('conversations');
        assert.deepEqual(
            await page.evaluate(exportState),
            { open: true, expanded: 'true', active: 'json' },
            'a sync keeps Export open with the focus on JSON'
        );
        await key(page, 'Escape', 'Escape', 27);
        assert.deepEqual(await page.evaluate(exportState), closedOnTrigger, 'Escape closes Export and returns to the trigger');
        await key(page, 'ArrowUp', 'ArrowUp', 38);
        await page.waitFor(`document.getElementById('convExport').open === true && document.activeElement.dataset.export === 'command'`, {
            label: 'ArrowUp to open Export on its last item',
        });
        await key(page, 'Home', 'Home', 36);
        assert.equal(await page.evaluate(`document.activeElement.dataset.export`), 'csv', 'Home moves to the first item');
        await key(page, 'Enter', 'Enter', 13);
        await page.waitFor(`window.__downloads.some(function(d){ return d.download === 'conversations-pairs.csv'; })`, {
            label: 'Enter on CSV to export',
        });
        assert.deepEqual(await page.evaluate(exportState), closedOnTrigger, 'choosing CSV closes Export and returns to the trigger');

        await page.evaluate(`window.__downloads.length = 0`);
        for (const kind of ['csv', 'json', 'png', 'command']) {
            await page.evaluate(`document.getElementById('convExportToggle').click()`);
            await page.waitFor(`document.getElementById('convExport').open === true`, { label: `Export open for ${kind}` });
            await page.evaluate(`document.querySelector('#convExportMenu [data-export="${kind}"]').click()`);
            await sleep(300);
            assert.deepEqual(await page.evaluate(exportState), closedOnTrigger, `${kind} closes Export and returns to the trigger`);
        }
        const downloads = await page.evaluate(`window.__downloads`);
        const byName = Object.fromEntries(downloads.map((d) => [d.download, d]));
        assert.ok(byName['conversations-pairs.csv']?.text?.includes(first.pairs[0].src), 'the CSV holds the pairs');
        assert.ok(byName['conversations-pairs.json']?.text?.startsWith('['), 'the JSON is a list');
        assert.ok(byName['conversations-sankey.png']?.href.startsWith(PNG_URL), 'the PNG of the current chart');
        assert.match((await page.evaluate(`window.__copied`)) ?? '', /nfdump .*-A/, 'Copy nfdump command copies the command');
        assert.equal(
            await page.evaluate(`window.__copied`),
            await page.evaluate(`document.getElementById('convCommand').textContent`),
            'the copy is the text of #convCommand'
        );
        const copyToast = `[...document.querySelectorAll('nfsen-toast')].some(function(t){ return t.message === 'Copied the nfdump command.'; })`;
        await page.waitFor(copyToast, { label: 'the copy toast' });
        // With the Matrix shown, the popover saves the Matrix.
        await page.evaluate(`document.getElementById('convView-matrix').click()`);
        await page.waitFor(`${visible('#convPanel-matrix')} && !!document.querySelector('nfsen-matrix').chart`, {
            label: 'the Matrix view',
        });
        await page.evaluate(`document.getElementById('convExportToggle').click()`);
        await page.waitFor(`document.querySelector('#convExportMenu [data-export="png"]').textContent === 'PNG of the Matrix'`, {
            label: 'the PNG item named for the Matrix',
        });
        await page.evaluate(`document.querySelector('#convExportMenu [data-export="png"]').click()`);
        await page.waitFor(
            `window.__downloads.some(function(d){ return d.download === 'conversations-matrix.png' && d.href.startsWith(${JSON.stringify(PNG_URL)}); })`,
            { label: 'the PNG of the Matrix from the popover' }
        );
        // The IP pairs view has no chart: the PNG item is disabled, a press saves nothing and Export stays open.
        await show(page, 'pairs');
        await page.evaluate(`window.__downloads.length = 0`);
        await page.evaluate(`document.getElementById('convExportToggle').click()`);
        await page.waitFor(
            `document.getElementById('convExport').open === true && document.querySelector('#convExportMenu [data-export="png"]').getAttribute('aria-disabled') === 'true'`,
            { label: 'Export open with the PNG item disabled' }
        );
        assert.equal(
            await page.evaluate(`document.querySelector('#convExportMenu [data-export="png"]').textContent`),
            'PNG of a chart (open Sankey or Matrix)'
        );
        await press(page, '#convExportMenu [data-export="png"]');
        await sleep(300);
        assert.deepEqual(
            await page.evaluate(`({ open: document.getElementById('convExport').open, downloads: window.__downloads.length })`),
            { open: true, downloads: 0 },
            'the disabled PNG item saves nothing and keeps Export open'
        );
        await key(page, 'Escape', 'Escape', 27);
        assert.deepEqual(await page.evaluate(exportState), closedOnTrigger, 'and Escape closes it');
        await page.evaluate(`document.getElementById('convView-sankey').click()`);
        await page.waitFor(visible('#convPanel-sankey'), { label: 'the Sankey view' });

        // A click on a source node opens the IP info of its address, through the onClick the reconnect above defined again.
        await inEachMode(page, async (mode) => {
            assert.equal(await clickNode(page, 0), `src:${first.pairs[0].src}`, `the first node is the top source in ${mode}`);
            await page.waitFor(`!!document.getElementById('ip-modal-inner')?.isOpen`, {
                label: `the IP info of a clicked node in ${mode}`,
                timeout: 40000,
            });
            assert.ok((await page.evaluate(`document.getElementById('ip-modal-inner').getAttribute('heading')`)).includes(first.pairs[0].src));
            await page.evaluate(`document.getElementById('ip-modal-inner').close()`);
        });

        // ── Kill: pressed as soon as the run shows, it ends cancelled and the result stays ──
        const kept = await page.evaluate(hostId);
        const keptNotices = await page.evaluate(`document.getElementById('conversationsMessage').textContent.trim()`);
        assert.match(keptNotices, /nfdump: done in/, 'the result to keep has its notices');
        await page.evaluate(`(function(){
            var control = document.getElementById('conversationsRun');
            window.__kill = null;
            var observer = new MutationObserver(function(){
                if (control.dataset.queryState !== 'running') return;
                observer.disconnect();
                var kill = control.querySelector('.kill');
                window.__kill = { visible: kill.getClientRects().length > 0, progress: control.querySelector('.query-progress').getClientRects().length > 0 };
                kill.click();
            });
            observer.observe(control, { attributes: true, attributeFilter: ['data-query-state'] });
        })()`);
        await runWhenReady(page);
        assert.deepEqual(await page.evaluate(`window.__kill`), { visible: true, progress: true }, 'the run showed Kill and its progress');
        assert.equal(await page.signalValue('query_kind'), 'conversations');
        await page.waitFor(`document.querySelector('#conversationsRun .query-progress-line').textContent.includes('Query cancelled.')`, {
            label: 'the cancelled status',
        });
        assert.equal(await page.evaluate(hostId), kept, 'a cancelled run keeps the previous result');
        const killed = await page.evaluate(`document.getElementById('conversationsMessage').textContent.trim()`);
        assert.ok(killed.endsWith(keptNotices), `the kept result keeps its notices, got: ${killed}`);
        // Kill before nfdump started leaves no process to name.
        const killNotice = killed.slice(0, killed.length - keptNotices.length).trim();
        assert.ok(
            killNotice === '' || /^nfdump process \(PID \d+\) was killed\./.test(killNotice),
            `only kill-nfdump's notice on top, got: ${killNotice}`
        );
        console.log(`  (kill: ${killNotice || 'landed before nfdump started'})`);
        assert.equal(await page.evaluate(visible('#convStale')), false, 'the kept result still matches the query');

        // ── A filter applied from the drawer marks the result stale, and undoing it clears that ──
        await applyFromDrawer(page, 'proto tcp');
        await page.waitFor(visible('#convStale'), { label: 'the stale notice after the drawer applied a filter' });
        await applyFromDrawer(page, '');
        await page.waitFor(`!${visible('#convStale')}`, { label: 'the stale notice gone with the filter undone' });
        assert.equal(log.count('conversations-run'), 2, 'the drawer ran nothing');

        // ── /24 subnets: stale first, then a new result replaces the charts ──
        const oldSankey = await page.evaluate(
            `(window.__oldSankey = document.querySelector('nfsen-sankey'), window.__oldMatrix = document.querySelector('nfsen-matrix'), window.__oldChart = window.__oldSankey.chart, true)`
        );
        assert.ok(oldSankey);
        await choose(page, '#convGroupBy', 'net24');
        await page.waitFor(visible('#convStale'), { label: 'the stale notice after an edit' });
        assert.ok(await page.evaluate(visible('.conv-subnet-note')), 'subnet grouping says it covers IPv4 only');
        const subnets = await run(page);
        assert.equal(subnets.meta.groupBy, 'net24');
        assert.ok(
            subnets.pairs.every((p) => p.src.endsWith('/24') && p.dst.endsWith('/24')),
            'group by /24 labels end in /24'
        );
        assert.equal(await page.evaluate(`window.__oldSankey.isConnected`), false, 'the second run replaced the Sankey');
        // A removed host drops its chart, its children and attributes, and leaves its old parent: it keeps next to nothing alive (K14).
        assert.deepEqual(
            await page.evaluate(`['__oldSankey', '__oldMatrix'].map(function(name){
                var host = window[name];
                var parts = [host.childNodes.length, host.shadowRoot.childNodes.length, host.attributes.length, String(host.chart),
                    String(host.parentNode)];
                return host.localName + ':' + parts.join('/');
            }).concat(window.__oldChart.isDisposed())`),
            ['nfsen-sankey:0/0/0/null/null', 'nfsen-matrix:0/0/0/null/null', true],
            'the replaced hosts are empty and detached, and their charts disposed'
        );
        // That reset of the props reaches no observeProps handler: Rocket dropped them at the disconnect.
        const gone = await page.evaluate(`(async function(){
            var ds = await import('datastar'), hs = await import('nfsen/host-state');
            window.__goneSeen = [];
            if (!customElements.get('e2e-gone-probe')) ds.rocket('e2e-gone-probe', {
                mode: 'open',
                props: function(p){ return { dataValue: p.string }; },
                setup: function(ctx){
                    ctx.observeProps(function(){ window.__goneSeen.push(ctx.host.dataValue); }, 'dataValue');
                    ctx.cleanup(function(){ hs.whenGone(ctx.host); });
                },
            });
            var box = document.createElement('div');
            box.innerHTML = '<e2e-gone-probe data-value="first"></e2e-gone-probe>';
            var host = box.firstChild;
            document.body.append(box);
            await new Promise(function(r){ setTimeout(r, 50); });
            host.dataset.value = 'second';
            box.remove();
            await new Promise(function(r){ setTimeout(r, 50); });
            return { seen: window.__goneSeen, attributes: host.attributes.length, value: host.dataValue };
        })()`);
        assert.deepEqual(gone, { seen: ['second'], attributes: 0, value: '' }, 'a gone host resets its props without a handler seeing it');
        assert.notEqual(
            await page.evaluate(`document.querySelector('nfsen-sankey').rocketInstanceId`),
            await page.evaluate(`window.__oldSankey.rocketInstanceId`),
            'the new Sankey is a new Rocket host'
        );
        assert.equal(await page.evaluate(visible('#convStale')), false, 'a fresh result is not stale');
        // A subnet node copies its filter instead.
        await inEachMode(page, async (mode) => {
            await page.evaluate('window.__copied = null');
            assert.equal(await clickNode(page, 0), `src:${subnets.pairs[0].src}`, `the first node is the top source subnet in ${mode}`);
            await page.waitFor(`window.__copied === ${JSON.stringify(`net ${subnets.pairs[0].src}`)}`, {
                label: `the subnet filter to be copied in ${mode}`,
            });
        });
        assert.ok(
            (await page.evaluate(sankeyNames)).every((n) => n.endsWith('/24') || n.endsWith(':*')),
            'the Sankey shows the subnets'
        );

        // ── Destination port: Both is disabled, the port is the middle column ─
        await choose(page, '#convGroupBy', 'port');
        await page.waitFor(`document.getElementById('convDirBoth').disabled`, { label: 'Both to be disabled' });
        assert.equal(await page.signalValue('conv_direction'), 'forward', 'the port grouping runs source to destination');
        const ports = await run(page);
        assert.equal(ports.meta.groupBy, 'port');
        assert.equal(ports.meta.direction, 'forward');
        assert.ok(
            ports.pairs.every((p) => Number.isInteger(p.port) || /^\d+\.\d+$/.test(p.port)),
            'every pair carries its port, or ICMP type.code'
        );
        assert.ok(
            (await page.evaluate(sankeyNames)).some((n) => n.startsWith('port:')),
            'the ports form the middle column'
        );

        // A filter comment with @x(1) and $$y copies unchanged: the command is element text, never a data-* value (PC4).
        const commented = 'any # see @x(1) and $$y';
        await applyFromDrawer(page, commented);
        const withComment = await run(page);
        assert.ok(withComment?.meta.command.includes(commented), `the run's command holds the comment, got ${withComment?.meta.command}`);
        await page.evaluate('window.__copied = null');
        await page.evaluate(`document.getElementById('convExportToggle').click()`);
        await page.waitFor(`document.getElementById('convExport').open === true`, { label: 'Export open for the command' });
        await page.evaluate(`document.querySelector('#convExportMenu [data-export="command"]').click()`);
        await page.waitFor(`typeof window.__copied === 'string'`, { label: 'the command with the comment copied' });
        assert.equal(await page.evaluate('window.__copied'), withComment.meta.command, 'the copied command is the one the run returned');
        await applyFromDrawer(page, '');

        // Back to the default so the next test starts from it.
        await choose(page, '#convGroupBy', 'ip');
        await page.evaluate(`document.getElementById('convDirBoth').click()`);
        await sleep(300);
        assert.equal(await page.signalValue('conv_direction'), 'both');

        // Forced colours: both charts redraw in the system palette and still save a PNG.
        await page.withForcedColors(async () => {
            const forcedText = await page.evaluate(themeText);
            await page.evaluate(`document.getElementById('convView-sankey').click()`);
            await page.waitFor(`${sankeyLabelColour} === ${JSON.stringify(forcedText)}`, { label: 'the Sankey in forced colours' });
            assert.equal(await page.evaluate(png('#convPanel-sankey nfsen-sankey')), `conversations-sankey.png|${PNG_URL}`);
            await page.screenshot('/tmp/nfsen-conversations-forced-colors.png');
            await page.evaluate(`document.getElementById('convView-matrix').click()`);
            await page.waitFor(`${matrixLabelColour} === ${JSON.stringify(forcedText)}`, { label: 'the Matrix in forced colours' });
            assert.equal(await page.evaluate(png('#convPanel-matrix nfsen-matrix')), `conversations-matrix.png|${PNG_URL}`);
            await page.screenshot('/tmp/nfsen-conversations-matrix-forced-colors.png');
            await page.evaluate(`document.getElementById('convView-sankey').click()`);
        });

        const errors = page.realErrors();
        assert.deepEqual(errors, [], `expected no console errors, got:\n${errors.join('\n')}`);
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    conversationsTest()
        .then(() => console.log('conversations: PASS'))
        .catch((e) => {
            console.error('conversations: FAIL\n', e);
            process.exit(1);
        });
}
