// The shared controls of the design system (spec 2.2, 2.4, 2.5): the tab keyboard model and
// the menus from nfsen-controls.js, focus rings in every theme and in forced colors, and the
// colours theme-colors.js hands to the charts. The markup is a fixture injected into the live page.
import assert from 'node:assert/strict';
import { BASE, withPage } from './lib/cdp.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const KEYS = {
    Tab: { code: 'Tab', keyCode: 9 },
    Enter: { code: 'Enter', keyCode: 13, text: '\r' },
    ' ': { code: 'Space', keyCode: 32, text: ' ' },
    Escape: { code: 'Escape', keyCode: 27 },
    ArrowRight: { code: 'ArrowRight', keyCode: 39 },
    ArrowLeft: { code: 'ArrowLeft', keyCode: 37 },
    ArrowDown: { code: 'ArrowDown', keyCode: 40 },
    ArrowUp: { code: 'ArrowUp', keyCode: 38 },
    Home: { code: 'Home', keyCode: 36 },
    End: { code: 'End', keyCode: 35 },
    5: { code: 'Digit5', keyCode: 53, text: '5' },
};

async function press(page, key, { shift = false } = {}) {
    const k = KEYS[key];
    const modifiers = shift ? 8 : 0;
    const down = { type: k.text ? 'keyDown' : 'rawKeyDown', key, code: k.code, windowsVirtualKeyCode: k.keyCode, modifiers, text: k.text };
    await page.send('Input.dispatchKeyEvent', down);
    await page.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code: k.code, windowsVirtualKeyCode: k.keyCode, modifiers });
    await sleep(60);
}

async function centre(page, selector) {
    return page.evaluate(`(() => {
        const b = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();
        return { x: b.x + b.width / 2, y: b.y + b.height / 2 };
    })()`);
}

async function clickAt(page, selector) {
    const { x, y } = await centre(page, selector);
    for (const type of ['mousePressed', 'mouseReleased']) {
        await page.send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
    }
    await sleep(100);
}

async function tapAt(page, selector) {
    const { x, y } = await centre(page, selector);
    await page.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await sleep(80);
    await page.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await sleep(400);
}

const active = (page) => page.evaluate('document.activeElement?.id || document.activeElement?.tagName');
const attr = (page, id, name) => page.evaluate(`document.getElementById(${JSON.stringify(id)})?.getAttribute(${JSON.stringify(name)})`);
const popoverOpen = (page, id) => page.evaluate(`document.getElementById(${JSON.stringify(id)}).matches(':popover-open')`);

const FIXTURE = `
<div id="fx" class="stack card">
  <button id="before">before</button>
  <div class="tabs" role="tablist" aria-label="Auto" id="tlAuto">
    <button role="tab" id="ta1" aria-selected="false" aria-controls="pa">One</button>
    <button role="tab" id="ta2" aria-selected="true" aria-controls="pa">Two</button>
    <button role="tab" id="ta3" aria-selected="false" aria-controls="pa">Three</button>
  </div>
  <div role="tabpanel" id="pa" tabindex="0" aria-labelledby="ta2">panel</div>
  <div class="tabs" role="tablist" aria-label="Unmarked" id="tlLeg">
    <button role="tab" id="tl1">L1</button>
    <button role="tab" id="tl2">L2</button>
    <button role="tab" id="tl3">L3</button>
  </div>
  <div class="tabs" role="tablist" aria-label="Manual" data-activation="manual" data-size="sm" id="tlMan">
    <button role="tab" id="tm1" aria-selected="true" tabindex="0">M1</button>
    <button role="tab" id="tm2" aria-selected="false" tabindex="-1">M2</button>
    <button role="tab" id="tm3" aria-selected="false" tabindex="-1">M3</button>
  </div>
  <div class="cluster">
    <div class="menu" id="dm">
      <button class="menu-toggle" aria-expanded="false" aria-controls="dmList" id="dmToggle">Range</button>
      <div class="menu-list" id="dmList" data-align="start">
        <button class="menu-item" aria-pressed="true" id="dmP1">Last 1 hour</button>
        <button class="menu-item" aria-pressed="false" id="dmP2">Last 24 hours</button>
        <div class="menu-sep"></div>
        <input type="number" id="dmNum" aria-label="n">
        <label class="switch"><input type="checkbox" id="dmSw"> Follow</label>
      </div>
    </div>
    <div class="menu" data-role="menu" id="am">
      <button aria-haspopup="menu" aria-expanded="false" aria-controls="amList" id="amToggle">Export</button>
      <ul role="menu" id="amList">
        <li role="none"><button role="menuitem" tabindex="-1" id="am1">CSV</button></li>
        <li role="none"><button role="menuitem" tabindex="-1" id="am2">JSON</button></li>
        <li role="none"><button role="menuitem" tabindex="-1" id="am3">Print</button></li>
      </ul>
    </div>
    <div class="menu" id="pm">
      <button class="menu-toggle" aria-expanded="false" aria-controls="pmList" id="pmToggle">Manual popover</button>
      <div class="menu-list" popover="manual" id="pmList"><button class="menu-item" id="pm1">A</button></div>
    </div>
    <div class="menu" id="qm">
      <button class="menu-toggle" aria-expanded="false" aria-controls="qmList" id="qmToggle">Auto popover</button>
      <div class="menu-list" popover id="qmList"><button class="menu-item" id="qm1">A</button><input id="qmNum" type="number" aria-label="n"></div>
    </div>
    <button id="after">after</button>
  </div>
  <div class="cluster">
    <button id="pressed" aria-pressed="true">Pressed</button>
    <div class="segmented" role="radiogroup" aria-label="Unit">
      <input type="radio" name="fxUnit" id="sg1" checked><label for="sg1">Bytes</label>
      <input type="radio" name="fxUnit" id="sg2"><label for="sg2">Packets</label>
    </div>
    <a href="#" aria-current="page" id="navActive">Current link</a>
    <button class="kill" id="kill">Kill</button>
    <label class="switch"><input type="checkbox" role="switch" id="sw"> Live</label>
  </div>
</div>`;

