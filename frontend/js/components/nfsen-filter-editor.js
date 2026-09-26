/**
 * <nfsen-filter-editor> (spec 4.5.3): keyword suggestions for the word at the cursor of the textarea
 * it wraps, announced through the region named by data-status (a textarea cannot be a combobox).
 * window.nfsenFilterEditor holds the drawer's helpers; loaded before datastar.js, which reads them.
 */

const MAX_SUGGESTIONS = 8;
const WORD_CHAR = /[A-Za-z0-9-]/;
const PLACEHOLDER = /<[a-z]+>/;
const LEGACY_KEY = 'stored_filters';
const MIGRATED_KEY = 'nfsen-filters-migrated';

class NfsenFilterEditor extends HTMLElement {
    static get observedAttributes() {
        return ['data-grammar'];
    }

    constructor() {
        super();
        this.keywords = [];
        this.matches = [];
        this.active = -1;
        this.word = null;
        this.accepting = false;
        this.onInput = this.onInput.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        this.onFocusout = this.onFocusout.bind(this);
        this.onPointerdown = this.onPointerdown.bind(this);
    }

    attributeChangedCallback() {
        try {
            const grammar = JSON.parse(this.dataset.grammar || '{}');
            this.keywords = Array.isArray(grammar.keywords) ? grammar.keywords.map(String) : [];
        } catch {
            this.keywords = [];
        }
    }

    connectedCallback() {
        this.addEventListener('input', this.onInput);
        this.addEventListener('keydown', this.onKeydown);
        this.addEventListener('focusout', this.onFocusout);
        this.addEventListener('pointerdown', this.onPointerdown);
    }

    disconnectedCallback() {
        this.removeEventListener('input', this.onInput);
        this.removeEventListener('keydown', this.onKeydown);
        this.removeEventListener('focusout', this.onFocusout);
        this.removeEventListener('pointerdown', this.onPointerdown);
    }

    get textarea() {
        return this.querySelector('textarea');
    }

    get list() {
        let list = this.querySelector('.suggestions');
        if (!list) {
            list = document.createElement('ul');
            list.className = 'suggestions';
            list.setAttribute('role', 'listbox');
            list.setAttribute('aria-label', 'Suggestions');
            list.hidden = true;
            this.append(list);
        }
        if (!list.id) list.id = `${this.textarea?.id || 'filter'}Suggestions`;
        return list;
    }

    /** Insert `snippet` at the cursor, spaced from its neighbours; its first placeholder ends up selected, Tab selects the next. */
    insert(snippet) {
        const textarea = this.textarea;
        if (!textarea || !snippet) return;
        const value = textarea.value;
        const start = textarea.selectionStart ?? value.length;
        const end = textarea.selectionEnd ?? value.length;
        const before = value.slice(0, start);
        const after = value.slice(end);
        const lead = before !== '' && !/\s$/.test(before) ? ' ' : '';
        const trail = after !== '' && !/^\s/.test(after) ? ' ' : '';

        textarea.focus();
        textarea.setRangeText(lead + snippet + trail, start, end, 'end');
        const placeholder = PLACEHOLDER.exec(snippet);
        if (placeholder) {
            const from = start + lead.length + placeholder.index;
            textarea.setSelectionRange(from, from + placeholder[0].length);
        } else {
            const caret = start + lead.length + snippet.length;
            textarea.setSelectionRange(caret, caret);
        }
        this.close();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        const more = (snippet.match(/<[a-z]+>/g) ?? []).length > 1;
        this.announce(`${snippet} inserted${more ? ', Tab selects the next placeholder' : ''}`);
    }

    /** The word the caret ends, when the caret is not inside a longer word and nothing is selected. */
    wordAtCaret() {
        const textarea = this.textarea;
        if (!textarea || textarea.selectionStart !== textarea.selectionEnd) return null;
        const value = textarea.value;
        const end = textarea.selectionStart;
        if (end < value.length && WORD_CHAR.test(value[end])) return null;
        let start = end;
        while (start > 0 && WORD_CHAR.test(value[start - 1])) start--;
        return start === end ? null : { start, end, text: value.slice(start, end) };
    }

    onInput(event) {
        if (event.target !== this.textarea || this.accepting) return;
        const word = this.wordAtCaret();
        const lower = word?.text.toLowerCase() ?? '';
        const matches = word ? this.keywords.filter((k) => k.startsWith(lower) && k !== lower).slice(0, MAX_SUGGESTIONS) : [];
        if (!matches.length) {
            this.close();
            return;
        }
        const same = matches.join(' ') === this.matches.join(' ') && !this.list.hidden;
        this.word = word;
        this.matches = matches;
        if (!same) this.active = 0;
        this.render();
        if (!same) this.announceHighlight();
    }

    onKeydown(event) {
        if (event.target !== this.textarea || event.altKey || event.ctrlKey || event.metaKey) return;
        if (this.list.hidden || !this.matches.length) {
            if (event.key === 'Tab' && !event.shiftKey && this.selectNextPlaceholder()) event.preventDefault();
            return;
        }
        const count = this.matches.length;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.active = (this.active + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
            this.render();
            this.announceHighlight();
        } else if (event.key === 'Enter' || (event.key === 'Tab' && !event.shiftKey)) {
            event.preventDefault();
            this.accept(this.active);
        } else if (event.key === 'Escape') {
            // Closes the list, not the drawer around it.
            event.preventDefault();
            event.stopPropagation();
            this.close();
        }
    }

