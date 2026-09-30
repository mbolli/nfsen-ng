/**
 * <nfsen-table> (4.3.4; ROCKET-SPEC 6.8, shape A): the client half of Table::generate(). All rows live
 * in the host state and only the current page is in the document; Datastar's observer takes seconds over 10,000 rows.
 */
import { rocket, root } from 'datastar';
import { ChunkPull, whenLoaded } from 'nfsen/chunks';
import { download } from 'nfsen/download';
import { escapeHtml } from 'nfsen/format';
import { hostState, peekState, whenGone } from 'nfsen/host-state';

export { ChunkPull, pullChunks, whenLoaded } from 'nfsen/chunks';

const PAGE_SIZES = [25, 50, 100, 250];
/** Rows per frame: at about 100, Datastar's and Rocket's observers and the table layout run a frame past 50 ms. */
const ROWS_PER_FRAME = 50;
const STORAGE_HIDDEN = 'nfsen-table-hidden-columns';
const STORAGE_SORT = 'nfsen-table-sort';
const STORAGE_PAGE_SIZE = 'nfsen-table-page-size';

function load(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) ?? 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

function save(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // storage disabled: the choice lasts for this page only
    }
}

/** A tab signal by name; the wire id carries a per-context suffix. */
function signal(name) {
    const key = Object.keys(root).find((k) => k === name || k.startsWith(`${name}____`));
    return key === undefined ? undefined : root[key];
}

const DIGITS = /^\d+$/;
const NUMBER = /^-?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?$/i;

/**
 * Sort keys: digit strings of any length by value (IPv6 keys have 41 digits, past a float's
 * precision), other numbers as numbers, the rest as text with natural digit order.
 */