// Popover menus that have to follow a scrolling toggle, and one at the foot of the viewport.
const FLOATING = `
<div id="fx2" style="position: fixed; inset-block-start: 1rem; inset-inline-end: 1rem; z-index: 40; inline-size: 16rem">
  <div id="sc" style="overflow: auto; block-size: 8rem; border: 1px solid">
    <div style="block-size: 30rem; padding-block-start: 1rem">
      <div class="menu" id="sm">
        <button class="menu-toggle" aria-expanded="false" aria-controls="smList" id="smToggle">Scrolls</button>
        <div class="menu-list" popover id="smList"><button class="menu-item" id="sm1">A</button><button class="menu-item">B</button></div>
      </div>
    </div>
  </div>
</div>
<div class="menu" id="bm" style="position: fixed; inset-block-end: 0.5rem; inset-inline-end: 1rem; z-index: 40">
  <button class="menu-toggle" aria-expanded="false" aria-controls="bmList" id="bmToggle">At the bottom</button>
  <div class="menu-list" popover id="bmList">${'<button class="menu-item">Item</button>'.repeat(40)}</div>
</div>`;

async function inject(page) {
    await page.evaluate(`(() => {
        document.getElementById('client-root').insertAdjacentHTML('beforeend', ${JSON.stringify(FIXTURE)});
        const fx = document.getElementById('fx');
        Object.assign(fx.style, { position: 'fixed', insetBlockStart: '1rem', insetInlineStart: '1rem', zIndex: 40, inlineSize: '40rem' });
        for (const list of fx.querySelectorAll('[role=tablist]:not(#tlLeg)')) {
            list.addEventListener('click', (e) => {
                const tab = e.target.closest('[role=tab]');
                if (!tab) return;
                for (const t of list.querySelectorAll('[role=tab]')) t.setAttribute('aria-selected', String(t === tab));
            });
        }
        window.__clicked = [];
        fx.addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (b) window.__clicked.push(b.id);
            if (e.target.closest('a[href="#"]')) e.preventDefault();
        });
        document.getElementById('client-root').insertAdjacentHTML('beforeend', ${JSON.stringify(FLOATING)});
        // A fired alert's toast (Shell::alertToast) would sit over the bottom-anchored fixture.
        document.getElementById('alerts-toast-container').style.display = 'none';
        return true;
    })()`);
}

