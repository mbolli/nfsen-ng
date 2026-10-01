/** window.nfsenFlowsList (ADOPT-VSCROLL 3.7). Loads before the bundle (K9), so it never imports 'datastar'. */
import { pullChunks } from 'nfsen/chunks';
import { download } from 'nfsen/download';

const TYPES = { csv: 'text/csv', json: 'application/json' };
/** As FlowWindowActions::KEY_PATTERN and MAX_HIDDEN: nfdump's json keys and its camelCase csv names. */
const KEY = /^[A-Za-z0-9_]{1,64}$/;
const MAX_HIDDEN = 64;
/** A Print whose export element has not come by then is reported lost. */
const PRINT_WAIT_MS = 30000;
const PREPARING = 'Preparing the rows for printing…';
const FAILED = 'The export did not arrive. Export again.';

/** The result the list shows, the timer of a Print waiting for its element, and the frame of the last print. */
let shown = null;
let printWait = 0;
let printFrame = null;

/** Puts `text` in #flowsExports in place of the earlier export notices (pullChunks' as well), or only clears them. */
function say(text) {
    const box = document.getElementById('flowsExports');
    for (const note of box?.querySelectorAll(':scope > p.notice') ?? []) note.remove();
    if (!text || !box) return;
    const note = document.createElement('p');
    note.className = 'notice';
    note.textContent = text;
    box.append(note);
}

/** Token colours in the light scheme, resolved now, for a printout on paper. */
function printColors() {
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;color-scheme:light;forced-color-adjust:none';
    document.body.append(probe);
    const read = (token) => {
        probe.style.color = `var(${token})`;
        return getComputedStyle(probe).color;
    };
    const colors = {
        text: read('--text-1'),
        muted: read('--text-2'),
        border: read('--border'),
        header: read('--surface-3'),
        zebra: read('--surface-2'),
        paper: read('--surface-1'),
        font: getComputedStyle(document.body).fontFamily,
    };
    probe.remove();
    return colors;
}

/**
 * The print export ({title, columns, rows}) as a table in a hidden frame of this page, whose window is returned; null
 * if it is not one. A window or tab of its own would hide this page, and Datastar closes a hidden page's stream.
 */
function printWindow(text) {
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        return null;
    }
    if (!Array.isArray(data?.columns) || !Array.isArray(data?.rows)) return null;
    const c = printColors();
    printFrame?.remove();
    const frame = document.createElement('iframe');
    frame.title = 'Flows to print';
    frame.tabIndex = -1;
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;inset:0 auto auto 0;inline-size:1px;block-size:1px;border:0;opacity:0;pointer-events:none';
    document.body.append(frame);
    printFrame = frame;
    const win = frame.contentWindow;
    const doc = win.document;
    doc.open();
    doc.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><title></title><style>
        body { margin: 1.5rem; font-family: ${c.font}; color: ${c.text}; background: ${c.paper}; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        p { color: ${c.muted}; font-size: 0.8rem; }
        table { border-collapse: collapse; inline-size: 100%; font-size: 0.75rem; }
        th, td { border: 1px solid ${c.border}; padding: 0.25rem 0.5rem; text-align: start; }
        th { background: ${c.header}; }
        tbody tr:nth-child(even) { background: ${c.zebra}; }
        @media print { body { margin: 0; } }
    </style></head><body><h1></h1><p></p><table><thead><tr></tr></thead><tbody></tbody></table></body></html>`);
    doc.close();
    const title = String(data.title ?? 'Flows');
    const cell = (tag, value) => {
        const node = doc.createElement(tag);
        node.textContent = String(value ?? '');
        return node;
    };
    doc.title = title;
    doc.querySelector('h1').textContent = title;
    doc.querySelector('p').textContent = new Date().toLocaleString();
    doc.querySelector('thead tr').append(...data.columns.map((column) => cell('th', column)));
    const body = doc.createDocumentFragment();
    for (const row of data.rows) {
        const tr = doc.createElement('tr');
        tr.append(...(Array.isArray(row) ? row : []).map((value) => cell('td', value)));
        body.append(tr);
    }
    doc.querySelector('tbody').append(body);
    win.addEventListener('afterprint', () =>
        setTimeout(() => {
            frame.remove();
            if (printFrame === frame) printFrame = null;
        })
    );
    return win;
}

window.nfsenFlowsList = {
    /**
     * Pulls the chunks of an export element (FlowExportActions), then saves the CSV or JSON file or prints the rows,
     * and removes the element. Safe to call again.
     */
    pull(el, request) {
        if (el.pull) return el.pull.done;
        const format = el.dataset.format;
        if (format === 'print') clearTimeout(printWait);
        const done = pullChunks(el, request);
        done.then((complete) => {
            const text = el.textContent;
            el.remove();
            if (!complete) {
                say(FAILED);
            } else if (format !== 'print') {
                say(null);
                download(text, el.dataset.name, TYPES[format] ?? 'text/plain');
            } else {
                const win = printWindow(text);
                say(win ? null : FAILED);
                win?.print();
            }
        });
        return done;
    },

    /** Runs in the Print click, before its post: says the rows are coming, and that they are lost if they never come. */
    openPrint() {
        clearTimeout(printWait);
        say(PREPARING);
        printWait = setTimeout(() => say(FAILED), PRINT_WAIT_MS);
    },

    /** The list shows result `id` (the toolbar's data-init): the notices and a waiting Print of another result go. */
    forResult(id) {
        if (shown !== null && shown !== id) {
            clearTimeout(printWait);
            say(null);
        }
        shown = id;
    },

    /**
     * The hidden set after a change of `input`, a column's box or "Show all", from `current` ($_flows_hidden).
     * The list's boxes follow at once; the next render checks them the same way.
     */
    hidden(current, input) {
        const list = input.closest('.column-selector-menu');
        const boxes = [...(list?.querySelectorAll('input[data-column-key]') ?? [])];
        const keys = boxes.map((box) => box.dataset.columnKey);
        const key = input.dataset.columnKey;
        let next = Array.isArray(current) ? current : [];
        if (input.hasAttribute('data-column-all')) {
            next = input.checked ? next.filter((k) => !keys.includes(k)) : [...next, ...keys];
        } else if (key) {
            next = input.checked ? next.filter((k) => k !== key) : [...next, key];
        }
        next = [...new Set(next.filter((k) => typeof k === 'string' && KEY.test(k)))];
        if (next.length > MAX_HIDDEN) {
            next = [...next.filter((k) => keys.includes(k)), ...next.filter((k) => !keys.includes(k))].slice(0, MAX_HIDDEN);
        }
        for (const box of boxes) box.checked = !next.includes(box.dataset.columnKey);
        const all = list?.querySelector('[data-column-all]');
        if (all) all.checked = boxes.every((box) => box.checked);
        return next;
    },
};
