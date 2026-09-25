// Tab and menu behaviour for ui.css (spec 2.5). Opt-ins: data-activation="manual" on a tablist
// whose clicks post, data-menu-keep on a menu button that must not close its menu.

const TABLIST = '[role="tablist"]';
const TAB = '[role="tab"]';
const MENU = '.menu';
const ITEM = '[role="menuitem"]';

// ── Tabs ────────────────────────────────────────────────────────────────────

function isUsable(el) {
    return !el.hidden && !el.disabled && el.getAttribute('aria-disabled') !== 'true';
}

/** Tabs that belong to this tablist, not to one nested inside it. */
function tabsOf(list) {
    return [...list.querySelectorAll(TAB)].filter((tab) => tab.closest(TABLIST) === list && isUsable(tab));
}

function selectedTab(list) {
    return tabsOf(list).find((tab) => tab.getAttribute('aria-selected') === 'true') ?? null;
}

/**
 * Make `current` (default: the selected tab) the only Tab stop of the list. A list that marks
 * no selection with aria-selected keeps its native Tab stops: nothing could move a roving one.
 */
function syncRoving(list, current = selectedTab(list)) {
    const tabs = tabsOf(list);
    if (!current || !tabs.some((tab) => tab.hasAttribute('aria-selected'))) return;
    for (const tab of tabs) {
        const index = tab === current ? '0' : '-1';
        if (tab.getAttribute('tabindex') !== index) tab.setAttribute('tabindex', index);
    }
}

function onTabKeydown(event, tab) {
    const list = tab.closest(TABLIST);
    if (!list) return;
    const tabs = tabsOf(list);
    const index = tabs.indexOf(tab);
    if (index === -1) return;

    const vertical = list.getAttribute('aria-orientation') === 'vertical';
    const rtl = getComputedStyle(list).direction === 'rtl';
    const forward = vertical ? 'ArrowDown' : rtl ? 'ArrowLeft' : 'ArrowRight';
    const back = vertical ? 'ArrowUp' : rtl ? 'ArrowRight' : 'ArrowLeft';

    let next;
    if (event.key === forward) next = tabs[(index + 1) % tabs.length];
    else if (event.key === back) next = tabs[(index - 1 + tabs.length) % tabs.length];
    else if (event.key === 'Home') next = tabs[0];
    else if (event.key === 'End') next = tabs[tabs.length - 1];
    else return;

    event.preventDefault();
    next.focus();
    if (list.dataset.activation !== 'manual') {
        syncRoving(list, next);
        if (next.getAttribute('aria-selected') !== 'true') next.click();
    }
}

// The pointer press in progress, kept until its click has run: a touch tap focuses its
// target only after touchend, and an auto popover is light-dismissed before the click.
let press = null;

function onPointerdownCapture(event) {
    const parts = partsOf(event.target.closest?.(MENU));
    const onOpenToggle = parts?.toggle.contains(event.target) && isOpen(parts);
    press = { openToggle: onOpenToggle ? parts.toggle : null };
}

function endPress() {
    press = null;
}

// Focus that enters a tablist from outside lands on the selected tab, also in markup that
// did not render the roving tabindex itself. A pointer click keeps its own target.
function onTabFocusin(event) {
    const tab = event.target.closest?.(TAB);
    const list = tab?.closest(TABLIST);
    if (!list) return;
    const entering = !(event.relatedTarget instanceof Node && list.contains(event.relatedTarget));
    const selected = selectedTab(list);
    if (entering && !press && selected && selected !== tab) {
        syncRoving(list, selected);
        selected.focus();
        return;
    }
    if (!tab.hasAttribute('tabindex')) syncRoving(list);
}

function onTabClick(event) {
    const tab = event.target.closest?.(TAB);
    const list = tab?.closest(TABLIST);
    if (list && isUsable(tab)) syncRoving(list, tab);
}

// A selection made by a signal or a morph moves the Tab stop with it.
function onSelectionMutation(records) {
    for (const { target } of records) {
        if (!(target instanceof Element) || target.getAttribute('aria-selected') !== 'true') continue;
        const list = target.matches(TAB) ? target.closest(TABLIST) : null;
        if (list) syncRoving(list, target);
    }
}

function initTablists() {
    for (const list of document.querySelectorAll(TABLIST)) syncRoving(list);
}

// ── Menus ───────────────────────────────────────────────────────────────────

/** Descendants of `menu` matching `selector` that are not inside a nested menu. */
function own(menu, selector) {
    return [...menu.querySelectorAll(selector)].filter((el) => el.closest(MENU) === menu);
}

