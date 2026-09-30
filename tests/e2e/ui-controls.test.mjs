// The shared controls of the design system (spec 2.2, 2.4, 2.5): the tab keyboard model, the
// menus and the sb-popover layer from nfsen-controls.js, focus rings in every theme and in forced
// colors, and the colours theme-colors.js hands to the charts. The markup is a fixture injected
// into the live page.
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

// sb-popover hosts as the templates write them (POPOVER-SPEC 4.1), with nfsen-controls.js on top.
const POPOVERS = `
<div id="fxp" class="cluster" style="position: fixed; inset-block-end: 14rem; inset-inline-start: 1rem; z-index: 40">
  <button id="pBefore">before popovers</button>
  <sb-popover id="popA" label="Export" placement="bottom-start">
    <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup" id="popAT">Export</button>
    <ul class="popover-list" id="popAList">
      <li><button type="button" class="menu-item" id="pa1" data-on:click="window.__openInHandler = document.getElementById('popA').open">CSV</button></li>
      <li><button type="button" class="menu-item" id="pa2">JSON</button></li>
      <li hidden><button type="button" class="menu-item" id="paHidden">Hidden</button></li>
      <li class="menu-sep" role="separator"></li>
      <li><button type="button" class="menu-item" id="pa3">Print</button></li>
    </ul>
  </sb-popover>
  <sb-popover id="popB" label="Options" placement="bottom-start">
    <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup" id="popBT">Options</button>
    <ul class="popover-list" id="popBList">
      <li><button type="button" class="menu-item" id="pbKeep" data-menu-keep>Keep open</button></li>
      <li><button type="button" class="menu-item" id="pbDisabled" aria-disabled="true">Unavailable</button></li>
      <li><button type="button" class="menu-item" id="pbPrevent">Prevented</button></li>
      <li><button type="button" class="menu-item" id="pbStop">Stopped</button></li>
      <li><label><input type="checkbox" id="pbCheck"> Show bytes</label></li>
      <li><label class="switch"><input type="checkbox" role="switch" id="pbSwitch"> Follow</label></li>
      <li><input type="text" id="pbText" aria-label="Name"></li>
    </ul>
  </sb-popover>
  <sb-popover id="popC" label="Theme" placement="bottom-start">
    <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup" id="popCT">Theme</button>
    <div class="popover-list" id="popCList">
      <button type="button" class="menu-item" aria-pressed="false" id="pc1">Light</button>
      <button type="button" class="menu-item" aria-pressed="true" id="pc2">Dark</button>
    </div>
  </sb-popover>
  <button id="pAfter">after popovers</button>
</div>
<div id="fxpMoved" style="position: fixed; inset-block-end: 6rem; inset-inline-start: 1rem; z-index: 40"></div>
<dialog id="fxDialog" aria-label="Fixture dialog"><button id="fxDialogOk">OK</button></dialog>`;

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
        document.getElementById('client-root').insertAdjacentHTML('beforeend', ${JSON.stringify(POPOVERS)});
        const fxp = document.getElementById('fxp');
        window.__popClicked = [];
        fxp.addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (b) window.__popClicked.push(b.id);
        }, true);
        document.getElementById('pbPrevent').addEventListener('click', (e) => e.preventDefault());
        document.getElementById('pbStop').addEventListener('click', (e) => e.stopPropagation());
        // A fired alert's toast (Shell::alertToast) would sit over the bottom-anchored fixture.
        document.getElementById('alerts-toast-container').style.display = 'none';
        return true;
    })()`);
    await page.waitFor(`[...document.querySelectorAll('#fxp sb-popover')].every((h) => h.shadowRoot && h.querySelector('[slot=trigger]').hasAttribute('aria-expanded'))`, {
        label: 'the fixture popovers to render',
    });
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

/** Whether an sb-popover is open; throws when its open property and its top-layer panel disagree. */
const popOpen = (page, id) =>
    page.evaluate(`(() => {
        const host = document.getElementById(${JSON.stringify(id)});
        const shown = host.shadowRoot.querySelector('.pop').matches(':popover-open');
        if (host.open !== shown) throw new Error(host.id + ': open is ' + host.open + ' but the panel is ' + (shown ? 'shown' : 'hidden'));
        return shown;
    })()`);

/** The rounded viewport boxes of a popover's trigger and panel. */
const popBoxes = (page, id) =>
    page.evaluate(`(() => {
        const host = document.getElementById(${JSON.stringify(id)});
        const round = (r) => ({ top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right) });
        return {
            trigger: round(host.querySelector('[slot=trigger]').getBoundingClientRect()),
            panel: round(host.shadowRoot.querySelector('.pop').getBoundingClientRect()),
            view: { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight },
        };
    })()`);

async function popoverStyles(page) {
    const s = await page.evaluate(`(() => {
        const cs = (id, pseudo) => getComputedStyle(document.getElementById(id), pseudo);
        const list = cs('popAList');
        const item = cs('pa1');
        return {
            list: [list.listStyleType, list.paddingInlineStart, list.marginBlockStart, list.position, list.display],
            item: [item.display, item.backgroundColor, item.borderTopStyle, item.textAlign],
            sep: getComputedStyle(document.querySelector('#popAList .menu-sep')).borderTopWidth,
            mark: [cs('pc1', '::before').content, cs('pc2', '::before').content, cs('pc2', '::before').maskImage !== 'none'],
        };
    })()`);
    assert.deepEqual(s.list, ['none', '0px', '0px', 'static', 'block'], 'a .popover-list has no bullets, padding or menu positioning');
    assert.deepEqual(s.item, ['flex', 'rgba(0, 0, 0, 0)', 'none', 'start'], 'its items look like menu items');
    assert.equal(s.sep, '1px', 'its separator draws a rule');
    assert.deepEqual(s.mark, ['""', '""', true], 'every item keeps the check mark column; the pressed one draws the mark');
}

async function popoverKeys(page) {
    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'Enter');
    assert.equal(await popOpen(page, 'popA'), true, 'Enter opens the popover');
    assert.equal(await active(page), 'pa1', 'with focus on the first item');
    assert.equal(await attr(page, 'popAT', 'aria-expanded'), 'true');
    assert.equal(await attr(page, 'popAT', 'aria-haspopup'), 'dialog');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pa2');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pa3', 'arrows skip a hidden item and the separator');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pa1', 'arrows wrap');
    await press(page, 'ArrowUp');
    assert.equal(await active(page), 'pa3', 'and wrap back');
    await press(page, 'Home');
    assert.equal(await active(page), 'pa1');
    await press(page, 'End');
    assert.equal(await active(page), 'pa3');
    for (const key of ['ArrowDown', 'ArrowUp', 'Home']) {
        await press(page, key, { shift: true });
        assert.equal(await active(page), 'pa3', `Shift+${key} on an item does not move focus`);
    }
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popA'), false, 'Escape closes');
    assert.equal(await active(page), 'popAT', 'and returns focus to the trigger');
    assert.equal(await attr(page, 'popAT', 'aria-expanded'), 'false');
    for (const key of ['ArrowDown', 'ArrowUp']) {
        await press(page, key, { shift: true });
        assert.equal(await popOpen(page, 'popA'), false, `Shift+${key} on the trigger does not open the popover`);
        assert.equal(await active(page), 'popAT');
    }

    await press(page, ' ');
    assert.equal(await popOpen(page, 'popA'), true, 'Space opens');
    assert.equal(await active(page), 'pa1', 'with focus on the first item');
    await press(page, 'Escape');
    await press(page, 'ArrowDown');
    assert.equal(await popOpen(page, 'popA'), true, 'ArrowDown on the trigger opens');
    assert.equal(await active(page), 'pa1', 'on the first item');
    await press(page, 'Escape');
    await press(page, 'ArrowUp');
    assert.equal(await popOpen(page, 'popA'), true, 'ArrowUp on the trigger opens');
    assert.equal(await active(page), 'pa3', 'on the last item');

    await page.evaluate(`window.__popClicked.length = 0`);
    await press(page, 'Enter');
    assert.equal(await popOpen(page, 'popA'), false, 'choosing an item closes');
    assert.equal(await active(page), 'popAT', 'and returns focus to the trigger');
    assert.deepEqual(await page.evaluate('window.__popClicked'), ['pa3'], 'the item still acted');

    await press(page, 'Enter');
    await press(page, 'Tab');
    assert.equal(await active(page), 'pa2', 'Tab walks the items');
    assert.equal(await popOpen(page, 'popA'), true, 'and keeps the popover open');
    await press(page, 'Tab');
    await press(page, 'Tab');
    assert.equal(await popOpen(page, 'popA'), false, 'Tab past the last item closes it');
    assert.equal(await active(page), 'popBT', 'and focus lands on the next element');
    assert.equal(await popOpen(page, 'popB'), false);
}

async function popoverChoose(page) {
    await page.evaluate(`window.__openInHandler = null; window.__popClicked.length = 0`);
    await clickAt(page, '#popAT');
    assert.equal(await popOpen(page, 'popA'), true, 'a click opens');
    await clickAt(page, '#pa1');
    assert.equal(await popOpen(page, 'popA'), false, 'clicking an item closes');
    assert.equal(await page.evaluate('window.__openInHandler'), true, "after the item's own data-on:click ran");
    assert.equal(await active(page), 'popAT', 'and focus returns to the trigger');

    await clickAt(page, '#popBT');
    assert.equal(await popOpen(page, 'popB'), true);
    for (const id of ['pbKeep', 'pbDisabled', 'pbPrevent', 'pbStop']) {
        await clickAt(page, `#${id}`);
        assert.ok((await page.evaluate('window.__popClicked')).includes(id), `#${id} was clicked`);
        assert.equal(await popOpen(page, 'popB'), true, `#${id} keeps the popover open`);
    }
    await clickAt(page, '#pbCheck');
    assert.equal(await page.evaluate(`document.getElementById('pbCheck').checked`), true);
    assert.equal(await popOpen(page, 'popB'), true, 'a checkbox keeps it open');
    await clickAt(page, '#pbSwitch');
    assert.equal(await page.evaluate(`document.getElementById('pbSwitch').checked`), true);
    assert.equal(await popOpen(page, 'popB'), true, 'a switch keeps it open');
    await clickAt(page, '#pbText');
    await press(page, '5');
    assert.equal(await page.evaluate(`document.getElementById('pbText').value`), '5');
    assert.equal(await popOpen(page, 'popB'), true, 'typing keeps it open');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pbText', 'a text field is not a list item');

    await page.evaluate(`document.getElementById('pbKeep').focus()`);
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pbPrevent', 'arrows skip an aria-disabled item');
    await press(page, 'ArrowDown');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pbCheck', 'a checkbox is a list item');
    await press(page, ' ');
    assert.equal(await page.evaluate(`document.getElementById('pbCheck').checked`), false);
    assert.equal(await popOpen(page, 'popB'), true, 'Space on a checkbox keeps it open');
    await press(page, 'End');
    assert.equal(await active(page), 'pbSwitch');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pbKeep', 'the arrows wrap past the text field');
    await press(page, 'Enter');
    assert.equal(await popOpen(page, 'popB'), true, 'Enter on a data-menu-keep item keeps it open');

    await clickAt(page, '#pBefore');
    assert.equal(await popOpen(page, 'popB'), false, 'an outside press closes');
    assert.equal(await active(page), 'pBefore', 'and focus stays where the press put it');
}