export function compareValues(a, b) {
    if (DIGITS.test(a) && DIGITS.test(b)) {
        const x = a.replace(/^0+(?=\d)/, '');
        const y = b.replace(/^0+(?=\d)/, '');
        return x.length - y.length || (x < y ? -1 : x > y ? 1 : 0);
    }
    if (NUMBER.test(a) && NUMBER.test(b)) return Number(a) - Number(b);
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

/** What Table::generate() left out because the cell shows it: the sort key, then the raw value. */
function sortKey(cell) {
    return cell.dataset.sortValue ?? cell.querySelector('time[data-epoch]')?.dataset.epoch ?? cell.dataset.raw ?? cell.textContent.trim();
}

const n = (v) => Number(v).toLocaleString('en');

/** Same words as Table::pagerText(). */
export function pagerText(from, to, total, limit, reached = undefined) {
    const shown = total === 0 ? 'No rows' : `Showing ${n(from)}-${n(to)} of ${n(total)}`;
    if (!(limit > 0)) return shown + (total === 0 ? '.' : ' rows.');
    const text = `${shown} returned (limit ${n(limit)}).`;
    return (reached ?? total >= limit) ? `${text} nfdump cannot skip rows: raise the limit to see more.` : text;
}

/** Same pages as Table::pagerPages(): null is a gap. */
export function pagerPages(current, pages) {
    if (pages <= 7) return Array.from({ length: pages }, (_, i) => i);
    if (current < 4) return [0, 1, 2, 3, 4, null, pages - 1];
    if (current > pages - 5) return [0, null, ...Array.from({ length: 5 }, (_, i) => pages - 5 + i)];
    return [0, null, current - 1, current, current + 1, null, pages - 1];
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

/** Scroll listeners are per element, and a new setup can meet the same elements again. */
const scrollBound = new WeakSet();

/** What a host keeps across a move (K14): the result it shows, its rows and the reader's choices. */
function blank() {
    return {
        result: undefined,
        table: null,
        body: null,
        headers: [],
        keys: [],
        rows: [],
        filling: null,
        held: null,
        received: 0,
        pull: null,
        started: null,
        page: 0,
        sort: {},
        hiddenColumns: [],
        paginate: false,
        pageSize: Number.POSITIVE_INFINITY,
        view: 'table',
        queued: false,
        watch: null,
        resizes: null,
    };
}

function release(state) {
    state.pull?.stop();
    unhold(state);
    Object.assign(state, { pull: null, started: null, rows: [], filling: null, table: null, body: null, headers: [] });
}

/**
 * Builds for a new result (a new <table>, or a new data-result when Datastar morphed this host into
 * the next one), else takes in arriving chunks; true when it built.
 */
function refresh(host, state) {
    const table = host.querySelector('table');
    let built = false;
    if (table !== state.table || host.dataResult !== state.result) {
        state.table = table;
        state.result = host.dataResult;
        if (table) {
            build(host, state);
            built = true;
        }
    } else if (table) {
        addChunks(host, state);
    }
    watch(host, state);
    return built;
}

/** After a morph or an arriving chunk; a new result asks for its chunks at once, its data-on being bound. */
function update(host) {
    const state = peekState(host);
    if (state && host.isConnected && refresh(host, state)) start(state);
}

/** One refresh per burst of prop changes, after the morph that made them (K15). */
function schedule(host) {
    const state = peekState(host);
    if (!state || state.queued) return;
    state.queued = true;
    queueMicrotask(() => {
        state.queued = false;
        update(host);
    });
}

/** The child observer sees chunk templates arrive and the <table> being replaced. */
function watch(host, state) {
    const observer = state.watch;
    if (!observer) return;
    observer.disconnect();
    observer.observe(host, { childList: true });
    const wrap = state.table?.parentElement;
    if (wrap && wrap !== host) observer.observe(wrap, { childList: true });
}

function build(host, state) {
    state.pull?.stop();
    const table = state.table;
    state.headers = [...table.querySelectorAll('thead th')];
    state.keys = state.headers.map((th) => th.dataset.originalTitle ?? titleOf(th));
    state.body = table.tBodies[0] ?? null;
    state.filling = null;
    unhold(state);
    state.received = 0;
    state.rows = [...(state.body?.rows ?? []), ...takeChunks(host, state)];
    state.hiddenColumns = loadHidden(host, state);
    state.sort = loadSort(host);
    state.paginate = host.hasAttribute('data-page-size');
    state.pageSize = state.paginate ? loadPageSize(host) : Number.POSITIVE_INFINITY;
    state.page = 0;
    localiseTimes(state.rows);
    buildColumnMenu(host, state);
    switchView(host, state, host.dataset.view ?? 'table');
    bindScrollbar(host, state);
    applyColumns(host, state);
    const pull = new ChunkPull(host.dataChunks || 0, (chunk) => askFor(host, pull, chunk), state.received);
    state.pull = pull;
    pull.done.then(() => state.pull === pull && showPage(host, state));
    if (state.sort.key && state.keys.includes(state.sort.key)) sortBy(host, state, state.sort.key, state.sort.direction);
    else showPage(host, state);
}

/** The first request of the current pull; before it the host's data-on:nfsen-table-more must be bound. */
function start(state) {
    const pull = state.pull;
    if (!pull || pull.complete || state.started === pull) return;
    state.started = pull;
    pull.ask();
}

/** data-on:nfsen-table-more on the host posts the request (Table::generateChunked()). */
function askFor(host, pull, chunk) {
    if (!host.isConnected) {
        pull.stop();
        return;
    }
    host.dispatchEvent(new CustomEvent('nfsen-table-more', { bubbles: true, composed: true, detail: { result: host.dataResult, chunk } }));
}

/** Rows of the <template> chunks inside the host, in order; a repeated chunk is dropped. */
function takeChunks(host, state) {
    const rows = [];
    for (const template of host.querySelectorAll(':scope > template.table-rows')) {
        const chunk = template.dataset.chunk;
        if (chunk === undefined || Number(chunk) === state.received) {
            rows.push(...template.content.children);
            if (chunk !== undefined) state.received += 1;
        }
        template.remove();
    }
    return rows;
}

/** A chunk the server appended: its rows join in the current order, on the current page. */
function addChunks(host, state) {
    const before = state.received;
    const rows = takeChunks(host, state);
    if (!rows.length) return;
    state.rows.push(...rows);
    applyColumns(host, state, rows);
    localiseTimes(rows);
    if (state.sort.key && state.keys.includes(state.sort.key)) sortRows(state, state.sort.key, state.sort.direction);
    showPage(host, state);
    for (let chunk = before; chunk < state.received; chunk += 1) state.pull?.arrived(chunk);
}

function titleOf(th) {
    return (th.querySelector('.sort-button') ?? th).textContent.trim();
}

function onClick(host, event) {
    const state = peekState(host);
    const target = event.target instanceof Element ? event.target : null;
    if (!state?.table || !target) return;
    const sort = target.closest('.sort-button');
    const index = sort ? state.headers.indexOf(sort.closest('th')) : -1;
    if (index !== -1) {
        const key = state.keys[index];
        sortBy(host, state, key, state.sort.key === key && state.sort.direction === 'asc' ? 'desc' : 'asc');
        save(`${STORAGE_SORT}-${host.id}`, state.sort);
        return;
    }
    const page = target.closest('.table-pager button[data-page]');
    if (page && !page.disabled) {
        const to = page.dataset.page;
        state.page = to === 'prev' ? state.page - 1 : to === 'next' ? state.page + 1 : Number(to);
        showPage(host, state);
        return;
    }
    const view = target.closest('button[data-view]');
    if (view) switchView(host, state, view.dataset.view);
}

function onChange(host, event) {
    const state = peekState(host);
    const input = event.target;
    if (!state?.table) return;
    if (input instanceof HTMLSelectElement && input.closest('.table-pager')) {
        const size = Number(input.value);
        if (!PAGE_SIZES.includes(size) && size !== host.dataPageSize) return;
        const first = state.page * state.pageSize;
        state.pageSize = size;
        state.page = Math.floor(first / size);
        save(`${STORAGE_PAGE_SIZE}-${host.id}`, size);
        showPage(host, state);
        return;
    }
    if (!(input instanceof HTMLInputElement) || !input.closest('.column-selector-menu')) return;
    if (input.hasAttribute('data-column-all')) {
        state.hiddenColumns = input.checked
            ? state.hiddenColumns.filter((k) => !state.keys.includes(k))
            : [...new Set([...state.hiddenColumns, ...state.keys])];
    } else if (input.checked) {
        state.hiddenColumns = state.hiddenColumns.filter((k) => k !== input.dataset.columnKey);
    } else {
        state.hiddenColumns = [...state.hiddenColumns, input.dataset.columnKey];
    }
    save(`${STORAGE_HIDDEN}-${host.id}`, state.hiddenColumns);
    applyColumns(host, state);
}

// ── Stored choices ────────────────────────────────────────────────────────

/** Hidden column keys. Older versions stored titles, which map to their key here. */
function loadHidden(host, state) {
    const stored = load(`${STORAGE_HIDDEN}-${host.id}`, []);
    if (!Array.isArray(stored)) return [];
    const byTitle = new Map(state.headers.map((th, i) => [titleOf(th), state.keys[i]]));
    return [...new Set(stored.map((v) => (state.keys.includes(v) ? v : (byTitle.get(v) ?? v))))];
}

function loadSort(host) {
    const stored = load(`${STORAGE_SORT}-${host.id}`, {});
    return typeof stored?.key === 'string' ? { key: stored.key, direction: stored.direction === 'desc' ? 'desc' : 'asc' } : {};
}

function loadPageSize(host) {
    const stored = Number(load(`${STORAGE_PAGE_SIZE}-${host.id}`, 0));
    return PAGE_SIZES.includes(stored) ? stored : Math.max(1, host.dataPageSize || 50);
}

// ── Columns ───────────────────────────────────────────────────────────────

/** A new Columns popover (POPOVER-SPEC PC1, PC2, PC5); the trigger names its list, which is in the same tree. */
function createColumnPopover(host) {
    const listId = `${host.id}-columns`;
    const popover = document.createElement('sb-popover');
    popover.id = `${host.id}-columnsPopover`;
    popover.className = 'column-selector';
    popover.setAttribute('label', 'Columns to show');
    popover.setAttribute('placement', 'bottom-end');
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.slot = 'trigger';
    trigger.className = 'menu-toggle';
    trigger.dataset.size = 'sm';
    trigger.dataset.preserveAttr = 'aria-expanded aria-haspopup';
    trigger.setAttribute('aria-controls', listId);
    trigger.textContent = 'Columns';
    const list = document.createElement('ul');
    list.className = 'popover-list column-selector-menu';
    list.id = listId;
    popover.append(trigger, list);
    return popover;
}

/**
 * The Columns popover, one per host for all its results. Table::toolbar() marks the placeholder data-ignore-morph,
 * so a new result keeps host and trigger and replaces only the list's items.
 */
function buildColumnMenu(host, state) {
    const slot = host.querySelector('.column-selector-placeholder');
    if (!slot || !state.headers.length) return;
    let popover = slot.querySelector(':scope > sb-popover.column-selector');
    if (!popover) {
        popover = createColumnPopover(host);
        slot.replaceChildren(popover);
    }
    const list = popover.querySelector('.column-selector-menu');
    const focused = list.contains(document.activeElement) ? document.activeElement : null;

    const item = (text, input) => {
        const li = document.createElement('li');
        const label = document.createElement('label');
        input.type = 'checkbox';
        label.append(input, ` ${text}`);
        li.append(label);
        return li;
    };
    const all = document.createElement('input');
    all.dataset.columnAll = '';
    const separator = document.createElement('li');
    separator.className = 'menu-sep';
    separator.setAttribute('role', 'separator');
    const boxes = state.keys.map((key) => {
        const box = document.createElement('input');
        box.className = 'column-checkbox';
        box.dataset.columnKey = key;
        return box;
    });
    list.replaceChildren(item('Show all', all), separator, ...boxes.map((box, i) => item(titleOf(state.headers[i]), box)));
    // A result that lands while the popover is open leaves the focus on the same column.
    if (focused) (boxes.find((box) => box.dataset.columnKey === focused.dataset.columnKey) ?? all).focus();
}

function applyColumns(host, state, rows = state.rows) {
    const hide = state.keys.map((key) => state.hiddenColumns.includes(key));
    state.headers.forEach((th, i) => {
        th.hidden = hide[i];
    });
    for (const row of rows) {
        for (let i = 0; i < row.cells.length; i++) {
            if (row.cells[i].hidden !== Boolean(hide[i])) row.cells[i].hidden = Boolean(hide[i]);
        }
    }
    const list = host.querySelector('.column-selector-menu');
    if (!list) return;
    for (const box of list.querySelectorAll('.column-checkbox')) box.checked = !state.hiddenColumns.includes(box.dataset.columnKey);
    const all = list.querySelector('[data-column-all]');
    if (all) all.checked = hide.every((h) => !h);
}

// ── Sorting and pages ─────────────────────────────────────────────────────

/** Sorts every row, not just the page, and goes back to the first page. */
function sortBy(host, state, key, direction) {
    if (state.keys.indexOf(key) !== -1) {
        sortRows(state, key, direction);
        state.page = 0;
    }
    showPage(host, state);
}

/** Stable, with empty cells last. */
function sortRows(state, key, direction) {
    const index = state.keys.indexOf(key);
    state.sort = { key, direction };
    const factor = direction === 'desc' ? -1 : 1;
    const rows = state.rows.map((row, order) => {
        const cell = row.cells[index];
        return { row, order, value: cell ? sortKey(cell) : '' };
    });
    rows.sort((x, y) => {
        const emptyX = x.value === '';
        const emptyY = y.value === '';
        if (emptyX || emptyY) return emptyX === emptyY ? x.order - y.order : emptyX ? 1 : -1;
        return factor * compareValues(x.value, y.value) || x.order - y.order;
    });
    state.rows = rows.map(({ row }) => row);
    state.headers.forEach((th, i) => {
        if (i === index) th.setAttribute('aria-sort', direction === 'desc' ? 'descending' : 'ascending');
        else th.removeAttribute('aria-sort');
    });
}

/** Puts the current page's rows in the document, if they are not there yet, and updates the pager. */
function showPage(host, state) {
    const total = state.rows.length;
    const size = state.paginate ? state.pageSize : Math.max(1, total);
    const pages = Math.max(1, Math.ceil(total / size));
    state.page = Math.min(Math.max(0, state.page), pages - 1);
    const start = state.page * size;
    const end = Math.min(total, start + size);
    const shown = state.rows.slice(start, end);
    const current = state.filling ?? state.body?.rows ?? [];
    if (state.body && (current.length !== shown.length || shown.some((row, i) => current[i] !== row))) fill(state, shown);
    if (state.paginate) renderPager(host, state, total ? start + 1 : 0, end, total, pages);
}

/** The first rows of a page now, the rest one batch per frame after the next paint. */
function fill(state, rows) {
    const body = state.body;
    const wrap = state.table.closest('.table-wrap');
    // The wrap keeps its height until the last batch is in, so a page no taller than the last leaves the pager in place.
    if (wrap && rows.length > ROWS_PER_FRAME) {
        const height = wrap.offsetHeight;
        if (state.held !== wrap) unhold(state);
        wrap.style.minBlockSize = `${height}px`;
        state.held = wrap;
    }
    // Emptied in one step: replaceChildren(...rows) takes out each row still here in a record of its own,
    // and Rocket's observer rescans the whole body for each record.
    body.replaceChildren();
    state.filling = rows;
    const add = (from) => {
        if (state.filling !== rows || state.body !== body) return;
        body.append(...rows.slice(from, from + ROWS_PER_FRAME));
        if (from + ROWS_PER_FRAME < rows.length) {
            requestAnimationFrame(() => setTimeout(add, 0, from + ROWS_PER_FRAME));
            return;
        }
        state.filling = null;
        unhold(state);
    };
    add(0);
}

function unhold(state) {
    state.held?.style.removeProperty('min-block-size');
    state.held = null;
}

function renderPager(host, state, from, to, total, pages) {
    const pager = host.querySelector('.table-pager');
    if (!pager) return;
    const status = pager.querySelector('.table-pager-status');
    const reached = host.hasAttribute('data-limit-reached') ? host.dataLimitReached : undefined;
    let text = pagerText(from, to, total, host.dataLimit || 0, reached);
    const rest = (host.dataTotal || total) - total;
    const pull = state.pull;
    if (pull?.failed && rest > 0)
        text = `${pagerText(from, to, total, 0)} The other ${n(rest)} rows did not arrive; run again to see them.`;
    // The status is a live region, so it stays put while the chunks come in.
    else if (pull && !pull.complete && rest > 0) text = `Showing ${n(from)}-${n(to)}; loading the other rows.`;
    if (status && status.textContent !== text) status.textContent = text;

    const focused = pager.contains(document.activeElement) ? document.activeElement.dataset.page : undefined;
    const list = pager.querySelector('.table-pager-pages');
    if (list) {
        list.replaceChildren(
            ...pagerPages(state.page, pages).map((page) => {
                const li = document.createElement('li');
                if (page === null) {
                    li.setAttribute('aria-hidden', 'true');
                    li.textContent = '…';
                    return li;
                }
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.size = 'sm';
                button.dataset.page = String(page);
                button.setAttribute('aria-label', `Page ${page + 1}`);
                if (page === state.page) button.setAttribute('aria-current', 'page');
                button.textContent = String(page + 1);
                li.append(button);
                return li;
            })
        );
    }
    const prev = pager.querySelector('[data-page="prev"]');
    const next = pager.querySelector('[data-page="next"]');
    if (prev) prev.disabled = state.page === 0;
    if (next) next.disabled = state.page >= pages - 1;
    const select = pager.querySelector('select');
    if (select && select.value !== String(state.pageSize)) select.value = String(state.pageSize);

    // A pressed page button was rebuilt or disabled (Next on the last page): focus its successor, or the current page.
    if (focused !== undefined && (!pager.contains(document.activeElement) || document.activeElement.disabled)) {
        const again = pager.querySelector(`[data-page="${focused}"]:not(:disabled)`) ?? pager.querySelector('[aria-current="page"]');
        again?.focus();
    }
}

// ── View, scrollbar, times ────────────────────────────────────────────────

/** Table or nfdump's own text (Top Talkers keeps the Original view); data-view carries it to the CSS. */
function switchView(host, state, view) {
    state.view = view === 'original' ? 'original' : 'table';
    host.dataset.view = state.view;
    for (const button of host.querySelectorAll('button[data-view]')) {
        button.setAttribute('aria-pressed', String(button.dataset.view === state.view));
    }
    const original = host.querySelector('.original');
    if (original) original.hidden = state.view !== 'original';
}

function scrollParts(host) {
    const id = CSS.escape(host.id);
    return { outer: host.querySelector(`#${id}Outer`), inner: host.querySelector(`#${id}Inner`) };
}

/** Sizes the scrollbar above a wide table like the one below it. */
function measure(host) {
    const { outer, inner } = scrollParts(host);
    if (!outer || !inner) return;
    outer.style.setProperty('--scroll-width', `${inner.scrollWidth}px`);
    host.toggleAttribute('data-overflow', inner.scrollWidth > inner.clientWidth + 1);
}

/** The scrollbar above a wide table mirrors the one below it. */
function bindScrollbar(host, state) {
    const { outer, inner } = scrollParts(host);
    if (!outer || !inner) return;
    if (!scrollBound.has(outer)) {
        scrollBound.add(outer);
        outer.addEventListener(
            'scroll',
            () => {
                if (inner.scrollLeft !== outer.scrollLeft) inner.scrollLeft = outer.scrollLeft;
            },
            { passive: true }
        );
    }
    if (!scrollBound.has(inner)) {
        scrollBound.add(inner);
        inner.addEventListener(
            'scroll',
            () => {
                if (outer.scrollLeft !== inner.scrollLeft) outer.scrollLeft = inner.scrollLeft;
            },
            { passive: true }
        );
    }
    state.resizes?.disconnect();
    for (const target of [inner, state.table]) if (target) state.resizes?.observe(target);
    measure(host);
}

/** Times the server wrote in its own timezone, shown in the display timezone. */
function localiseTimes(rows) {
    const times = rows.flatMap((row) => [...row.getElementsByTagName('time')]).filter((t) => t.dataset.epoch);
    if (!times.length) return;
    const options = window.nfsenTime?.tzOptions?.(signal('displayTz') ?? 'browser', signal('nfcapdTz') ?? 'UTC') ?? {};
    let format;
    try {
        format = new Intl.DateTimeFormat('sv-SE', {
            ...options,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23',
        });
    } catch {
        return;
    }
    for (const time of times) {
        const text = format.format(Number(time.dataset.epoch) * 1000);
        if (time.textContent !== text) time.textContent = text;
    }
}

// ── Exports (the page's Export menu calls these) ──────────────────────────

/** The shown columns in their order, titled; a repeated title gets its key. */
function shownColumns(state) {
    const seen = new Set();
    return state.headers.flatMap((th, i) => {
        if (th.hidden) return [];
        let title = titleOf(th);
        if (seen.has(title)) title = `${title} (${state.keys[i]})`;
        seen.add(title);
        return [{ index: i, title }];
    });
}

/** Every row in the current order, not just the page; enhanced = the text shown, else the raw value. */
function rowsFor(state, columns, enhanced) {
    return state.rows.map((row) =>
        columns.map(({ index }) => {
            const cell = row.cells[index];
            if (!cell) return '';
            return !enhanced && cell.dataset.raw !== undefined ? cell.dataset.raw : cell.textContent.replace(/\s+/g, ' ').trim();
        })
    );
}

function enhanced(host) {
    return host.querySelector(`#${CSS.escape(host.id)}-enhanced`)?.checked ?? true;
}

function fileName(host, extension) {
    return `${host.dataExportName || host.id}.${extension}`;
}

/**
 * The state with every row, once the chunks still on their way are in; null, and says so in the
 * pager, when some never arrived, since a partial export would pass for the whole result.
 */
async function allRows(host) {
    await whenLoaded(host);
    const state = peekState(host);
    if (!state?.table) return null;
    if (!state.pull?.failed) return state;
    const status = host.querySelector('.table-pager-status');
    if (status) status.textContent = 'Some rows did not arrive, so nothing was exported. Run again to export every row.';
    return null;
}

async function exportCsv(host) {
    const state = await allRows(host);
    if (!state) return;
    const columns = shownColumns(state);
    const quote = (value) => (/[",\n\r]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value);
    const lines = [columns.map((c) => quote(c.title)), ...rowsFor(state, columns, enhanced(host)).map((row) => row.map(quote))];
    download(`${lines.map((line) => line.join(',')).join('\n')}\n`, fileName(host, 'csv'), 'text/csv');
}

async function exportJson(host) {
    const state = await allRows(host);
    if (!state) return;
    const columns = shownColumns(state);
    const rows = rowsFor(state, columns, enhanced(host)).map((row) => Object.fromEntries(columns.map((c, i) => [c.title, row[i]])));
    download(JSON.stringify(rows, null, 2), fileName(host, 'json'), 'application/json');
}

/** The shown columns of every row in a print window titled by the caption, in the light scheme. */
async function print(host) {
    // Opened before any wait, while the click still counts as the user's.
    const win = window.open('', '_blank');
    if (!win) return;
    const state = await allRows(host);
    if (!state) {
        win.close();
        return;
    }
    const columns = shownColumns(state);
    const title = host.dataCaption || document.title;
    const c = printColors();
    const head = columns.map((col) => `<th>${escapeHtml(col.title)}</th>`).join('');
    const body = rowsFor(state, columns, true)
        .map((row) => `<tr>${row.map((v) => `<td>${escapeHtml(v)}</td>`).join('')}</tr>`)
        .join('');
    win.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${escapeHtml(title)}</title><style>
        body { margin: 1.5rem; font-family: ${c.font}; color: ${c.text}; background: ${c.paper}; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        p { color: ${c.muted}; font-size: 0.8rem; }
        table { border-collapse: collapse; inline-size: 100%; font-size: 0.75rem; }
        th, td { border: 1px solid ${c.border}; padding: 0.25rem 0.5rem; text-align: start; }
        th { background: ${c.header}; }
        tbody tr:nth-child(even) { background: ${c.zebra}; }
        @media print { body { margin: 0; } }
    </style></head><body><h1>${escapeHtml(title)}</h1><p>${escapeHtml(new Date().toLocaleString())}</p><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></body></html>`);
    win.document.close();
    win.addEventListener('afterprint', () => win.close());
    win.focus();
    win.print();
}

rocket('nfsen-table', {
    mode: 'open',
    props: ({ bool, number, string }) => ({
        dataResult: string.docs({ description: 'The result id; a new one on a moved host means a new result to build.' }),
        dataPageSize: number.docs({ description: 'Rows per page the server rendered; present means the table has pages.' }),
        dataLimit: number.docs({ description: 'The row limit nfdump ran with, for the pager text; 0 for none.' }),
        dataLimitReached: bool.docs({ description: 'Whether that limit cut the result, so the pager says why there are no more rows.' }),
        dataTotal: number.docs({ description: 'Rows in the whole result, of which the chunks bring those past the first page.' }),
        dataChunks: number.docs({ description: 'How many <template data-chunk> pieces to ask for with nfsen-table-more.' }),
        dataCaption: string.docs({ description: 'The table caption, used as the print title.' }),
        dataExportName: string.docs({ description: 'File name of the CSV and JSON exports, without the extension.' }),
    }),
    manifest: {
        events: [
            {
                name: 'nfsen-table-more',
                kind: 'custom-event',
                bubbles: true,
                composed: true,
                description: 'Asks for rows past the first page; detail.result is the result id, detail.chunk the piece.',
            },
        ],
    },
    setup: ({ cleanup, defineHostProp, host, observeProps }) => {
        if (!host.shadowRoot.firstChild) host.shadowRoot.append(document.createElement('slot'));
        const state = hostState(host, blank);

        defineHostProp('rows', { get: () => peekState(host)?.rows });
        defineHostProp('keys', { get: () => peekState(host)?.keys });
        defineHostProp('headers', { get: () => peekState(host)?.headers });
        defineHostProp('pull', { get: () => peekState(host)?.pull ?? null });
        defineHostProp('loading', {
            get: () => {
                const pull = peekState(host)?.pull;
                return Boolean(pull && !pull.complete && !pull.failed);
            },
        });
        defineHostProp('exportCsv', { value: () => exportCsv(host) });
        defineHostProp('exportJson', { value: () => exportJson(host) });
        defineHostProp('print', { value: () => print(host) });

        // Delegated, so a morph that keeps the old buttons leaves no second listener on them.
        const click = (event) => onClick(host, event);
        const change = (event) => onChange(host, event);
        host.addEventListener('click', click);
        host.addEventListener('change', change);
        const watcher = new MutationObserver(() => update(host));
        const resizes = new ResizeObserver(() => measure(host));
        state.watch = watcher;
        state.resizes = resizes;

        // Set up again after a move that was not atomic: the rows in memory are still the ones to show.
        if (!refresh(host, state) && state.table) {
            switchView(host, state, state.view);
            bindScrollbar(host, state);
        }
        observeProps(() => schedule(host), 'dataResult');

        cleanup(() => {
            host.removeEventListener('click', click);
            host.removeEventListener('change', change);
            watcher.disconnect();
            resizes.disconnect();
            if (state.watch === watcher) state.watch = null;
            if (state.resizes === resizes) state.resizes = null;
            whenGone(host, release);
        });
    },
    // Rocket binds the host's data-on only after setup, so the first chunk is asked for here.
    onFirstRender: ({ host }) => {
        const state = peekState(host);
        if (state) start(state);
    },
});
