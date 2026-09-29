/** Keyword suggestions for the textarea `for` (spec 4.5.3, ROCKET-SPEC 6.7, shape C); a textarea cannot be a combobox. */
import { rocket } from 'datastar';
import { hostState, peekState, whenGone } from 'nfsen/host-state';

const MAX_SUGGESTIONS = 8;
const WORD_CHAR = /[A-Za-z0-9-]/;
const PLACEHOLDER = /<[a-z]+>/;
const PLACEHOLDERS = /<[a-z]+>/g;

const byId = (id) => (id ? document.getElementById(id) : null);

/** The keywords of the current grammar, decoded again only when the prop changed. */
function keywordsOf(host, state) {
    if (state.grammar !== host.grammar) {
        state.grammar = host.grammar;
        const words = state.grammar?.keywords;
        state.keywords = Array.isArray(words) ? words.map(String) : [];
    }
    return state.keywords;
}

/** The word the caret ends, when the caret is not inside a longer word and nothing is selected. */
function wordAtCaret(textarea) {
    if (textarea.selectionStart !== textarea.selectionEnd) return null;
    const value = textarea.value;
    const end = textarea.selectionStart;
    if (end < value.length && WORD_CHAR.test(value[end])) return null;
    let start = end;
    while (start > 0 && WORD_CHAR.test(value[start - 1])) start--;
    return start === end ? null : { start, end, text: value.slice(start, end) };
}

function announce(host, text) {
    const region = byId(host.status);
    if (region && region.textContent !== text) region.textContent = text;
}

function announceHighlight(host, state) {
    const count = state.matches.length;
    announce(host, `${count} suggestion${count === 1 ? '' : 's'}, ${state.matches[state.active]} selected`);
}

function showList(list, state) {
    list.replaceChildren(
        ...state.matches.map((keyword, i) => {
            const li = document.createElement('li');
            li.id = `${list.id}-${i}`;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', String(i === state.active));
            li.dataset.index = String(i);
            li.textContent = keyword;
            return li;
        })
    );
    list.hidden = false;
    list.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
}

function closeList(list, state) {
    if (list && !list.hidden) {
        list.hidden = true;
        list.replaceChildren();
    }
    state.matches = [];
    state.active = -1;
    state.word = null;
}

/** Selects the first `<placeholder>` after the selection. Only forward, so Tab still leaves once none is left. */
function selectNextPlaceholder(host, textarea) {
    const from = textarea.selectionEnd;
    const placeholder = PLACEHOLDER.exec(textarea.value.slice(from));
    if (!placeholder) return false;
    const start = from + placeholder.index;
    textarea.setSelectionRange(start, start + placeholder[0].length);
    announce(host, `${placeholder[0]} selected`);
    return true;
}

/** Insert `snippet` at the caret, spaced from its neighbours; its first placeholder ends up selected, Tab selects the next. */
function insert(host, snippet) {
    const state = peekState(host);
    const textarea = byId(host.for);
    if (!host.isConnected || !state || !(textarea instanceof HTMLTextAreaElement) || !snippet) return;
    const value = textarea.value;
    const start = textarea.selectionStart ?? value.length;
    const end = textarea.selectionEnd ?? value.length;
    const before = value.slice(0, start);
    const after = value.slice(end);
    const lead = before !== '' && !/\s$/.test(before) ? ' ' : '';
    const trail = after !== '' && !/^\s/.test(after) ? ' ' : '';

    // Its focusin binds the editor to this textarea, should a sync have replaced it.
    textarea.focus();
    textarea.setRangeText(lead + snippet + trail, start, end, 'end');
    const placeholder = PLACEHOLDER.exec(snippet);
    const from = start + lead.length + (placeholder ? placeholder.index : snippet.length);
    textarea.setSelectionRange(from, from + (placeholder ? placeholder[0].length : 0));
    closeList(byId(host.list), state);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    const more = (snippet.match(PLACEHOLDERS) ?? []).length > 1;
    announce(host, `${snippet} inserted${more ? ', Tab selects the next placeholder' : ''}`);
}