async function popoverLayers(page) {
    const menuOpen = () => attr(page, 'dmList', 'data-open');
    await clickAt(page, '#popAT');
    await clickAt(page, '#dmToggle');
    assert.equal(await popOpen(page, 'popA'), false, 'opening a menu by pointer closes the popover');
    assert.equal(await menuOpen(), '');
    await clickAt(page, '#popAT');
    assert.equal(await menuOpen(), null, 'opening a popover by pointer closes the menu');
    assert.equal(await popOpen(page, 'popA'), true);
    await clickAt(page, '#qmToggle');
    assert.equal(await popOpen(page, 'popA'), false, 'and the same with a top-layer menu list');
    assert.equal(await popoverOpen(page, 'qmList'), true);
    await clickAt(page, '#popAT');
    assert.equal(await popoverOpen(page, 'qmList'), false);
    assert.equal(await popOpen(page, 'popA'), true);
    await press(page, 'Escape');

    // EXP-8: popover open, focus to a menu toggle, Enter; then the reverse.
    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'Enter');
    await page.evaluate(`document.getElementById('qmToggle').focus()`);
    await press(page, 'Enter');
    assert.equal(await popoverOpen(page, 'qmList'), true, 'the menu opens by keyboard');
    assert.equal(await popOpen(page, 'popA'), false, 'with the popover closed');
    await press(page, 'Escape');
    assert.equal(await popoverOpen(page, 'qmList'), false, 'so one Escape closes the menu');
    assert.equal(await active(page), 'qmToggle', 'and returns focus to its toggle');
    await press(page, 'Enter');
    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'Enter');
    assert.equal(await popOpen(page, 'popA'), true, 'the popover opens by keyboard');
    assert.equal(await popoverOpen(page, 'qmList'), false, 'with the menu closed');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popA'), false);
    assert.equal(await active(page), 'popAT');

    // Opened without a press or a focus move (a script, the server's open).
    await press(page, 'Enter');
    await page.evaluate(`document.getElementById('dmToggle').click()`);
    assert.equal(await menuOpen(), '', 'a menu opened by script');
    assert.equal(await popOpen(page, 'popA'), false, 'closes the popover');
    await page.evaluate(`document.getElementById('popA').show()`);
    assert.equal(await popOpen(page, 'popA'), true, 'a popover opened by script');
    assert.equal(await menuOpen(), null, 'closes the menu');
    await page.evaluate(`document.getElementById('popB').show()`);
    assert.equal(await popOpen(page, 'popB'), true, 'a second popover opened by script');
    assert.equal(await popOpen(page, 'popA'), false, 'closes the first');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popB'), false);
    assert.equal(await active(page), 'popBT');
}

