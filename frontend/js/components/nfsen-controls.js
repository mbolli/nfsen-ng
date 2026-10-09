// Tab and sb-popover behaviour for ui.css (spec 2.5). Opt-ins: data-activation="manual" on a
// tablist whose clicks post, data-menu-keep on a popover button that must not close it.

const TABLIST = '[role="tablist"]';
const TAB = '[role="tab"]';
const POPOVER = 'sb-popover';
const LIST = '.popover-list';
const LIST_ITEM = 'button, a[href], input[type="checkbox"]';

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

// A pointer press in progress, kept until its click has run: a touch tap focuses its target
// only after touchend.
let press = false;

function onPointerdownCapture() {
    press = true;
}

function endPress() {
    press = false;
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

// ── Popovers ───────────────────────────────────────────────────────────────

// sb-popover handles opening, placement, Escape and outside presses; this adds the rest of
// the menu contract: close on choose and when focus leaves, list keys, one open popover.

function openPopovers() {
    return [...document.querySelectorAll(POPOVER)].filter((host) => host.open === true);
}

/** The host's own .popover-list, not one of a popover nested in its panel. */
function listOf(host) {
    return [...host.querySelectorAll(LIST)].find((list) => list.closest(POPOVER) === host) ?? null;
}

function listItems(list) {
    return [...list.querySelectorAll(LIST_ITEM)].filter((el) => el.closest(LIST) === list && isUsable(el) && el.checkVisibility());
}

function onPopoverClick(event) {
    const item = event.target.closest?.('button, a[href]');
    const host = item?.closest(POPOVER);
    if (!host?.open || event.defaultPrevented) return;
    if (item.closest('[slot="trigger"]') || item.hasAttribute('data-menu-keep') || item.getAttribute('aria-disabled') === 'true') return;
    // Runs after the item's own handler: document is the last stop of the bubble.
    host.hide();
}

function onPopoverFocusout(event) {
    const next = event.relatedTarget;
    if (!(next instanceof Node)) return;
    for (let host = event.target.closest?.(POPOVER); host; host = host.parentElement?.closest(POPOVER)) {
        if (host.open && !host.contains(next)) host.hide();
    }
}

function onPopoverKeydown(event) {
    const target = event.target;
    if (!(target instanceof Element) || event.shiftKey) return false;
    const { key } = event;

    const parent = target.getAttribute('slot') === 'trigger' ? target.parentElement : null;
    // Until Starbase defines sb-popover the host has no show(), and its trigger is a plain button.
    const host = parent?.matches(POPOVER) && typeof parent.show === 'function' ? parent : null;
    const hostList = host && listOf(host);
    if (hostList && (key === 'ArrowDown' || key === 'ArrowUp')) {
        event.preventDefault();
        const which = key === 'ArrowDown' ? 0 : -1;
        const edge = () => listItems(hostList).at(which)?.focus();
        if (host.open) {
            edge();
        } else {
            host.show();
            // After the popover's own focus move, which it queues as a microtask.
            queueMicrotask(edge);
        }
        return true;
    }

    const list = target.closest(LIST);
    if (!list || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(key)) return false;
    const all = listItems(list);
    const index = all.indexOf(target);
    if (index === -1) return false;
    event.preventDefault();
    const next = {
        ArrowDown: (index + 1) % all.length,
        ArrowUp: (index - 1 + all.length) % all.length,
        Home: 0,
        End: all.length - 1,
    }[key];
    all[next].focus();
    return true;
}

// A popover that opens closes the others, except the ones it sits in or holds.
function onPopoverOpen(event) {
    const host = event.target;
    if (!(host instanceof Element) || !host.matches(POPOVER)) return;
    for (const other of openPopovers()) {
        if (other !== host && !other.contains(host) && !host.contains(other)) other.hide();
    }
}

// A popover's Escape listener would still take the first Escape once a modal covers it.
function onDialogToggleCapture(event) {
    const dialog = event.target;
    if (!(dialog instanceof HTMLDialogElement) || event.newState !== 'open' || !dialog.matches(':modal')) return;
    for (const host of openPopovers()) {
        if (!dialog.contains(host)) host.hide();
    }
}

// An sb-modal's <dialog> is in its shadow root, so its toggle never reaches the document: it says sb-open.
function onModalOpen(event) {
    if (event.target.localName !== 'sb-modal') return;
    for (const host of openPopovers()) {
        if (!event.target.contains(host)) host.hide();
    }
}

function onKeydown(event) {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
    if (onPopoverKeydown(event)) return;
    const tab = event.target.closest?.(TAB);
    if (tab) onTabKeydown(event, tab);
}

// ── Install ─────────────────────────────────────────────────────────────────

// Once per page, even if the module is reached through a second URL.
if (!window.__nfsenControls) {
    window.__nfsenControls = true;
    document.addEventListener('pointerdown', onPointerdownCapture, true);
    document.addEventListener('pointercancel', endPress, true);
    document.addEventListener('keydown', endPress, true);
    document.addEventListener('click', () => setTimeout(endPress), true);
    document.addEventListener('toggle', onDialogToggleCapture, true);
    document.addEventListener('sb-open', onModalOpen);
    document.addEventListener('focusin', onTabFocusin);
    document.addEventListener('focusout', onPopoverFocusout);
    document.addEventListener('click', onTabClick);
    document.addEventListener('click', onPopoverClick);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('sb-open', onPopoverOpen);
    new MutationObserver(onSelectionMutation).observe(document.documentElement, {
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-selected'],
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initTablists, { once: true });
    else initTablists();
}