async function tabs(page) {
    await page.evaluate(`document.getElementById('before').focus()`);
    await press(page, 'Tab');
    assert.equal(await active(page), 'ta2', 'Tab into the tablist lands on the selected tab');
    assert.equal(await attr(page, 'ta1', 'tabindex'), '-1');
    assert.equal(await attr(page, 'ta3', 'tabindex'), '-1');
    await press(page, 'Tab');
    assert.equal(await active(page), 'pa', 'the next Tab leaves the tablist for the panel');
    await press(page, 'Tab', { shift: true });
    assert.equal(await active(page), 'ta2', 'Shift+Tab returns to the selected tab');
    await press(page, 'ArrowRight');
    assert.equal(await active(page), 'ta3');
    assert.equal(await attr(page, 'ta3', 'aria-selected'), 'true', 'automatic activation');
    assert.equal(await attr(page, 'ta3', 'tabindex'), '0');
    await press(page, 'ArrowRight');
    assert.equal(await active(page), 'ta1', 'arrows wrap');
    await press(page, 'End');
    assert.equal(await active(page), 'ta3');
    await press(page, 'Home');
    assert.equal(await active(page), 'ta1');
    await press(page, 'ArrowLeft');
    assert.equal(await active(page), 'ta3');

    await page.evaluate(`document.getElementById('tm1').focus()`);
    await press(page, 'ArrowRight');
    assert.equal(await active(page), 'tm2');
    assert.equal(await attr(page, 'tm2', 'aria-selected'), 'false', 'manual activation: arrows only move focus');
    await press(page, 'Enter');
    assert.equal(await attr(page, 'tm2', 'aria-selected'), 'true', 'manual activation: Enter selects');
    assert.equal(await attr(page, 'tm2', 'tabindex'), '0');
    assert.equal(await attr(page, 'tm1', 'tabindex'), '-1');

    // A pointer click on a tab keeps focus on that tab.
    await page.evaluate(`document.getElementById('before').focus()`);
    await clickAt(page, '#ta2');
    assert.equal(await active(page), 'ta2', 'a click focuses the clicked tab');
    assert.equal(await attr(page, 'ta2', 'aria-selected'), 'true');

    // Without aria-selected nothing could move a roving Tab stop, so every tab keeps its own.
    await clickAt(page, '#tl2');
    await press(page, 'ArrowRight');
    assert.equal(await active(page), 'tl3', 'arrows also move in a list without aria-selected');
    const stops = await page.evaluate(`[...document.querySelectorAll('#tlLeg [role=tab]')].map((t) => t.getAttribute('tabindex'))`);
    assert.deepEqual(stops, [null, null, null], 'a list without aria-selected gets no roving tabindex');
}

async function disclosureMenu(page) {
    await page.evaluate(`document.getElementById('dmToggle').focus()`);
    await press(page, 'Enter');
    assert.equal(await attr(page, 'dmList', 'data-open'), '', 'Enter opens');
    assert.equal(await attr(page, 'dmToggle', 'aria-expanded'), 'true');
    await press(page, 'Tab');
    assert.equal(await active(page), 'dmP1', 'Tab moves into the open menu');
    await press(page, 'Tab');
    await press(page, 'Tab');
    assert.equal(await active(page), 'dmNum');
    await press(page, '5');
    assert.equal(await page.evaluate(`document.getElementById('dmNum').value`), '5');
    assert.equal(await attr(page, 'dmList', 'data-open'), '', 'typing keeps the menu open');
    await press(page, 'Tab');
    assert.equal(await active(page), 'dmSw');
    await press(page, ' ');
    assert.equal(await page.evaluate(`document.getElementById('dmSw').checked`), true);
    assert.equal(await attr(page, 'dmList', 'data-open'), '', 'a switch keeps the menu open');
    await press(page, 'Escape');
    assert.equal(await attr(page, 'dmList', 'data-open'), null, 'Escape closes');
    assert.equal(await active(page), 'dmToggle', 'Escape returns focus to the toggle');
    assert.equal(await attr(page, 'dmToggle', 'aria-expanded'), 'false');
    await press(page, ' ');
    assert.equal(await attr(page, 'dmList', 'data-open'), '', 'Space opens');
    await press(page, 'Tab');
    await press(page, 'Tab');
    assert.equal(await active(page), 'dmP2');
    await press(page, 'Enter');
    assert.equal(await attr(page, 'dmList', 'data-open'), null, 'choosing an item closes');
    assert.equal(await active(page), 'dmToggle', 'and focus returns to the toggle');
    assert.ok((await page.evaluate('window.__clicked')).includes('dmP2'), 'the item still acted');

    await clickAt(page, '#dmToggle');
    assert.equal(await attr(page, 'dmList', 'data-open'), '', 'a click opens');
    await clickAt(page, '#before');
    assert.equal(await attr(page, 'dmList', 'data-open'), null, 'an outside click closes');
    await clickAt(page, '#dmToggle');
    await clickAt(page, '#dmToggle');
    assert.equal(await attr(page, 'dmList', 'data-open'), null, 'a second click on the toggle closes');
}