/** The toggle and list of a menu this module drives, or null. */
function partsOf(menu) {
    if (!menu?.matches(MENU)) return null;
    const action = menu.dataset.role === 'menu';
    const toggle = own(menu, action ? '[aria-haspopup="menu"]' : '.menu-toggle[aria-controls]')[0];
    if (!toggle) return null;
    const controls = toggle.getAttribute('aria-controls');
    const list = (controls && document.getElementById(controls)) || own(menu, action ? '[role="menu"]' : '.menu-list')[0] || null;
    return list ? { menu, toggle, list, action } : null;
}

function isOpen({ list }) {
    return list.hasAttribute('popover') ? list.matches(':popover-open') : list.hasAttribute('data-open');
}

const GAP = 4;

/**
 * Pin a top-layer list under its toggle, or above it when the list does not fit below and
 * there is more room above, capped to that room. End-aligned unless data-align=start.
 */
function place({ toggle, list }) {
    const { clientWidth: viewWidth, clientHeight: viewHeight } = document.documentElement;
    const anchor = toggle.getBoundingClientRect();
    const width = list.offsetWidth;
    const height = list.scrollHeight + list.offsetHeight - list.clientHeight;
    const rtl = getComputedStyle(toggle).direction === 'rtl';
    const alignStart = list.dataset.align === 'start';
    let left = alignStart !== rtl ? anchor.left : anchor.right - width;
    left = Math.min(Math.max(GAP, left), viewWidth - width - GAP);
    list.style.setProperty('--menu-left', `${Math.round(left)}px`);

    const below = viewHeight - anchor.bottom - 2 * GAP;
    const above = anchor.top - 2 * GAP;
    const up = height > below && above > below;
    if (up) {
        list.style.removeProperty('--menu-top');
        list.style.setProperty('--menu-bottom', `${Math.round(viewHeight - anchor.top + GAP)}px`);
    } else {
        list.style.removeProperty('--menu-bottom');
        list.style.setProperty('--menu-top', `${Math.round(anchor.bottom + GAP)}px`);
    }
    list.style.setProperty('--menu-room', `${Math.max(0, Math.floor(up ? above : below))}px`);
}

/** Whether some of `el` shows through the viewport and the ancestors that clip it. */
function inView(el) {
    const box = el.getBoundingClientRect();
    const clip = { top: 0, left: 0, bottom: document.documentElement.clientHeight, right: document.documentElement.clientWidth };
    for (let up = el.parentElement; up && up !== document.body; up = up.parentElement) {
        const style = getComputedStyle(up);
        if (style.overflowX !== 'visible' || style.overflowY !== 'visible') {
            const r = up.getBoundingClientRect();
            clip.top = Math.max(clip.top, r.top);
            clip.left = Math.max(clip.left, r.left);
            clip.bottom = Math.min(clip.bottom, r.bottom);
            clip.right = Math.min(clip.right, r.right);
        }
        if (style.position === 'fixed') break;
    }
    return box.bottom > clip.top && box.top < clip.bottom && box.right > clip.left && box.left < clip.right;
}

// Open top-layer lists by list element. They are position: fixed, so a scroll or resize
// re-places them, and one whose toggle has scrolled out of sight closes.
const floating = new Map();
let frame = 0;

function onViewportChange() {
    if (!floating.size || frame) return;
    frame = requestAnimationFrame(() => {
        frame = 0;
        for (const [list, parts] of floating) {
            if (!list.isConnected || !isOpen(parts)) {
                floating.delete(list);
            } else if (inView(parts.toggle)) {
                place(parts);
            } else {
                const hadFocus = list.contains(document.activeElement);
                close(parts);
                if (hadFocus) parts.toggle.focus({ preventScroll: true });
            }
        }
    });
}

function items(parts) {
    return [...parts.list.querySelectorAll(ITEM)].filter(isUsable);
}

function focusItem(parts, which) {
    const all = items(parts);
    if (!all.length) return;
    const current = all.indexOf(document.activeElement);
    let index;
    if (which === 'first') index = 0;
    else if (which === 'last') index = all.length - 1;
    else if (which === 'next') index = (current + 1) % all.length;
    else index = (current - 1 + all.length) % all.length;
    for (const item of all) item.setAttribute('tabindex', '-1');
    all[index].focus();
}

function open(parts, { focus } = {}) {
    for (const other of openMenus()) {
        if (other.menu !== parts.menu && !other.menu.contains(parts.menu)) close(other);
    }
    if (parts.list.hasAttribute('popover')) {
        if (!parts.list.matches(':popover-open')) parts.list.showPopover();
        place(parts);
        floating.set(parts.list, parts);
    } else {
        parts.list.setAttribute('data-open', '');
    }
    parts.toggle.setAttribute('aria-expanded', 'true');
    if (parts.action) focusItem(parts, focus ?? 'first');
}

