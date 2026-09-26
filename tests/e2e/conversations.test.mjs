// Conversations (4.4): one run shown as a Sankey, a Matrix and ranked IP pairs; switching views
// posts nothing; Kill cancels a run and keeps the result; a filter applied from the drawer marks
// the result stale; group by /24 labels subnets; Both is disabled for the port grouping; a second
// run replaces the charts; the Export menu, the keyboard walk and a forced-colors screenshot.
// Needs flows in the last year of the dev stack.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const payload = `JSON.parse(document.querySelector('#convPanel-sankey nfsen-sankey')?.dataset.conversation ?? 'null')`;
const visible = (selector) => `(function(){ var e = document.querySelector(${JSON.stringify(selector)}); return !!e && e.getClientRects().length > 0; })()`;
const sankeyModule = `import(document.querySelector('script[src*="/nfsen-sankey.js"]').src)`;
const hostId = `document.querySelector('#convPanel-sankey .result-host')?.id ?? ''`;
const themeText = `import('nfsen/theme-colors').then(function(m){ return m.chartTheme().text; })`;
const sankeyNames = `document.querySelector('nfsen-sankey').chart.getOption().series[0].data.map(function(n){ return n.name; })`;

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
    await page.evaluate(`(function(){ var b = document.querySelector('[data-filter-field="conversations"] [data-open-drawer="builder"]'); b.focus(); b.click(); })()`);
    await page.waitFor(`document.getElementById('filter-drawer').open && document.getElementById('drawerTitle').textContent.includes('for ') && !!document.getElementById('drawerFilterTextarea')`, {
        timeout: 10000,
        label: 'the drawer for Conversations',
    });
    await page.evaluate(`(function(){ var t = document.getElementById('drawerFilterTextarea'); t.value = ${JSON.stringify(text)}; t.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await sleep(200);
    await page.evaluate(`document.getElementById('drawerApply').click()`);
    await page.waitFor(`!document.getElementById('filter-drawer').open`, { label: 'the drawer to close' });
    await page.waitFor(`document.getElementById('filterNfdumpTextareaSankey').value === ${JSON.stringify(text)}`, { label: 'the applied filter' });
}

async function key(page, name, code = name, keyCode = 0) {
    // Enter activates a button only with its text on the key down.
    const text = name === 'Enter' ? '\r' : undefined;
    await page.send('Input.dispatchKeyEvent', { type: 'keyDown', key: name, code, windowsVirtualKeyCode: keyCode, text });
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key: name, code, windowsVirtualKeyCode: keyCode });
}

async function choose(page, selector, value) {
    await page.setSelectValue(selector, value);
    await sleep(300);
}

async function run(page) {
    const before = await page.evaluate(hostId);
    await page.runQuery('conversations', { timeout: 30000 });
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

        const sankey = await page.evaluate(`(function(){
            var o = document.querySelector('nfsen-sankey').chart.getOption();
            return { type: o.series[0].type, nodes: o.series[0].data.length, names: o.series[0].data.map(function(n){ return n.name; }),
                     others: o.series[0].data.filter(function(n){ return n.others; }).map(function(n){ return n.text; }) };
        })()`);
        assert.equal(sankey.type, 'sankey');
        const ends = new Set(first.pairs.flatMap((p) => [`src:${p.src}`, `dst:${p.dst}`]));
        const hasOthers = (first.others?.[first.meta.metric] ?? 0) > 0;
        assert.equal(sankey.nodes, ends.size + (hasOthers ? 2 : 0), 'one node per source and per destination, and Others in each column');
        assert.deepEqual(sankey.others, hasOthers ? ['Others', 'Others'] : [], 'the traffic outside the top N is an Others node on both sides');
        assert.ok(
            sankey.names.every((n) => n.startsWith('src:') || n.startsWith('dst:')),
            'two columns without the port grouping'
        );
        assert.equal(
            await page.evaluate(`document.querySelector('nfsen-sankey').getAttribute('role') + '|' + document.querySelector('nfsen-matrix').getAttribute('role')`),
            'img|img',
            'both charts are images with a name'
        );
        assert.match(await page.evaluate(`document.querySelector('nfsen-sankey').getAttribute('aria-label')`), /^Sankey of the top .* The IP pairs view lists the same pairs as a table\.$/);

        // Rates in the traffic graph's units, and long labels shortened from the middle.
        const rate = await page.evaluate(`(function(){
            var s = document.querySelector('nfsen-sankey');
            return { unit: s.dataset.unit, text: s.tooltip({ dataType: 'edge', data: { source: 'a', target: 'b', bytes: 1000000, packets: 1, flows: 1 } }, 'bytes', 8) };
        })()`);
        assert.match(rate.text, rate.unit === 'bytes' ? /\(122 KiB\/s on average\)/ : /\(1 Mb\/s on average\)/, `the tooltip rate, got ${rate.text}`);
        const fitted = await page.evaluate(`${sankeyModule}.then(function(m){ return m.fitLabel('2a02:1210:5e0c:f300:1c3d:82ff:fe4b:9a1', 110); })`);
        assert.ok(fitted.includes('…') && fitted.endsWith('9a1') && fitted.startsWith('2a02'), `an IPv6 label keeps its start and end, got ${fitted}`);

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
            assert.ok(probe.labels.some((l) => l.includes('…')), `long IPv6 labels are shortened, got ${probe.labels.slice(0, 3).join(', ')}`);
            assert.equal(probe.labels.filter((l) => l.startsWith('2001:')).length, new Set(probe.labels.filter((l) => l.startsWith('2001:'))).size, 'and stay distinct');
            assert.equal(probe.legend, 6, 'every step of the ramp is in the legend');
        }

        // A theme switch while the Matrix is hidden reaches it once it is shown again.
        const labelColour = `document.querySelector('nfsen-matrix').chart.getOption().yAxis[0].axisLabel.color`;
        const themeBefore = await page.evaluate(`({ choice: window.__nfsenTheme.choice ?? null, dark: window.__nfsenTheme.dark })`);
        const shownColour = await page.evaluate(labelColour);
        await page.evaluate(`document.getElementById('convView-sankey').click()`);
        await page.waitFor(visible('#convPanel-sankey'), { label: 'the Sankey view' });
        await page.evaluate(`window.__nfsenTheme.choose(${JSON.stringify(themeBefore.dark ? 'light' : 'dark')})`);
        await page.waitFor(`document.documentElement.dataset.theme === ${JSON.stringify(themeBefore.dark ? 'light' : 'dark')}`, { label: 'the other theme' });
        const otherText = await page.evaluate(themeText);
        assert.notEqual(otherText, shownColour, 'the two themes have different text colours');
        await sleep(200);
        assert.equal(await page.evaluate(labelColour), shownColour, 'the hidden Matrix has not been drawn again');
        await page.evaluate(`document.getElementById('convView-matrix').click()`);
        await page.waitFor(`${labelColour} === ${JSON.stringify(otherText)}`, { label: 'the Matrix in the new theme' });
        await page.evaluate(`window.__nfsenTheme.choose(${JSON.stringify(themeBefore.choice)})`);
        await page.waitFor(`${labelColour} === ${JSON.stringify(shownColour)}`, { label: 'the Matrix back in the first theme' });

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
        const bytesCell = await page.evaluate(`document.querySelector('#conversationsTable td[data-raw="${first.pairs[0].bytes}"]')?.textContent ?? ''`);
        const others = await page.evaluate(`document.getElementById('convOthers')?.textContent.trim() ?? ''`);
        if (others !== '') {
            const unit = (text) => text.match(/\b(B|KB|MB|GB|TB|PB)\b/)?.[1] ?? null;
            assert.ok(unit(others) !== null && unit(bytesCell) !== null, `the footer prints bytes as the table does, got "${others}" and "${bytesCell}"`);
        }
        await key(page, 'Home', 'Home', 36);
        await page.waitFor(visible('#convPanel-sankey'), { label: 'back to the Sankey' });
        assert.equal(log.count('conversations-run'), 1, 'switching views posts no run');

        // ── Export menu ──────────────────────────────────────────────────────
        await page.evaluate(`(function(){
            window.__downloads = [];
            HTMLAnchorElement.prototype.click = function(){
                var entry = { download: this.download, href: this.href, text: null };
                window.__downloads.push(entry);
                if (this.href.startsWith('blob:')) fetch(this.href).then(function(r){ return r.text(); }).then(function(t){ entry.text = t; });
            };
            document.execCommand = function(){ window.__copied = document.activeElement && document.activeElement.value; return true; };
        })()`);
        await page.evaluate(`document.getElementById('convExportToggle').focus()`);
        await key(page, 'Enter', 'Enter', 13);
        await page.waitFor(`document.getElementById('convExportMenu').hasAttribute('data-open')`, { label: 'the Export menu to open' });
        assert.equal(await page.evaluate(`document.activeElement.dataset.export`), 'csv', 'the first item has the focus');
        await key(page, 'ArrowDown', 'ArrowDown', 40);
        assert.equal(await page.evaluate(`document.activeElement.dataset.export`), 'json');
        await key(page, 'Escape', 'Escape', 27);
        assert.equal(await page.evaluate(`document.activeElement.id`), 'convExportToggle', 'Escape returns to the toggle');
        assert.equal(await page.evaluate(`document.getElementById('convExportMenu').hasAttribute('data-open')`), false);

        for (const kind of ['csv', 'json', 'png', 'command']) {
            await page.evaluate(`document.getElementById('convExportToggle').click()`);
            await page.evaluate(`document.querySelector('#convExportMenu [data-export="${kind}"]').click()`);
            await sleep(300);
        }
        const downloads = await page.evaluate(`window.__downloads`);
        const byName = Object.fromEntries(downloads.map((d) => [d.download, d]));
        assert.ok(byName['conversations-pairs.csv']?.text?.includes(first.pairs[0].src), 'the CSV holds the pairs');
        assert.ok(byName['conversations-pairs.json']?.text?.startsWith('['), 'the JSON is a list');
        assert.ok(byName['conversations-sankey.png']?.href.startsWith('data:image/png'), 'the PNG of the current chart');
        assert.match((await page.evaluate(`window.__copied`)) ?? '', /nfdump .*-A/, 'Copy nfdump command copies the command');

        // A source node opens the IP info of its address.
        await page.evaluate(
            `document.querySelector('nfsen-sankey').onClick({ dataType: 'node', data: { text: ${JSON.stringify(first.pairs[0].src)}, kind: 'source' } })`
        );
        await page.waitFor(`!!document.getElementById('ip-modal-inner')?.open`, { label: 'the IP info of a clicked node', timeout: 40000 });
        assert.ok((await page.evaluate(`document.getElementById('ip-modal-inner').textContent`)).includes(first.pairs[0].src));
        await page.evaluate(`document.getElementById('ip-modal-inner').close()`);

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
        await page.runQuery('conversations', { timeout: 30000 });
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
        assert.ok(killNotice === '' || /^nfdump process \(PID \d+\) was killed\./.test(killNotice), `only kill-nfdump's notice on top, got: ${killNotice}`);
        console.log(`  (kill: ${killNotice || 'landed before nfdump started'})`);
        assert.equal(await page.evaluate(visible('#convStale')), false, 'the kept result still matches the query');

        // ── A filter applied from the drawer marks the result stale, and undoing it clears that ──
        await applyFromDrawer(page, 'proto tcp');
        await page.waitFor(visible('#convStale'), { label: 'the stale notice after the drawer applied a filter' });
        await applyFromDrawer(page, '');
        await page.waitFor(`!${visible('#convStale')}`, { label: 'the stale notice gone with the filter undone' });
        assert.equal(log.count('conversations-run'), 2, 'the drawer ran nothing');

        // ── /24 subnets: stale first, then a new result replaces the charts ──
        const oldSankey = await page.evaluate(`(window.__oldSankey = document.querySelector('nfsen-sankey'), true)`);
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
        assert.equal(await page.evaluate(visible('#convStale')), false, 'a fresh result is not stale');
        // A subnet node copies its filter instead.
        await page.evaluate(`document.querySelector('nfsen-sankey').onClick({ dataType: 'node', data: { text: ${JSON.stringify(subnets.pairs[0].src)}, kind: 'source' } })`);
        await page.waitFor(`window.__copied === ${JSON.stringify(`net ${subnets.pairs[0].src}`)}`, { label: 'the subnet filter to be copied' });
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

        // Back to the default so the next test starts from it.
        await choose(page, '#convGroupBy', 'ip');
        await page.evaluate(`document.getElementById('convDirBoth').click()`);
        await sleep(300);
        assert.equal(await page.signalValue('conv_direction'), 'both');

        await page.withForcedColors(async () => {
            await sleep(300);
            await page.screenshot('/tmp/nfsen-conversations-forced-colors.png');
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