async function actionMenu(page) {
    await page.evaluate(`document.getElementById('amToggle').focus()`);
    await press(page, 'Enter');
    assert.equal(await attr(page, 'amList', 'data-open'), '');
    assert.equal(await active(page), 'am1', 'opening focuses the first item');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'am2');
    await press(page, 'ArrowDown');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'am1', 'arrows wrap');
    await press(page, 'ArrowUp');
    assert.equal(await active(page), 'am3');
    await press(page, 'Home');
    assert.equal(await active(page), 'am1');
    await press(page, 'End');
    assert.equal(await active(page), 'am3');
    await press(page, 'Escape');
    assert.equal(await attr(page, 'amList', 'data-open'), null);
    assert.equal(await active(page), 'amToggle');
    await press(page, 'ArrowUp');
    assert.equal(await active(page), 'am3', 'ArrowUp on the toggle opens on the last item');
    await press(page, 'Tab');
    assert.equal(await attr(page, 'amList', 'data-open'), null, 'Tab closes');
    assert.equal(await active(page), 'pmToggle', 'and moves on from the toggle');
    await page.evaluate(`document.getElementById('amToggle').focus()`);
    await press(page, 'ArrowDown');
    await press(page, 'ArrowDown');
    await press(page, 'Enter');
    assert.equal(await attr(page, 'amList', 'data-open'), null, 'choosing a menuitem closes');
    assert.equal(await active(page), 'amToggle');
}

async function popoverMenus(page) {
    await clickAt(page, '#pmToggle');
    assert.equal(await popoverOpen(page, 'pmList'), true, 'a manual popover list opens');
    assert.match(await page.evaluate(`document.getElementById('pmList').style.getPropertyValue('--menu-top')`), /px$/);
    await press(page, 'Escape');
    assert.equal(await popoverOpen(page, 'pmList'), false, 'Escape closes it');
    assert.equal(await attr(page, 'pmToggle', 'aria-expanded'), 'false');

    // Auto popovers are light-dismissed by the browser before the toggle's click arrives.
    await clickAt(page, '#qmToggle');
    assert.equal(await popoverOpen(page, 'qmList'), true, 'an auto popover list opens');
    assert.equal(await attr(page, 'qmToggle', 'aria-expanded'), 'true');
    await clickAt(page, '#qmToggle');
    assert.equal(await popoverOpen(page, 'qmList'), false, 'a second click on the toggle closes it');
    assert.equal(await attr(page, 'qmToggle', 'aria-expanded'), 'false');
    await clickAt(page, '#qmToggle');
    assert.equal(await popoverOpen(page, 'qmList'), true, 'a third click opens it again');
    await clickAt(page, '#qmNum');
    await press(page, '5');
    assert.equal(await popoverOpen(page, 'qmList'), true, 'typing inside keeps it open');
    await clickAt(page, '#before');
    assert.equal(await popoverOpen(page, 'qmList'), false, 'an outside click closes it');
    assert.equal(await attr(page, 'qmToggle', 'aria-expanded'), 'false');
    await page.evaluate(`document.getElementById('qmToggle').focus()`);
    await press(page, 'Enter');
    assert.equal(await popoverOpen(page, 'qmList'), true, 'Enter opens it');
    await press(page, 'Enter');
    assert.equal(await popoverOpen(page, 'qmList'), false, 'Enter on the toggle closes it');
    await clickAt(page, '#qmToggle');
    await clickAt(page, '#qm1');
    assert.equal(await popoverOpen(page, 'qmList'), false, 'choosing an item closes it');
}

const box = (page, id) =>
    page.evaluate(`(() => {
    const r = document.getElementById(${JSON.stringify(id)}).getBoundingClientRect();
    return { top: Math.round(r.top), bottom: Math.round(r.bottom) };
})()`);