rocket('nfsen-filter-editor', {
    mode: 'light',
    props: ({ json, string }) => ({
        for: string.docs({ description: 'Id of the textarea whose words are completed.' }),
        list: string.docs({ description: 'Id of the listbox that shows the suggestions; keep it data-ignore-morph.' }),
        status: string.docs({ description: 'Id of the live region that announces suggestions and insertions.' }),
        grammar: json
            .default(() => ({ keywords: [] }))
            .docs({ description: 'The filter grammar as JSON; its keywords are the suggestions.' }),
    }),
    manifest: { events: [] },
    setup: ({ cleanup, defineHostProp, host, observeProps }) => {
        const state = hostState(host, () => ({ matches: [], active: -1, word: null, accepting: false, grammar: undefined, keywords: [] }));
        const bound = { textarea: null, list: null };
        let live = true;

        const close = () => closeList(bound.list, state);

        const accept = (index) => {
            const keyword = state.matches[index];
            const textarea = bound.textarea;
            if (!keyword || !textarea || !state.word) return;
            const { start, end } = state.word;
            const after = textarea.value.slice(end);
            textarea.setRangeText(/^\s/.test(after) ? keyword : `${keyword} `, start, end, 'end');
            close();
            state.accepting = true;
            try {
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            } finally {
                state.accepting = false;
            }
            announce(host, `${keyword} inserted`);
        };

        const textareaListeners = {
            input: (event) => {
                if (state.accepting) return;
                const word = wordAtCaret(event.currentTarget);
                const lower = word?.text.toLowerCase() ?? '';
                const matches = word
                    ? keywordsOf(host, state)
                          .filter((k) => k.startsWith(lower) && k !== lower)
                          .slice(0, MAX_SUGGESTIONS)
                    : [];
                if (!matches.length || !bound.list) {
                    close();
                    return;
                }
                const same = matches.join(' ') === state.matches.join(' ') && !bound.list.hidden;
                state.word = word;
                state.matches = matches;
                if (!same) state.active = 0;
                showList(bound.list, state);
                if (!same) announceHighlight(host, state);
            },
            keydown: (event) => {
                if (event.altKey || event.ctrlKey || event.metaKey) return;
                if (!bound.list || bound.list.hidden || !state.matches.length) {
                    if (event.key === 'Tab' && !event.shiftKey && selectNextPlaceholder(host, event.currentTarget)) event.preventDefault();
                    return;
                }
                const count = state.matches.length;
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    state.active = (state.active + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
                    showList(bound.list, state);
                    announceHighlight(host, state);
                } else if (event.key === 'Enter' || (event.key === 'Tab' && !event.shiftKey)) {
                    event.preventDefault();
                    accept(state.active);
                } else if (event.key === 'Escape') {
                    // Closes the list, not the drawer around it.
                    event.preventDefault();
                    event.stopPropagation();
                    close();
                }
            },
            focusout: close,
        };
        const listListeners = {
            pointerdown: (event) => {
                const option = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
                if (!option) return;
                // Keeps the caret in the textarea.
                event.preventDefault();
                accept(Number(option.dataset.index));
            },
        };

        /** Moves the listeners to `next`; true when it replaces a node bound before. */
        const swap = (key, next, listeners) => {
            const previous = bound[key];
            if (next === previous) return false;
            for (const [type, listener] of Object.entries(listeners)) {
                previous?.removeEventListener(type, listener);
                next?.addEventListener(type, listener);
            }
            bound[key] = next;
            return previous !== null;
        };
        const bind = () => {
            const textarea = byId(host.for);
            const oldList = bound.list;
            const replaced = swap('textarea', textarea instanceof HTMLTextAreaElement ? textarea : null, textareaListeners);
            if (swap('list', byId(host.list), listListeners) || replaced) closeList(oldList, state);
        };
        // A sync can replace either target; the next focus anywhere picks up the new node.
        const onFocusin = () => bind();
        let queued = false;
        observeProps(
            () => {
                if (queued) return;
                queued = true;
                queueMicrotask(() => {
                    queued = false;
                    if (live) bind();
                });
            },
            'for',
            'list'
        );
        document.addEventListener('focusin', onFocusin, true);
        bind();

        defineHostProp('insert', { value: (snippet) => insert(host, snippet) });

        cleanup(() => {
            live = false;
            document.removeEventListener('focusin', onFocusin, true);
            swap('textarea', null, textareaListeners);
            swap('list', null, listListeners);
            whenGone(host);
        });
    },
});