async function popoverUnderModal(page) {
    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'Enter');
    // Focus off the panel (a press on its padding, a morph's parking), so focus moving into
    // the dialog cannot be what closes the popover.
    await page.evaluate(`document.activeElement.blur()`);
    assert.equal(await popOpen(page, 'popA'), true, 'focus going nowhere keeps the popover open');
    await page.evaluate(`(() => {
        const dialog = document.getElementById('fxDialog');
        window.__cancels = 0;
        dialog.addEventListener('cancel', () => window.__cancels++);
        dialog.showModal();
    })()`);
    await page.waitFor(`document.getElementById('popA').open === false`, { timeout: 2000, label: 'the modal dialog to close the popover' });
    assert.equal(await popOpen(page, 'popA'), false);
    await press(page, 'Escape');
    assert.equal(await page.evaluate(`document.getElementById('fxDialog').open`), false, 'the next Escape closes the dialog');
    assert.equal(await page.evaluate('window.__cancels'), 1);
}

async function popoverMove(page) {
    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'ArrowDown');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pa2');
    await page.evaluate(`document.getElementById('fxpMoved').moveBefore(document.getElementById('popA'), null)`);
    await sleep(150);
    assert.equal(await page.evaluate(`document.getElementById('popA').parentElement.id`), 'fxpMoved');
    assert.equal(await popOpen(page, 'popA'), true, 'a host moved with moveBefore stays open');
    assert.equal(await active(page), 'pa2', 'with focus where it was');
    assert.equal(await attr(page, 'popAT', 'aria-expanded'), 'true');
    const { trigger, panel } = await popBoxes(page, 'popA');
    const beside = panel.top >= trigger.bottom ? panel.top - trigger.bottom : trigger.top - panel.bottom;
    assert.ok(beside >= 0 && beside <= 12, `the panel follows its trigger: ${JSON.stringify({ trigger, panel })}`);
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pa3', 'the list keys still work');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popA'), false);
    assert.equal(await active(page), 'popAT');
    await page.evaluate(`document.getElementById('fxp').moveBefore(document.getElementById('popA'), document.getElementById('popB'))`);
}

