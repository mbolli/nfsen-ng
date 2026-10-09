// Expressions for an sb-dropdown in the page, by id: its trigger and menu live in its shadow root.
// The component's own keyboard and focus handling is Starbase's to test; these drive nfsen-ng's wiring.

const host = (id) => `document.getElementById(${JSON.stringify(id)})`;

/** The trigger button. */
export const ddTrigger = (id) => `${host(id)}?.shadowRoot?.querySelector('[part~="trigger"]')`;

/** Whether the menu is open. */
export const ddOpen = (id) => `${host(id)}.matches(':state(open)')`;

/** The root menu's rows (items, not dividers). */
export const ddRows = (id) => `[...${host(id)}.shadowRoot.querySelectorAll('#menu0 [role^="menuitem"]')].filter(function(r){ return r.checkVisibility(); })`;

/** The labels of the root menu's rows, in order. */
export const ddLabels = (id) => `${ddRows(id)}.map(function(r){ return r.textContent.trim(); })`;

/** Clicks the row whose label is `label`; true when there was one. */
export const ddChoose = (id, label) =>
    `(function(){ var row = ${ddRows(id)}.find(function(r){ return r.textContent.trim() === ${JSON.stringify(label)}; }); if (row) row.click(); return !!row; })()`;

/** The rounded viewport boxes of the trigger and the open menu, and the viewport size. */
export const ddBoxes = (id) => `(function(){
    var round = function(r){ return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right) }; };
    return {
        open: ${ddOpen(id)},
        trigger: round(${ddTrigger(id)}.getBoundingClientRect()),
        panel: round(${host(id)}.shadowRoot.querySelector('#menu0').getBoundingClientRect()),
        view: { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight },
    };
})()`;