async function floatingMenus(page) {
    const { clientHeight } = await page.evaluate(`({ clientHeight: document.documentElement.clientHeight })`);

    await clickAt(page, '#bmToggle');
    assert.equal(await popoverOpen(page, 'bmList'), true);
    const [toggle, list] = [await box(page, 'bmToggle'), await box(page, 'bmList')];
    assert.ok(
        Math.abs(list.bottom - (toggle.top - 4)) <= 1,
        `a list with no room below opens above its toggle: ${JSON.stringify({ toggle, list })}`
    );
    assert.ok(list.top >= 0, `and stays inside the viewport: ${list.top}`);
    assert.ok(list.bottom <= clientHeight);
    await press(page, 'Escape');
    assert.equal(await popoverOpen(page, 'bmList'), false);

    await clickAt(page, '#smToggle');
    assert.equal(await popoverOpen(page, 'smList'), true);
    const opened = await box(page, 'smList');
    assert.equal(opened.top, (await box(page, 'smToggle')).bottom + 4, 'the list opens under its toggle');
    await page.evaluate(`document.getElementById('sc').scrollTop = 10`);
    await sleep(100);
    const [t2, l2] = [await box(page, 'smToggle'), await box(page, 'smList')];
    assert.ok(
        l2.top !== opened.top && Math.abs(l2.top - (t2.bottom + 4)) <= 1,
        `the list follows a scroll: ${JSON.stringify({ t2, l2, opened })}`
    );
    assert.equal(await popoverOpen(page, 'smList'), true, 'and stays open while its toggle shows');
    await page.evaluate(`document.getElementById('sc').scrollTop = 200`);
    await sleep(100);
    assert.equal(await popoverOpen(page, 'smList'), false, 'the list closes once its toggle scrolls out of sight');
    assert.equal(await attr(page, 'smToggle', 'aria-expanded'), 'false');
    await page.evaluate(`document.getElementById('sc').scrollTop = 0`);
}

async function focusRings(page) {
    const selectors = [
        '#before',
        '#ta1',
        '#dmToggle',
        '#amToggle',
        '#kill',
        '#sg2',
        'select',
        'input[type=text], input[type=number]',
        'textarea',
        'a[href]',
        '.switch input',
    ];
    for (const sel of selectors) {
        await page.evaluate(`document.getElementById('before').focus()`);
        await press(page, 'Tab'); // keyboard modality, so focus() below counts as :focus-visible
        const r = await page.evaluate(`(() => {
            const el = [...document.querySelectorAll(${JSON.stringify(sel)})].find((e) => e.getClientRects().length);
            if (!el) return null;
            el.focus();
            const ring = el.matches('.segmented input') ? el.nextElementSibling : el;
            const cs = getComputedStyle(ring);
            return { fv: el.matches(':focus-visible'), style: cs.outlineStyle, width: cs.outlineWidth };
        })()`);
        if (!r) continue;
        assert.ok(r.fv && r.style !== 'none' && parseFloat(r.width) >= 2, `focus ring on ${sel}: ${JSON.stringify(r)}`);
    }
}

/** The outline on `ring` while focus is on `away`, then with keyboard focus on `target`. */
async function ringBeforeAndAfter(page, { target, ring = target, away = '#before' }) {
    const q = (sel) => `document.querySelector(${JSON.stringify(sel)})`;
    const outline = `(() => { const s = getComputedStyle(${q(ring)}); return [s.outlineStyle, s.outlineWidth, s.outlineOffset].join(' '); })()`;
    await page.evaluate(`${q(away)}.focus()`);
    await press(page, 'Tab'); // keyboard modality, so focus() below counts as :focus-visible
    await page.evaluate(`${q(away)}.focus()`);
    const before = await page.evaluate(outline);
    await page.evaluate(`${q(target)}.focus()`);
    const fv = await page.evaluate(`${q(target)}.matches(':focus-visible')`);
    return { before, after: await page.evaluate(outline), fv };
}

