/**
 * window.nfsenFilterEditor: the filter drawer's helpers (spec 4.5, ROCKET-SPEC 6.7). A plain module that loads
 * before the Datastar bundle, whose first pass over the drawer reads them (K9), so it never imports 'datastar'.
 */

const LEGACY_KEY = 'stored_filters';
const MIGRATED_KEY = 'nfsen-filters-migrated';

let pendingFocus = null;

/** Whether focus is still where opening left it (the drawer's own close button), so moving it takes nothing from the user. */
function focusUnclaimed(drawer) {
    const active = document.activeElement;
    return !active || active === document.body || active === drawer || !drawer.contains(active);
}

window.nfsenFilterEditor = {
    /** Focus #id in the open drawer now, or once a sync has rendered it, unless the user moved on. */
    focusDrawer(id) {
        const drawer = document.getElementById('filter-drawer');
        if (!drawer) return;
        pendingFocus?.();
        const attempt = () => {
            const target = document.getElementById(id);
            if (!drawer.isOpen || !target?.getClientRects().length) return !drawer.isOpen;
            target.focus();
            return true;
        };
        requestAnimationFrame(() => {
            if (attempt()) return;
            // Showing the frame focused its close button; the editor arrives with the drawer-open sync.
            const observer = new MutationObserver(() => {
                if (!focusUnclaimed(drawer) || attempt()) stop();
            });
            const timer = setTimeout(() => stop(), 10000);
            const stop = () => {
                observer.disconnect();
                clearTimeout(timer);
                if (pendingFocus === stop) pendingFocus = null;
            };
            pendingFocus = stop;
            observer.observe(drawer, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'style'] });
        });
    },

    /** A saved-list row's name and expression, from its text (K1 keeps user text out of data-* values). */
    rowName: (row) => row?.querySelector('.saved-name')?.textContent ?? '',
    rowExpression: (row) => row?.querySelector('.saved-expression')?.textContent ?? '',

    /** Whether a saved-list row matches the search, by name or expression, case-insensitively. */
    matches(row, search) {
        const query = String(search ?? '')
            .trim()
            .toLowerCase();
        if (query === '') return true;
        return this.rowName(row).toLowerCase().includes(query) || this.rowExpression(row).toLowerCase().includes(query);
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
