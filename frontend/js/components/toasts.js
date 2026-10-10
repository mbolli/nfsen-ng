/**
 * window.showMessage(level, message, autoDismiss, containerSelector): a toast in Starbase's sb-toast. Each stack is an
 * sb-toast in a data-ignore-morph container, its list on the toasts attribute; a dismissed toast leaves the list.
 */

const VARIANTS = { success: 'ok', info: 'info', warning: 'warn', error: 'danger' };
let next = 0;

/** The region's list, oldest first. */
function listOf(region) {
    try {
        const list = JSON.parse(region.getAttribute('toasts') ?? '[]');
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

/** containerSelector's region, else the top open modal's own (a modal makes the page inert), else the shell's. */
function regionFor(containerSelector) {
    const named = containerSelector && document.querySelector(containerSelector);
    if (named) return named.localName === 'sb-toast' ? named : named.querySelector('sb-toast');
    const modal = [...document.querySelectorAll('sb-modal, sb-drawer')].filter((d) => d.isOpen).pop();
    return modal?.querySelector(':scope > .toast-stack > sb-toast') ?? document.querySelector('#alerts-toast-container > sb-toast');
}

const queued = window.showMessage?.queue ?? [];

/** Shows a toast and returns its id; `autoDismiss` lets it go after the region's duration, otherwise it stays. */
window.showMessage = (type, message, autoDismiss = false, containerSelector = null) => {
    const region = regionFor(containerSelector);
    if (!region) return null;
    const id = `toast-${++next}`;
    const toast = { id, variant: VARIANTS[type] ?? 'info', text: String(message ?? '') };
    if (!autoDismiss) toast.duration = 0;
    region.setAttribute('toasts', JSON.stringify([...listOf(region), toast]));
    return id;
};

document.addEventListener('sb-dismiss', (event) => {
    const region = event.target;
    if (region.localName !== 'sb-toast' || !region.matches('.toast-stack > sb-toast')) return;
    region.setAttribute('toasts', JSON.stringify(listOf(region).filter((t) => t.id !== event.detail?.id)));
});

// Calls made before this module ran, queued by the layout's stand-in (a fired alert on the first sync).
for (const args of queued.splice(0)) window.showMessage(...args);