function close(parts, { returnFocus = false } = {}) {
    if (parts.list.hasAttribute('popover')) {
        floating.delete(parts.list);
        if (parts.list.matches(':popover-open')) parts.list.hidePopover();
    } else {
        parts.list.removeAttribute('data-open');
    }
    parts.toggle.setAttribute('aria-expanded', 'false');
    if (returnFocus) parts.toggle.focus();
}

/** Open menus this module drives, innermost last. */
function openMenus() {
    return [...document.querySelectorAll(MENU)].map(partsOf).filter((parts) => parts && isOpen(parts));
}

function onMenuClick(event) {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const menu = target.closest(MENU);
    const parts = partsOf(menu);
    if (!parts) return;

    if (parts.toggle.contains(target)) {
        if (isOpen(parts) || press?.openToggle === parts.toggle) close(parts);
        else open(parts);
        return;
    }

    const item = target.closest('button, a[href], [role="menuitem"]');
    if (item && parts.list.contains(item) && !item.hasAttribute('data-menu-keep') && isOpen(parts)) {
        // Runs after the item's own handler: document is the last stop of the bubble.
        close(parts, { returnFocus: parts.list.contains(document.activeElement) || document.activeElement === document.body });
    }
}

// Outside press closes, before the press does anything else.
function onMenuPointerdownCapture(event) {
    for (const parts of openMenus()) {
        if (!parts.menu.contains(event.target) && !parts.list.contains(event.target)) close(parts);
    }
}

function onMenuFocusout(event) {
    const next = event.relatedTarget;
    if (!(next instanceof Node)) return;
    for (const parts of openMenus()) {
        if (parts.menu.contains(event.target) && !parts.menu.contains(next) && !parts.list.contains(next)) close(parts);
    }
}

// A popover list can also close itself (light dismiss); keep the toggle's state honest.
function onPopoverToggleCapture(event) {
    const list = event.target;
    if (!(list instanceof Element) || !list.hasAttribute('popover')) return;
    if (event.newState !== 'open') floating.delete(list);
    const parts = partsOf(list.closest(MENU));
    if (parts && parts.list === list) parts.toggle.setAttribute('aria-expanded', String(event.newState === 'open'));
}

function onMenuKeydown(event) {
    const target = event.target;
    if (!(target instanceof Element)) return false;

    if (event.key === 'Escape') {
        const menus = openMenus();
        const inner = menus.filter((parts) => parts.menu.contains(target)).pop() ?? menus.pop();
        if (!inner) return false;
        event.preventDefault();
        event.stopPropagation();
        close(inner, { returnFocus: inner.menu.contains(target) || target === document.body });
        return true;
    }

    const parts = partsOf(target.closest(MENU));
    if (!parts?.action) return false;

    if (target === parts.toggle && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        event.preventDefault();
        open(parts, { focus: event.key === 'ArrowDown' ? 'first' : 'last' });
        return true;
    }

    if (!target.matches(ITEM) || !parts.list.contains(target)) return false;
    const moves = { ArrowDown: 'next', ArrowUp: 'previous', Home: 'first', End: 'last' };
    if (moves[event.key]) {
        event.preventDefault();
        focusItem(parts, moves[event.key]);
        return true;
    }
    if (event.key === 'Tab') {
        // Focus goes back to the toggle, then the Tab itself moves on from there.
        close(parts, { returnFocus: true });
        return true;
    }
    return false;
}

function onKeydown(event) {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
    if (onMenuKeydown(event)) return;
    const tab = event.target.closest?.(TAB);
    if (tab) onTabKeydown(event, tab);
}

// ── Install ─────────────────────────────────────────────────────────────────

// Once per page, even if the module is reached through a second URL.
if (!window.__nfsenControls) {
    window.__nfsenControls = true;
    document.addEventListener('pointerdown', onPointerdownCapture, true);
    document.addEventListener('pointerdown', onMenuPointerdownCapture, true);
    document.addEventListener('pointercancel', endPress, true);
    document.addEventListener('keydown', endPress, true);
    document.addEventListener('click', () => setTimeout(endPress), true);
    document.addEventListener('toggle', onPopoverToggleCapture, true);
    document.addEventListener('scroll', onViewportChange, { capture: true, passive: true });
    window.addEventListener('resize', onViewportChange, { passive: true });
    document.addEventListener('focusin', onTabFocusin);
    document.addEventListener('focusout', onMenuFocusout);
    document.addEventListener('click', onTabClick);
    document.addEventListener('click', onMenuClick);
    document.addEventListener('keydown', onKeydown);
    new MutationObserver(onSelectionMutation).observe(document.documentElement, {
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-selected'],
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initTablists, { once: true });
    else initTablists();
}
