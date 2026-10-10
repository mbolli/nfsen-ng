// The shared controls of the design system (spec 2.2, 2.4, 2.5): the tab keyboard model and the
// sb-popover layer from nfsen-controls.js, focus rings in every theme and in forced colors, and the
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
    <sb-popover id="popN" label="Nested" placement="end-start">
      <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup" id="popNT">More</button>
      <ul class="popover-list"><li><button type="button" class="menu-item" id="pn1">Inner</button></li></ul>
    </sb-popover>
  </sb-popover>
  <button id="pAfter">after popovers</button>
</div>
<div id="fxpMoved" style="position: fixed; inset-block-end: 6rem; inset-inline-start: 1rem; z-index: 40"></div>
<sb-modal id="fxDialog" heading="Fixture dialog"><button id="fxDialogOk">OK</button></sb-modal>`;

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
        fx.addEventListener('click', (e) => {
            if (e.target.closest('a[href="#"]')) e.preventDefault();
        });
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
    await clickAt(page, '#popAT');
    await clickAt(page, '#popBT');
    assert.equal(await popOpen(page, 'popB'), true, 'a click opens a second popover');
    assert.equal(await popOpen(page, 'popA'), false, 'and closes the first');
    await clickAt(page, '#popAT');
    assert.equal(await popOpen(page, 'popA'), true);
    assert.equal(await popOpen(page, 'popB'), false, 'and the reverse');
    await clickAt(page, '#popAT');
    assert.equal(await popOpen(page, 'popA'), false, 'a second click on the trigger closes it');
    assert.equal(await active(page), 'popAT');

    // EXP-8 with two popovers: focus off the open one, so only N4 can close it.
    await page.evaluate(`document.getElementById('popA').show()`);
    await page.evaluate(`document.activeElement.blur()`);
    await page.evaluate(`document.getElementById('popBT').focus()`);
    assert.equal(await popOpen(page, 'popA'), true, 'focus going nowhere keeps a popover open');
    await press(page, 'Enter');
    assert.equal(await popOpen(page, 'popB'), true, 'a popover opened by keyboard');
    assert.equal(await popOpen(page, 'popA'), false, 'closes the open one');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popB'), false, 'so one Escape closes the new one');
    assert.equal(await active(page), 'popBT', 'and returns focus to its trigger');

    // Opened without a press or a focus move (a script, the server's open).
    await page.evaluate(`document.getElementById('popA').show()`);
    assert.equal(await popOpen(page, 'popA'), true, 'a popover opened by script');
    await page.evaluate(`document.getElementById('popB').show()`);
    assert.equal(await popOpen(page, 'popB'), true, 'a second popover opened by script');
    assert.equal(await popOpen(page, 'popA'), false, 'closes the first');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popB'), false);
    assert.equal(await active(page), 'popBT');

    // A popover in another one's panel keeps that one open, and Escape closes the inner one first.
    await page.evaluate(`document.getElementById('popC').show()`);
    await page.evaluate(`document.getElementById('popNT').focus()`);
    await press(page, 'Enter');
    assert.equal(await popOpen(page, 'popN'), true, 'a nested popover opens');
    assert.equal(await popOpen(page, 'popC'), true, 'and keeps the popover it sits in open');
    assert.equal(await active(page), 'pn1');
    await press(page, 'Enter');
    assert.equal(await popOpen(page, 'popN'), false, 'choosing in the nested popover closes it');
    assert.equal(await popOpen(page, 'popC'), true, 'and only it');
    assert.equal(await active(page), 'popNT');
    await press(page, 'ArrowDown');
    assert.equal(await active(page), 'pn1', 'ArrowDown on the nested trigger opens it');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popN'), false, 'Escape closes the nested popover');
    assert.equal(await popOpen(page, 'popC'), true, 'not the outer one');
    assert.equal(await active(page), 'popNT');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popC'), false, 'the next Escape closes the outer one');
    assert.equal(await active(page), 'popCT');
}

async function popoverUnderModal(page) {
    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'Enter');
    // Focus off the panel (a press on its padding, a morph's parking), so focus moving into
    // the dialog cannot be what closes the popover.
    await page.evaluate(`document.activeElement.blur()`);
    assert.equal(await popOpen(page, 'popA'), true, 'focus going nowhere keeps the popover open');
    await page.evaluate(`(async () => {
        await customElements.whenDefined('sb-modal');
        const modal = document.getElementById('fxDialog');
        window.__escapes = 0;
        modal.addEventListener('sb-close', (e) => e.detail.reason === 'escape' && window.__escapes++);
        modal.show();
    })()`);
    await page.waitFor(`document.getElementById('popA').open === false`, { timeout: 2000, label: 'the modal to close the popover' });
    assert.equal(await popOpen(page, 'popA'), false);
    await press(page, 'Escape');
    assert.equal(await page.evaluate(`document.getElementById('fxDialog').isOpen`), false, 'the next Escape closes the modal');
    assert.equal(await page.evaluate('window.__escapes'), 1);
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

// nfsen-router leaves focus in an open popover on a page switch, and moves it to the heading otherwise.
async function popoverPageSwitch(page) {
    const from = await page.evaluate(`location.hash.replace(/^#\\//, '')`);
    const to = from === 'health' ? 'alerts' : 'health';
    const go = async (id) => {
        await page.evaluate(`location.hash = ${JSON.stringify(`#/${id}`)}`);
        await page.waitForPage(id, { timeout: 15000 });
        await sleep(300);
    };
    const onHeading = (id, label) => page.waitFor(`!!document.activeElement?.matches('[data-page-heading=${JSON.stringify(id)}] h1')`, { label });

    await page.evaluate(`document.getElementById('popAT').focus()`);
    await press(page, 'Enter');
    assert.equal(await active(page), 'pa1');
    await go(to);
    assert.equal(await popOpen(page, 'popA'), true, 'a page switch leaves the popover open');
    assert.equal(await active(page), 'pa1', 'and the focus in it');
    await press(page, 'Escape');
    assert.equal(await active(page), 'popAT');

    await page.evaluate(`document.getElementById('popC').show()`);
    await page.evaluate(`document.getElementById('popNT').focus()`);
    await go(from);
    assert.equal(await popOpen(page, 'popC'), true, 'a page switch leaves the outer popover open');
    assert.equal(await active(page), 'popNT', 'with focus on the closed nested trigger in its panel');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popC'), false);
    assert.equal(await active(page), 'popCT');

    await go(to);
    await onHeading(to, 'focus on a closed popover trigger to move to the page heading');
    await go(from);
    await onHeading(from, 'and again on the way back');
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

/**
 * Until Starbase defines sb-popover (a slow or failed load) every host shows only its trigger (starbase.css), and the
 * trigger is a plain button that N3 leaves alone.
 */
async function popoverBeforeUpgrade(page, src) {
    assert.equal(await page.evaluate(`!!customElements.get('sb-popover')`), false, 'the popover module is held back');
    await page.evaluate(`document.getElementById('client-root').insertAdjacentHTML('beforeend', ${JSON.stringify(EARLY)})`);
    const early = await page.evaluate(`(() => {
        const hosts = [...document.querySelectorAll('sb-popover')];
        return {
            hosts: hosts.length,
            shown: hosts.flatMap((h) => [...h.children].filter((c) => c.slot !== 'trigger' && getComputedStyle(c).display !== 'none').map(() => h.id)),
            trigger: document.getElementById('popET').getClientRects().length > 0,
        };
    })()`);
    assert.ok(early.hosts > 1, `the page has popovers besides the fixture: ${early.hosts}`);
    assert.deepEqual(early.shown, [], 'no undefined host shows its panel content');
    assert.equal(early.trigger, true, 'the trigger of an undefined host shows');
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
    assert.equal(await page.evaluate(`getComputedStyle(document.querySelector('#popE .popover-list')).display`), 'block', 'and its list shows in the panel');
    await press(page, 'Escape');
    assert.equal(await popOpen(page, 'popE'), false);
}

async function focusRings(page) {
    const selectors = [
        '#before',
        '#ta1',
        '#popAT',
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
    ];
    for (const c of cases) {
        const r = await ringBeforeAndAfter(page, c);
        assert.ok(r.fv, `${c.target} gets :focus-visible`);
        assert.notEqual(r.before.split(' ')[0], 'none', `${c.target} shows its state without focus: ${r.before}`);
        assert.notEqual(r.after, r.before, `focus on ${c.target} shows over its state ring: ${r.before} -> ${r.after}`);
    }

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
            await popoverStyles(page);
            await popoverKeys(page);
            await popoverChoose(page);
            await popoverLayers(page);
            await popoverUnderModal(page);
            await popoverMove(page);
            await popoverSyncAround(page);
            await popoverPageSwitch(page);
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