// Forced colors: a selected, pressed or current control keeps its state ring, and focus
// on it must still change what is drawn.
async function forcedColorsFocus(page) {
    const cases = [
        { target: '#ta2' },
        { target: '#pressed' },
        { target: '#navActive' },
        { target: '#sg1', ring: 'label[for=sg1]' },
        { target: '#dmP1', away: '#dmP2' },
    ];
    for (const c of cases) {
        if (c.target === '#dmP1') await page.evaluate(`document.getElementById('dmToggle').click()`);
        const r = await ringBeforeAndAfter(page, c);
        assert.ok(r.fv, `${c.target} gets :focus-visible`);
        assert.notEqual(r.before.split(' ')[0], 'none', `${c.target} shows its state without focus: ${r.before}`);
        assert.notEqual(r.after, r.before, `focus on ${c.target} shows over its state ring: ${r.before} -> ${r.after}`);
    }
    await press(page, 'Escape');

    // A mouse click paints no focus ring, but the pressed state must still show.
    await clickAt(page, '#pressed');
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 1000 });
    const r = await page.evaluate(`(() => {
        const el = document.getElementById('pressed');
        return { focused: document.activeElement === el, fv: el.matches(':focus-visible'), style: getComputedStyle(el).outlineStyle };
    })()`);
    assert.ok(r.focused && !r.fv, `the click focuses #pressed without :focus-visible: ${JSON.stringify(r)}`);
    assert.notEqual(r.style, 'none', 'a pressed button keeps its state ring after a mouse click');

    // The switch opts out of forced colours, so its ring must not come from a pinned theme
    // that matches Canvas (a dark theme on a light forced palette, or the reverse).
    const canvas = await page.evaluate(`(() => {
        const p = document.createElement('span');
        p.style.cssText = 'forced-color-adjust: none; color: Canvas; background: Highlight';
        document.body.append(p);
        const s = getComputedStyle(p);
        const r = { canvas: s.color, highlight: s.backgroundColor };
        p.remove();
        return r;
    })()`);
    const pinned = canvas.canvas === 'rgb(0, 0, 0)' ? 'light' : 'dark';
    const previous = await page.evaluate(`document.documentElement.dataset.theme`);
    await page.evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(pinned)}`);
    const sw = await ringBeforeAndAfter(page, { target: '#sw' });
    const colour = await page.evaluate(`getComputedStyle(document.getElementById('sw')).outlineColor`);
    await page.evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(previous ?? '')}`);
    assert.ok(sw.fv && sw.after.split(' ')[0] !== 'none', `the switch shows a focus ring: ${sw.after}`);
    assert.equal(colour, canvas.highlight, `the switch focus ring is Highlight with a ${pinned} theme pinned`);
}

/** A mouse click on a control paints no focus ring outside forced colors. */
async function mouseFocus(page) {
    await clickAt(page, '#pressed');
    const style = await page.evaluate(`getComputedStyle(document.getElementById('pressed')).outlineStyle`);
    assert.equal(style, 'none', 'no outline after a mouse click');
}

async function killBoundary(page) {
    const measure = () =>
        page.evaluate(`(async () => {
        const m = await import('/js/components/theme-colors.js');
        const lum = (v) => {
            const [r, g, b] = v.match(/[\\d.]+/g).slice(0, 3).map(Number).map((c) => {
                c /= 255;
                return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
            });
            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };
        const cr = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
        const s = getComputedStyle(document.getElementById('kill'));
        const [border, text] = [m.resolve(s.borderTopColor), m.resolve(s.color)];
        const fill = s.backgroundColor === 'rgba(0, 0, 0, 0)' ? null : m.resolve(s.backgroundColor);
        const around = (fg) => Math.min(cr(fg, m.resolve('var(--surface-2)')), cr(fg, m.resolve('var(--surface-3)')));
        return { boundary: around(border), text: fill ? cr(text, fill) : around(text), hovered: document.getElementById('kill').matches(':hover') };
    })()`);
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 1000 });
    await sleep(400);
    const rest = await measure();
    const { x, y } = await centre(page, '#kill');
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await sleep(400);
    const hover = await measure();
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 1000 });
    assert.ok(hover.hovered && !rest.hovered);
    assert.ok(rest.boundary >= 3, `the Kill button boundary is at least 3:1, got ${rest.boundary.toFixed(2)}`);
    assert.ok(rest.text >= 4.5, `the Kill label is at least 4.5:1, got ${rest.text.toFixed(2)}`);
    assert.ok(hover.text >= 4.5, `the hovered Kill label is at least 4.5:1 on its fill, got ${hover.text.toFixed(2)}`);
}

