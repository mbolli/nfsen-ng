// Expressions for the toasts: each stack is an sb-toast in a .toast-stack container, its list on the toasts attribute
// ({id, variant: ok|info|warn|danger, text, duration?}). A dismissed or timed-out toast leaves the list.

const regions = (stack) => `[...document.querySelectorAll(${JSON.stringify(stack ? `${stack} > sb-toast` : '.toast-stack > sb-toast')})]`;

/** The toasts of `stack` (a .toast-stack selector), or of every stack, oldest first. */
export const toasts = (stack) =>
    `${regions(stack)}.flatMap(function(r){ try { return JSON.parse(r.getAttribute('toasts') || '[]'); } catch (e) { return []; } })`;

/** Empties every stack, so a check waits for a toast of its own. */
export const CLEAR_TOASTS = `${regions()}.forEach(function(r){ r.setAttribute('toasts', '[]'); })`;

/** The sb-toast of `stack`. */
export const toastRegion = (stack) => `document.querySelector(${JSON.stringify(`${stack} > sb-toast`)})`;

/** The toasts `stack` shows (rows in its shadow root, a leaving one included). */
export const toastRows = (stack) =>
    `[...(${toastRegion(stack)}?.shadowRoot?.querySelectorAll('[part~="toast"]') ?? [])].filter(function(t){ return t.checkVisibility(); })`;
