/**
 * <nfsen-table>: the client half of Table::generate() (4.3.4). All rows live in memory and only
 * the current page is in the document; Datastar's observer takes seconds over 10,000 rows.
 */

import { root } from 'datastar';

const PAGE_SIZES = [25, 50, 100, 250];
const STORAGE_HIDDEN = 'nfsen-table-hidden-columns';
const STORAGE_SORT = 'nfsen-table-sort';
const STORAGE_PAGE_SIZE = 'nfsen-table-page-size';
const RETRY_MS = 8000;
const RETRIES = 3;

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

/**
 * Asks for a result's remaining chunks one at a time (D26): an arrival asks for the next, and a
 * chunk that does not come (a reconnect drops queued events) is asked for again.
 */
export class ChunkPull {
    constructor(total, request, from = 0) {
        this.total = total;
        this.next = from;
        this.request = request;
        this.tries = 0;
        this.failed = false;
        this.done = new Promise((resolve) => {
            this.resolve = resolve;
        });
        if (this.complete) this.resolve(true);
    }

    get complete() {
        return this.next >= this.total;
    }

    /** Chunk `chunk` is in; false for a duplicate or one out of turn. */
    arrived(chunk) {
        if (chunk !== this.next) return false;
        this.next += 1;
        this.tries = 0;
        if (this.failed) {
            // A late chunk after giving up: the pull is live again, and so is its promise.
            this.failed = false;
            this.done = new Promise((resolve) => {
                this.resolve = resolve;
            });
        }
        this.ask();
        return true;
    }

    ask() {
        clearTimeout(this.timer);
        if (this.complete) {
            this.resolve(true);
            return;
        }
        this.request(this.next);
        this.timer = setTimeout(() => {
            this.tries += 1;
            if (this.tries < RETRIES) {
                this.ask();
                return;
            }
            this.failed = true;
            this.resolve(false);
        }, RETRY_MS);
    }

    stop() {
        clearTimeout(this.timer);
        this.resolve(false);
    }
}

/**
 * The Raw output tab: the server appends `<span data-chunk>` pieces to `el` as `request(n)`
 * asks for them. Safe to call again; the first call starts the pull.
 */
export function pullChunks(el, request) {
    if (el.pull) return el.pull.done;
    const pull = new ChunkPull(Number(el.dataset.chunks) || 0, request);
    el.pull = pull;
    const take = () => {
        for (const piece of el.querySelectorAll(':scope > [data-chunk]:not([data-taken])')) {
            if (pull.arrived(Number(piece.dataset.chunk))) piece.dataset.taken = '';
            else piece.remove();
        }
    };
    const observer = new MutationObserver(take);
    observer.observe(el, { childList: true });
    pull.done.then((complete) => {
        observer.disconnect();
        el.removeAttribute('aria-busy');
        if (!complete && pull.failed) {
            const note = document.createElement('p');
            note.className = 'notice';
            note.textContent = "Part of nfdump's output did not arrive. Run again to see all of it.";
            el.after(note);
        }
    });
    take();
    pull.ask();
    return pull.done;
}

/** Resolves with `el` once its chunks are in (or stopped coming). */
export function whenLoaded(el) {
    return (el?.pull?.done ?? Promise.resolve()).then(() => el);
}

window.nfsenPullChunks ??= pullChunks;
window.nfsenWhenLoaded ??= whenLoaded;

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

function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