async function themeColors(page, mode) {
    const t = await page.evaluate(`(async () => {
        const m = await import('/js/components/theme-colors.js');
        const rgba = /^rgba\\(\\d{1,3}, \\d{1,3}, \\d{1,3}, [0-9.]+\\)$/;
        const t = m.chartTheme();
        const flat = [t.text, t.axis, t.grid, t.tooltipBg, t.tooltipBorder, t.brush, t.brushBorder, t.surface, ...t.series, t.others, ...m.sequentialRamp(6), m.seriesColor(1), m.seriesColor(9), m.seriesColor('others')];
        const names = ['--surface-1', '--surface-2', '--surface-3', '--surface-4', '--surface-sidebar', '--surface-inverse', '--text-1', '--text-2', '--text-3', '--text-on-inverse', '--border', '--border-strong', '--control-border', '--accent', '--accent-hover', '--on-accent', '--accent-subtle', '--on-danger', '--focus-color', '--series-others', '--chart-text', '--chart-axis', '--chart-grid', '--chart-tooltip-bg', '--chart-tooltip-border', '--chart-brush', '--chart-brush-border', '--backdrop'];
        const neutral = names.map((n) => [n, m.resolve('var(' + n + ')')]);
        const chroma = neutral.filter(([, v]) => { const [r, g, b] = v.match(/\\d+/g).map(Number); return Math.max(r, g, b) - Math.min(r, g, b) > 1; });
        let fired = 0;
        const off = m.onThemeChange(() => { fired++; });
        const pick = (c) => document.querySelector('[data-theme-choice="' + c + '"]').click();
        pick(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
        await new Promise((r) => setTimeout(r, 150));
        const switched = m.chartTheme().surface !== t.surface;
        pick('default');
        await new Promise((r) => setTimeout(r, 150));
        off();
        return {
            theme: document.documentElement.dataset.theme,
            bad: flat.filter((v) => !rgba.test(v)),
            unresolved: neutral.filter(([, v]) => !rgba.test(v)).map(([n]) => n),
            chroma: chroma.map(([n]) => n),
            series: t.series.length,
            ramp: m.sequentialRamp(6).length,
            patterns: [0, 7, 8, 15, 16, 23, 24, 40].map(m.linePattern),
            fired,
            switched,
        };
    })()`);
    assert.equal(t.theme, mode);
    assert.deepEqual(t.bad, [], 'every chartTheme value is an rgba() string');
    assert.deepEqual(t.unresolved, [], 'every token resolves to rgba()');
    assert.deepEqual(t.chroma, [], 'neutral tokens have zero chroma');
    assert.equal(t.series, 8);
    assert.equal(t.ramp, 6);
    assert.deepEqual(t.patterns, ['solid', 'solid', 'dashed', 'dashed', 'dotted', 'dotted', 'solid', 'solid']);
    assert.equal(t.fired, 2, 'onThemeChange fires on each theme switch');
    assert.ok(t.switched, 'chartTheme follows the theme');
}

async function boot(page, features) {
    await page.send('Emulation.setEmulatedMedia', { features });
    await page.navigate(BASE + '/');
    await page.waitForBoot();
    await sleep(800);
    await inject(page);
}

export default async function uiControlsTest() {
    for (const mode of ['light', 'dark']) {
        await withPage(async (page) => {
            await boot(page, [{ name: 'prefers-color-scheme', value: mode }]);
            await themeColors(page, mode);
            await tabs(page);
            await disclosureMenu(page);
            await actionMenu(page);
            await popoverMenus(page);
            await floatingMenus(page);
            await mouseFocus(page);
            await focusRings(page);
            await killBoundary(page);
            assert.deepEqual(page.realErrors(), []);
        });
    }

    await withPage(async (page) => {
        await boot(page, [{ name: 'forced-colors', value: 'active' }]);
        const surface = await page.evaluate(`import('/js/components/theme-colors.js').then((m) => m.chartTheme().surface)`);
        assert.match(surface, /^rgba\(/, 'theme-colors resolves under forced colors');
        await tabs(page);
        await disclosureMenu(page);
        await focusRings(page);
        await forcedColorsFocus(page);
        assert.deepEqual(page.realErrors(), []);
    });

    // Touch: the tap focuses the tab it lands on, not the previously selected one.
    await withPage(async (page) => {
        await boot(page, []);
        await page.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 1 });
        await page.evaluate(`document.getElementById('before').focus()`);
        await tapAt(page, '#ta3');
        assert.equal(await attr(page, 'ta3', 'aria-selected'), 'true');
        assert.equal(await active(page), 'ta3', 'a tap from outside the tablist keeps focus on the tapped tab');
    });
}

if (import.meta.url === `file://${process.argv[1]}`) {
    uiControlsTest()
        .then(() => console.log('ui-controls: PASS'))
        .catch((e) => {
            console.error('ui-controls: FAIL\n', e);
            process.exit(1);
        });
}