// The fixture sits in #client-root, which the morph skips: this covers a morph around an open
// popover. A morph that re-sends the host is checked by the page tests on server-rendered hosts.
async function popoverSyncAround(page) {
    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'ArrowUp');
    assert.equal(await active(page), 'pa3');
    await page.syncNow('overview');
    assert.equal(await popOpen(page, 'popA'), true, 'a sync morph of #page-content, outside the host, leaves the popover open');
    assert.equal(await active(page), 'pa3', 'and focus on the same item');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popA'), false);
}

// Forced colors: a .popover-list item gets a focus ring, and a pressed one keeps its state
// ring and its check mark.
async function popoverForcedColors(page) {
    await page.evaluate(`document.getElementById('popA').show()`);
    const ring = await ringBeforeAndAfter(page, { target: '#pa2', away: '#pa1' });
    assert.ok(ring.fv, '#pa2 gets :focus-visible');
    assert.equal(ring.before.split(' ')[0], 'none');
    const [style, width] = ring.after.split(' ');
    assert.ok(style !== 'none' && parseFloat(width) >= 2, `a .popover-list item shows a focus ring: ${ring.after}`);
    await press(page, 'Escape');

    await page.evaluate(`document.getElementById('popC').show()`);
    const pressed = await ringBeforeAndAfter(page, { target: '#pc2', away: '#pc1' });
    assert.ok(pressed.fv, '#pc2 gets :focus-visible');
    assert.notEqual(pressed.before.split(' ')[0], 'none', `a pressed popover item shows its state without focus: ${pressed.before}`);
    assert.notEqual(pressed.after, pressed.before, `focus on it shows over its state ring: ${pressed.before} -> ${pressed.after}`);
    const mark = await page.evaluate(`(() => {
        const s = getComputedStyle(document.getElementById('pc2'), '::before');
        return { content: s.content, mask: s.maskImage, adjust: s.forcedColorAdjust, width: s.width };
    })()`);
    assert.ok(mark.content === '""' && mark.mask !== 'none' && mark.adjust === 'none' && parseFloat(mark.width) > 0, `the check mark stays drawn: ${JSON.stringify(mark)}`);
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popC'), false);
}