function download(content, filename, type) {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/**
 * Copies text, with the old execCommand path where the Clipboard API is missing (plain http),
 * and says so on the button for a moment.
 */
export async function copyText(text, button = null) {
    let copied = false;
    try {
        await navigator.clipboard.writeText(text);
        copied = true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.cssText = 'position:fixed;inset-block-start:0;opacity:0';
        document.body.append(area);
        area.select();
        try {
            copied = document.execCommand('copy');
        } catch {
            copied = false;
        }
        area.remove();
    }
    if (button) {
        button.dataset.label ??= button.textContent;
        button.textContent = copied ? 'Copied' : 'Copy failed';
        clearTimeout(button.copyTimer);
        button.copyTimer = setTimeout(() => {
            button.textContent = button.dataset.label;
        }, 1500);
    }
    return copied;
}

window.nfsenCopyText ??= copyText;

/** Scroll listeners are per element, and a morph can hand setup the same element again. */
const scrollBound = new WeakSet();

export class NfsenTable extends HTMLElement {
    static compareValues = compareValues;

    constructor() {
        super();
        // Delegated, so a morph that keeps the old buttons leaves no second listener on them.
        this.addEventListener('click', (event) => this.onClick(event));
        this.addEventListener('change', (event) => this.onChange(event));
    }

    connectedCallback() {
        const known = this.table;
        this.observer ??= new MutationObserver(() => this.refresh());
        this.refresh();
        // Moved rather than replaced: the rows in memory are still the ones to show.
        if (known && known === this.table) for (const target of this.sizeTargets ?? []) this.resizeObserver?.observe(target);
    }

    /** Rows past the first page still on their way (Table::generateChunked()). */
    get loading() {
        return Boolean(this.pull && !this.pull.complete && !this.pull.failed);
    }

    disconnectedCallback() {
        this.observer?.disconnect();
        this.resizeObserver?.disconnect();
    }

    /**
     * Sets up for a new result (a new <table>, or a new data-result when Datastar morphed this
     * element into the next one), else takes in arriving chunks. The observers watch children only.
     */
    refresh() {
        const table = this.querySelector('table');
        if (table !== this.table || this.dataset.result !== this.result) {
            this.table = table;
            this.result = this.dataset.result;
            if (table) this.setup();
        } else if (table) {
            this.addChunks();
        }
        this.observer.disconnect();
        this.observer.observe(this, { childList: true, attributes: true, attributeFilter: ['data-result'] });
        const wrap = table?.parentElement;
        if (wrap && wrap !== this) this.observer.observe(wrap, { childList: true });
    }

    setup() {
        this.pull?.stop();
        this.headers = [...this.table.querySelectorAll('thead th')];
        this.keys = this.headers.map((th) => th.dataset.originalTitle ?? this.titleOf(th));
        this.body = this.table.tBodies[0] ?? null;
        this.received = 0;
        this.rows = [...(this.body?.rows ?? []), ...this.takeChunks()];
        this.hiddenColumns = this.loadHidden();
        this.sort = this.loadSort();
        this.paginate = this.hasAttribute('data-page-size');
        this.pageSize = this.paginate ? this.loadPageSize() : Number.POSITIVE_INFINITY;
        this.page = 0;
        this.localiseTimes(this.rows);
        this.buildColumnMenu();
        this.switchView(this.dataset.view ?? 'table');
        this.bindScrollbar();
        this.applyColumns();
        const pull = new ChunkPull(Number(this.dataset.chunks) || 0, (chunk) => this.askFor(pull, chunk), this.received);
        this.pull = pull;
        pull.done.then(() => this.pull === pull && this.render());
        if (this.sort.key && this.keys.includes(this.sort.key)) this.sortBy(this.sort.key, this.sort.direction);
        else this.render();
        // Datastar binds data-on:nfsen-table-more only after this element is connected.
        if (!pull.complete) setTimeout(() => this.pull === pull && pull.ask(), 0);
    }

    /** data-on:nfsen-table-more on this element posts the request (Table::generateChunked()). */
    askFor(pull, chunk) {
        if (!this.isConnected) {
            pull.stop();
            return;
        }
        this.dispatchEvent(new CustomEvent('nfsen-table-more', { detail: { result: this.dataset.result, chunk } }));
    }

    /** Rows of the <template> chunks inside this element, in order; a repeated chunk is dropped. */
    takeChunks() {
        const rows = [];
        for (const template of this.querySelectorAll(':scope > template.table-rows')) {
            const chunk = template.dataset.chunk;
            if (chunk === undefined || Number(chunk) === this.received) {
                rows.push(...template.content.children);
                if (chunk !== undefined) this.received += 1;
            }
            template.remove();
        }
        return rows;
    }

    /** A chunk the server appended: its rows join in the current order, on the current page. */
    addChunks() {
        const before = this.received;
        const rows = this.takeChunks();
        if (!rows.length) return;
        this.rows.push(...rows);
        this.applyColumns(rows);
        this.localiseTimes(rows);
        if (this.sort.key && this.keys.includes(this.sort.key)) this.sortRows(this.sort.key, this.sort.direction);
        this.render();
        for (let chunk = before; chunk < this.received; chunk += 1) this.pull?.arrived(chunk);
    }

    titleOf(th) {
        return (th.querySelector('.sort-button') ?? th).textContent.trim();
    }

    onClick(event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || !this.table) return;
        const sort = target.closest('.sort-button');
        const index = sort ? this.headers.indexOf(sort.closest('th')) : -1;
        if (index !== -1) {
            const key = this.keys[index];
            this.sortBy(key, this.sort.key === key && this.sort.direction === 'asc' ? 'desc' : 'asc');
            save(`${STORAGE_SORT}-${this.id}`, this.sort);
            return;
        }
        const page = target.closest('.table-pager button[data-page]');
        if (page && !page.disabled) {
            const to = page.dataset.page;
            this.page = to === 'prev' ? this.page - 1 : to === 'next' ? this.page + 1 : Number(to);
            this.render();
            return;
        }
        const view = target.closest('button[data-view]');
        if (view) this.switchView(view.dataset.view);
    }

    onChange(event) {
        const input = event.target;
        if (!this.table) return;
        if (input instanceof HTMLSelectElement && input.closest('.table-pager')) {
            const size = Number(input.value);
            if (!PAGE_SIZES.includes(size) && size !== Number(this.dataset.pageSize)) return;
            const first = this.page * this.pageSize;
            this.pageSize = size;
            this.page = Math.floor(first / size);
            save(`${STORAGE_PAGE_SIZE}-${this.id}`, size);
            this.render();
            return;
        }
        if (!(input instanceof HTMLInputElement) || !input.closest('.column-selector-menu')) return;
        if (input.hasAttribute('data-column-all')) {
            this.hiddenColumns = input.checked
                ? this.hiddenColumns.filter((k) => !this.keys.includes(k))
                : [...new Set([...this.hiddenColumns, ...this.keys])];
        } else if (input.checked) {
            this.hiddenColumns = this.hiddenColumns.filter((k) => k !== input.dataset.columnKey);
        } else {
            this.hiddenColumns = [...this.hiddenColumns, input.dataset.columnKey];
        }
        save(`${STORAGE_HIDDEN}-${this.id}`, this.hiddenColumns);
        this.applyColumns();
    }

    // ── State ─────────────────────────────────────────────────────────────────

    /** Hidden column keys. Older versions stored titles, which map to their key here. */
    loadHidden() {
        const stored = load(`${STORAGE_HIDDEN}-${this.id}`, []);
        if (!Array.isArray(stored)) return [];
        const byTitle = new Map(this.headers.map((th, i) => [this.titleOf(th), this.keys[i]]));
        return [...new Set(stored.map((v) => (this.keys.includes(v) ? v : (byTitle.get(v) ?? v))))];
    }

    loadSort() {
        const stored = load(`${STORAGE_SORT}-${this.id}`, {});
        return typeof stored?.key === 'string' ? { key: stored.key, direction: stored.direction === 'desc' ? 'desc' : 'asc' } : {};
    }

    loadPageSize() {
        const stored = Number(load(`${STORAGE_PAGE_SIZE}-${this.id}`, 0));
        return PAGE_SIZES.includes(stored) ? stored : Math.max(1, Number(this.dataset.pageSize) || 50);
    }

    // ── Columns ───────────────────────────────────────────────────────────────

    /** The Columns disclosure; nfsen-controls.js opens and closes it (2.5). */
    buildColumnMenu() {
        const slot = this.querySelector('.column-selector-placeholder');
        if (!slot || !this.headers.length) return;
        const listId = `${this.id}-columns`;

        const menu = document.createElement('div');
        menu.className = 'menu column-selector';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'menu-toggle';
        toggle.dataset.size = 'sm';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', listId);
        toggle.textContent = 'Columns';
        const list = document.createElement('ul');
        list.className = 'menu-list column-selector-menu';
        list.id = listId;
        list.setAttribute('aria-label', 'Columns to show');

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
        list.append(item('Show all', all));
        const separator = document.createElement('li');
        separator.className = 'menu-sep';
        separator.setAttribute('role', 'separator');
        list.append(separator);
        this.headers.forEach((th, i) => {
            const box = document.createElement('input');
            box.className = 'column-checkbox';
            box.dataset.columnKey = this.keys[i];
            list.append(item(this.titleOf(th), box));
        });

        menu.append(toggle, list);
        slot.replaceChildren(menu);
    }

    applyColumns(rows = this.rows) {
        const hide = this.keys.map((key) => this.hiddenColumns.includes(key));
        this.headers.forEach((th, i) => {
            th.hidden = hide[i];
        });
        for (const row of rows) {
            for (let i = 0; i < row.cells.length; i++) {
                if (row.cells[i].hidden !== Boolean(hide[i])) row.cells[i].hidden = Boolean(hide[i]);
            }
        }
        const list = this.querySelector('.column-selector-menu');
        if (!list) return;
        for (const box of list.querySelectorAll('.column-checkbox')) box.checked = !this.hiddenColumns.includes(box.dataset.columnKey);
        const all = list.querySelector('[data-column-all]');
        if (all) all.checked = hide.every((h) => !h);
    }

    // ── Sorting and pages ─────────────────────────────────────────────────────

    /** Sorts every row, not just the page, and goes back to the first page. */
    sortBy(key, direction) {
        if (this.keys.indexOf(key) === -1) return this.render();
        this.sortRows(key, direction);
        this.page = 0;
        this.render();
    }

    /** Stable, with empty cells last. */
    sortRows(key, direction) {
        const index = this.keys.indexOf(key);
        this.sort = { key, direction };
        const factor = direction === 'desc' ? -1 : 1;
        const rows = this.rows.map((row, order) => {
            const cell = row.cells[index];
            return { row, order, value: cell ? sortKey(cell) : '' };
        });
        rows.sort((x, y) => {
            const emptyX = x.value === '';
            const emptyY = y.value === '';
            if (emptyX || emptyY) return emptyX === emptyY ? x.order - y.order : emptyX ? 1 : -1;
            return factor * compareValues(x.value, y.value) || x.order - y.order;
        });
        this.rows = rows.map(({ row }) => row);
        this.headers.forEach((th, i) => {
            if (i === index) th.setAttribute('aria-sort', direction === 'desc' ? 'descending' : 'ascending');
            else th.removeAttribute('aria-sort');
        });
    }

    /** Puts the current page's rows in the document, if they are not there yet, and updates the pager. */
    render() {
        const total = this.rows.length;
        const size = this.paginate ? this.pageSize : Math.max(1, total);
        const pages = Math.max(1, Math.ceil(total / size));
        this.page = Math.min(Math.max(0, this.page), pages - 1);
        const start = this.page * size;
        const end = Math.min(total, start + size);
        const shown = this.rows.slice(start, end);
        const current = this.body?.rows ?? [];
        if (this.body && (current.length !== shown.length || shown.some((row, i) => current[i] !== row))) {
            this.body.replaceChildren(...shown);
        }
        if (this.paginate) this.renderPager(total ? start + 1 : 0, end, total, pages);
    }

    renderPager(from, to, total, pages) {
        const pager = this.querySelector('.table-pager');
        if (!pager) return;
        const status = pager.querySelector('.table-pager-status');
        const reached = this.dataset.limitReached === undefined ? undefined : this.dataset.limitReached === 'true';
        let text = pagerText(from, to, total, Number(this.dataset.limit) || 0, reached);
        const rest = (Number(this.dataset.total) || total) - total;
        if (this.pull?.failed && rest > 0)
            text = `${pagerText(from, to, total, 0)} The other ${n(rest)} rows did not arrive; run again to see them.`;
        // The status is a live region, so it stays put while the chunks come in.
        else if (this.loading && rest > 0) text = `Showing ${n(from)}-${n(to)}; loading the other rows.`;
        if (status && status.textContent !== text) status.textContent = text;

        const focused = pager.contains(document.activeElement) ? document.activeElement.dataset.page : undefined;
        const list = pager.querySelector('.table-pager-pages');
        if (list) {
            list.replaceChildren(
                ...pagerPages(this.page, pages).map((page) => {
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
                    if (page === this.page) button.setAttribute('aria-current', 'page');
                    button.textContent = String(page + 1);
                    li.append(button);
                    return li;
                })
            );
        }
        const prev = pager.querySelector('[data-page="prev"]');
        const next = pager.querySelector('[data-page="next"]');
        if (prev) prev.disabled = this.page === 0;
        if (next) next.disabled = this.page >= pages - 1;
        const select = pager.querySelector('select');
        if (select && select.value !== String(this.pageSize)) select.value = String(this.pageSize);

        // A pressed page button was rebuilt or disabled (Next on the last page): focus its successor, or the current page.
        if (focused !== undefined && (!pager.contains(document.activeElement) || document.activeElement.disabled)) {
            const again = pager.querySelector(`[data-page="${focused}"]:not(:disabled)`) ?? pager.querySelector('[aria-current="page"]');
            again?.focus();
        }
    }

    // ── View, scrollbar, times ────────────────────────────────────────────────

    /** Table or nfdump's own text (Top Talkers keeps the Original view); the state is data-view. */
    switchView(view) {
        this.dataset.view = view === 'original' ? 'original' : 'table';
        for (const button of this.querySelectorAll('button[data-view]')) {
            button.setAttribute('aria-pressed', String(button.dataset.view === this.dataset.view));
        }
        const original = this.querySelector('.original');
        if (original) original.hidden = this.dataset.view !== 'original';
    }

    /** The scrollbar above a wide table mirrors the one below it. */
    bindScrollbar() {
        const outer = this.querySelector(`#${CSS.escape(this.id)}Outer`);
        const inner = this.querySelector(`#${CSS.escape(this.id)}Inner`);
        if (!outer || !inner) return;
        const measure = () => {
            outer.style.setProperty('--scroll-width', `${inner.scrollWidth}px`);
            this.toggleAttribute('data-overflow', inner.scrollWidth > inner.clientWidth + 1);
        };
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
        this.resizeObserver?.disconnect();
        this.resizeObserver = new ResizeObserver(measure);
        this.sizeTargets = [inner, this.table];
        for (const target of this.sizeTargets) this.resizeObserver.observe(target);
        measure();
    }

    /** Times the server wrote in its own timezone, shown in the display timezone. */
    localiseTimes(rows) {
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
    shownColumns() {
        const seen = new Set();
        return this.headers.flatMap((th, i) => {
            if (th.hidden) return [];
            let title = this.titleOf(th);
            if (seen.has(title)) title = `${title} (${this.keys[i]})`;
            seen.add(title);
            return [{ index: i, title }];
        });
    }

    /** Every row in the current order, not just the page; enhanced = the text shown, else the raw value. */
    rowsFor(columns, enhanced = this.enhanced()) {
        return this.rows.map((row) =>
            columns.map(({ index }) => {
                const cell = row.cells[index];
                if (!cell) return '';
                return !enhanced && cell.dataset.raw !== undefined ? cell.dataset.raw : cell.textContent.replace(/\s+/g, ' ').trim();
            })
        );
    }

    enhanced() {
        return this.querySelector(`#${CSS.escape(this.id)}-enhanced`)?.checked ?? true;
    }

    fileName(extension) {
        return `${this.dataset.exportName || this.id}.${extension}`;
    }

    /** Every row, once the chunks still on their way are in. */
    loaded() {
        return whenLoaded(this);
    }

    /** False, and says so in the pager, when some rows never arrived: a partial export would pass for the whole result. */
    async allRows() {
        await this.loaded();
        if (!this.pull?.failed) return true;
        const status = this.querySelector('.table-pager-status');
        if (status) status.textContent = 'Some rows did not arrive, so nothing was exported. Run again to export every row.';
        return false;
    }

    async exportCsv() {
        if (!(await this.allRows())) return;
        const columns = this.shownColumns();
        const quote = (value) => (/[",\n\r]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value);
        const lines = [columns.map((c) => quote(c.title)), ...this.rowsFor(columns).map((row) => row.map(quote))];
        download(`${lines.map((line) => line.join(',')).join('\n')}\n`, this.fileName('csv'), 'text/csv');
    }

    async exportJson() {
        if (!(await this.allRows())) return;
        const columns = this.shownColumns();
        const rows = this.rowsFor(columns).map((row) => Object.fromEntries(columns.map((c, i) => [c.title, row[i]])));
        download(JSON.stringify(rows, null, 2), this.fileName('json'), 'application/json');
    }

    /** The shown columns of every row in a print window titled by the caption, in the light scheme. */
    async print() {
        // Opened before any wait, while the click still counts as the user's.
        const win = window.open('', '_blank');
        if (!win) return;
        if (!(await this.allRows())) {
            win.close();
            return;
        }
        const columns = this.shownColumns();
        const title = this.dataset.caption || document.title;
        const c = printColors();
        const head = columns.map((col) => `<th>${escapeHtml(col.title)}</th>`).join('');
        const body = this.rowsFor(columns, true)
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
}

if (!customElements.get('nfsen-table')) customElements.define('nfsen-table', NfsenTable);