    /** Selects the first `<placeholder>` after the selection. Only forward, so Tab still leaves once none is left. */
    selectNextPlaceholder() {
        const textarea = this.textarea;
        const from = textarea.selectionEnd;
        const placeholder = PLACEHOLDER.exec(textarea.value.slice(from));
        if (!placeholder) return false;
        const start = from + placeholder.index;
        textarea.setSelectionRange(start, start + placeholder[0].length);
        this.announce(`${placeholder[0]} selected`);
        return true;
    }

    onFocusout(event) {
        if (event.target === this.textarea) this.close();
    }

    onPointerdown(event) {
        const option = event.target.closest?.('[role="option"]');
        if (!option || !this.list.contains(option)) return;
        // Keeps the caret in the textarea.
        event.preventDefault();
        this.accept(Number(option.dataset.index));
    }

    accept(index) {
        const keyword = this.matches[index];
        const textarea = this.textarea;
        if (!keyword || !textarea || !this.word) return;
        const { start, end } = this.word;
        const after = textarea.value.slice(end);
        const insert = /^\s/.test(after) ? keyword : `${keyword} `;
        textarea.setRangeText(insert, start, end, 'end');
        this.close();
        this.accepting = true;
        try {
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        } finally {
            this.accepting = false;
        }
        this.announce(`${keyword} inserted`);
    }

    render() {
        const list = this.list;
        const base = list.id;
        list.replaceChildren(
            ...this.matches.map((keyword, i) => {
                const li = document.createElement('li');
                li.id = `${base}-${i}`;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', String(i === this.active));
                li.dataset.index = String(i);
                li.textContent = keyword;
                return li;
            })
        );
        list.hidden = false;
        list.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
    }

    close() {
        const list = this.querySelector('.suggestions');
        if (list && !list.hidden) {
            list.hidden = true;
            list.replaceChildren();
        }
        this.matches = [];
        this.active = -1;
        this.word = null;
    }

    announceHighlight() {
        const count = this.matches.length;
        this.announce(`${count} suggestion${count === 1 ? '' : 's'}, ${this.matches[this.active]} selected`);
    }

    announce(text) {
        const region = this.dataset.status ? document.getElementById(this.dataset.status) : null;
        if (region && region.textContent !== text) region.textContent = text;
    }
}

customElements.define('nfsen-filter-editor', NfsenFilterEditor);

// ── Drawer helpers ──────────────────────────────────────────────────────────

let pendingFocus = null;

/** Whether focus is still where showModal() left it, so moving it takes nothing from the user. */
function focusUnclaimed(dialog) {
    const active = document.activeElement;
    return !active || active === document.body || active === dialog || !dialog.contains(active) || active.matches('[data-variant="close"]');
}

window.nfsenFilterEditor = {
    /** Focus #id in the open drawer now, or once a sync has rendered it, unless the user moved on. */
    focusDrawer(id) {
        const dialog = document.getElementById('filter-drawer');
        if (!dialog) return;
        pendingFocus?.();
        const attempt = () => {
            const target = document.getElementById(id);
            if (!dialog.open || !target?.getClientRects().length) return !dialog.open;
            target.focus();
            return true;
        };
        requestAnimationFrame(() => {
            if (attempt()) return;
            // Showing the frame focused its close button; the editor arrives with the drawer-open sync.
            const observer = new MutationObserver(() => {
                if (!focusUnclaimed(dialog) || attempt()) stop();
            });
            const timer = setTimeout(() => stop(), 10000);
            const stop = () => {
                observer.disconnect();
                clearTimeout(timer);
                if (pendingFocus === stop) pendingFocus = null;
            };
            pendingFocus = stop;
            observer.observe(dialog, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'style'] });
        });
    },

    /** Whether a saved-list row matches the search, by name or expression, case-insensitively. */
    matches(row, search) {
        const query = String(search ?? '')
            .trim()
            .toLowerCase();
        if (query === '') return true;
        return (row.dataset.name ?? '').toLowerCase().includes(query) || (row.dataset.expression ?? '').toLowerCase().includes(query);
    },

    /** True when a search is typed and no row of `list` matches it. */
    noneMatch(list, search) {
        if (!list || String(search ?? '').trim() === '') return false;
        return ![...list.querySelectorAll('li[data-filter-id]')].some((row) => this.matches(row, search));
    },

    /**
     * The old browser list as posted to filter-migrate-local, or null when there is nothing to
     * import; a browser with nothing to import is marked done straight away.
     */
    legacyFilters() {
        try {
            if (localStorage.getItem(MIGRATED_KEY) === '1') return null;
            const raw = localStorage.getItem(LEGACY_KEY);
            let list = null;
            try {
                list = JSON.parse(raw ?? 'null');
            } catch {
                list = null;
            }
            if (!Array.isArray(list) || !list.some((item) => typeof item === 'string' && item.trim() !== '')) {
                localStorage.setItem(MIGRATED_KEY, '1');
                return null;
            }
            return raw;
        } catch {
            // Storage disabled: nothing was saved there either.
            return null;
        }
    },

    /** The old list stays in place, so a user can roll back; only the flag is set. */
    markMigrated() {
        try {
            localStorage.setItem(MIGRATED_KEY, '1');
        } catch {
            // Storage disabled.
        }
    },
};

export { NfsenFilterEditor };