const PHONE = `
<div style="position: fixed; inset-block-start: 5rem; inset-inline-end: 0.5rem; z-index: 40">
  <sb-popover id="popR" label="Export" placement="bottom-start">
    <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup" id="popRT">Export</button>
    <ul class="popover-list">${['CSV', 'JSON', 'Print the table', 'Copy the nfdump command'].map((t) => `<li><button type="button" class="menu-item">${t}</button></li>`).join('')}</ul>
  </sb-popover>
</div>
<div style="position: fixed; inset-block-end: 6rem; inset-inline-start: 0.5rem; z-index: 40">
  <sb-popover id="popL" label="Columns to show" placement="bottom-end">
    <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup" id="popLT">Columns</button>
    <ul class="popover-list">${Array.from({ length: 16 }, (_, i) => `<li><label><input type="checkbox" checked> Column number ${i + 1}</label></li>`).join('')}</ul>
  </sb-popover>
</div>`;

/** At 390 x 844 a panel near either edge lies inside the viewport and its trigger does not move. */
async function popoverPhone(page) {
    await page.evaluate(`(() => {
        document.getElementById('client-root').insertAdjacentHTML('beforeend', ${JSON.stringify(PHONE)});
        document.getElementById('alerts-toast-container').style.display = 'none';
    })()`);
    await page.waitFor(`['popR', 'popL'].every((id) => document.getElementById(id).shadowRoot)`, { label: 'the phone popovers to render' });
    for (const id of ['popR', 'popL']) {
        const before = await popBoxes(page, id);
        await tapAt(page, `#${id}T`);
        assert.equal(await popOpen(page, id), true, `a tap opens #${id}`);
        const { trigger, panel, view } = await popBoxes(page, id);
        assert.equal(view.width, 390);
        assert.deepEqual(trigger, before.trigger, `#${id}'s trigger stays where it was`);
        const inside = panel.left >= 0 && panel.top >= 0 && panel.right <= view.width && panel.bottom <= view.height;
        assert.ok(inside && panel.bottom > panel.top, `#${id}'s panel lies inside the viewport: ${JSON.stringify({ panel, view })}`);
        await page.evaluate(`document.getElementById(${JSON.stringify(id)}).hide()`);
    }
}

const EARLY = `
<div style="position: fixed; inset-block-start: 5rem; inset-inline-start: 1rem; z-index: 40">
  <sb-popover id="popE" label="Export" placement="bottom-start">
    <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup" id="popET">Export</button>
    <ul class="popover-list"><li><button type="button" class="menu-item" id="pe1">CSV</button></li><li><button type="button" class="menu-item" id="pe2">JSON</button></li></ul>
  </sb-popover>
</div>`;

/** Until Starbase defines sb-popover (a slow or failed load) its trigger is a plain button that N3 leaves alone. */
async function popoverBeforeUpgrade(page, src) {
    assert.equal(await page.evaluate(`!!customElements.get('sb-popover')`), false, 'the popover module is held back');
    await page.evaluate(`document.getElementById('client-root').insertAdjacentHTML('beforeend', ${JSON.stringify(EARLY)})`);
    await page.evaluate(`document.getElementById('popET').focus()`);
    for (const key of ['ArrowDown', 'ArrowUp']) {
        await press(page, key);
        assert.equal(await active(page), 'popET', `${key} on the trigger of an undefined host keeps focus there`);
    }
    assert.deepEqual(page.realErrors(), [], 'and throws nothing');

    await page.send('Network.setBlockedURLs', { urls: [] });
    await page.evaluate(`import(${JSON.stringify(`${src}?late`)}).then(() => true)`);
    await page.waitFor(`typeof document.getElementById('popE').show === 'function'`, { label: 'sb-popover to upgrade' });
    await page.evaluate(`document.getElementById('popET').focus()`);
    await press(page, 'ArrowUp');
    assert.equal(await popOpen(page, 'popE'), true, 'once upgraded, ArrowUp on the trigger opens it');
    assert.equal(await active(page), 'pe2', 'on the last item');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popE'), false);
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
            await popoverStyles(page);
            await popoverKeys(page);
            await popoverChoose(page);
            await popoverLayers(page);
            await popoverUnderModal(page);
            await popoverMove(page);
            await popoverSyncAround(page);
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
        await popoverForcedColors(page);
        assert.deepEqual(page.realErrors(), []);
    });

    await withPage(
        async (page) => {
            await page.navigate(BASE + '/');
            await page.waitForBoot();
            await sleep(800);
            await popoverPhone(page);
            assert.deepEqual(page.realErrors(), []);
        },
        { width: 390, height: 844, mobile: true }
    );

    await withPage(async (page) => {
        await page.send('Network.enable');
        await page.send('Network.setBlockedURLs', { urls: ['*/js/starbase/popover@*'] });
        await page.navigate(BASE + '/');
        await page.waitForBoot();
        const src = await page.evaluate(`[...document.scripts].find((s) => /\\/js\\/starbase\\/popover@[0-9a-f]{12}\\/popover\\.js$/.test(s.src))?.src`);
        assert.ok(src, 'the layout loads sb-popover from the Starbase lock');
        await popoverBeforeUpgrade(page, src);
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
