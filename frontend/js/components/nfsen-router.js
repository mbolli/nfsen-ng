// Hash routing (spec 1.1): '#/<page>' names the page and the `page` signal mirrors it. Loaded
// before datastar.js, because the data-init on <body> and the hashchange handler on <html> call it.

const PAGES = ['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health', 'settings'];
const LEGACY = { graphs: 'overview', statistics: 'talkers', sankey: 'conversations', investigate: 'flows' };

/** '#/flows' -> 'flows'; '#/graphs' -> 'overview'; anything else -> fallback. */
function fromHash(hash, fallback) {
    const id = String(hash ?? '')
        .replace(/^#\/?/, '')
        .split(/[/?#]/)[0]
        .toLowerCase();
    if (PAGES.includes(id)) return id;
    return LEGACY[id] ?? fallback;
}

/** replaceState when the hash is missing or not canonical, so no hashchange fires. */
function canonicalize(page) {
    const hash = `#/${page}`;
    if (location.hash !== hash) history.replaceState(history.state, '', hash);
}

function heading(page) {
    return document.querySelector(`[data-page-heading="${page}"] h1`);
}

/** Focus that sits in a control which survives the switch stays where it is. */
function keepsFocus() {
    const active = document.activeElement;
    if (!active || active === document.body) return false;
    try {
        return !!active.closest('.controls-bar, .menu-list:is([data-open], :popover-open), [role="menu"][data-open]');
    } catch {
        return !!active.closest('.controls-bar, .menu-list[data-open], [role="menu"][data-open]');
    }
}

let waiting = null;
// The go() from the body's expressions, the page the client is on (the hash runs ahead of it
// during a view transition), and the pages posted that no sync has shown yet.
let post = null;
let clientPage = null;
const posted = [];

function navigate(go, page) {
    post = go;
    clientPage = page;
    posted.push(page);
    if (posted.length > 8) posted.shift();
    go(page);
}

function title(page) {
    document.title = `${heading(page)?.textContent.trim() || page} · nfsen-ng`;
}

/** The title changes at once; focus moves to the page h1 once #page-<id>[data-ready] exists,
    except on the initial load. */
function onNavigate(page, { initial = false } = {}) {
    title(page);
    waiting?.disconnect();
    waiting = null;
    if (initial) return;

    const focus = () => {
        if (!keepsFocus()) heading(page)?.focus({ preventScroll: true });
    };
    if (document.querySelector(`#page-${page}[data-ready]`)) {
        focus();
        return;
    }
    const root = document.getElementById('page-content');
    if (!root) return;
    waiting = new MutationObserver(() => {
        if (!document.querySelector(`#page-${page}[data-ready]`)) return;
        waiting.disconnect();
        waiting = null;
        focus();
    });
    waiting.observe(root, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-ready'] });
}

/** A sync rendered for a page this tab has left (an action that carried the old page landed
    after navigate): post navigate again for the page on screen, unless one is on its way. */
function heal() {
    const server = document.body.dataset.serverPage;
    const shown = posted.indexOf(server);
    if (shown >= 0) posted.splice(0, shown + 1);
    if (!post || !clientPage || clientPage === server || posted.includes(clientPage)) return;
    title(clientPage);
    navigate(post, clientPage);
}

const serverSync = new MutationObserver(heal);

/** On load: canonical hash, the title, and `go(page)` when the hash names another page than the server rendered. */
function start(serverPage, go) {
    post = go;
    const page = fromHash(location.hash, serverPage);
    clientPage = page;
    canonicalize(page);
    onNavigate(page, { initial: true });
    serverSync.observe(document.body, { attributes: true, attributeFilter: ['data-server-page'] });
    if (page !== serverPage) navigate(go, page);
}

// The page of the last hashchange whose transition has not run yet; `$page` lags behind it.
let pending = null;

/** On hashchange: `go(page)` switches the signal and posts navigate, inside a view transition. */
function follow(current, go) {
    const base = pending ?? current;
    const page = fromHash(location.hash, base);
    canonicalize(page);
    if (page === base) return;
    pending = page;
    const run = () => {
        if (pending === page) pending = null;
        navigate(go, page);
        window.scrollTo({ top: 0 });
        onNavigate(page);
    };
    if (window.withViewTransition) window.withViewTransition(run);
    else run();
}

window.nfsenRouter = { PAGES, LEGACY, fromHash, canonicalize, onNavigate, start, follow };

// Datastar reuses one hidden pantry <div> for every morph and never empties it, so leftovers of
// one morph break a later one (HierarchyRequestError). Emptied once a morph has detached it.
new MutationObserver((records) => {
    for (const record of records) {
        for (const node of record.removedNodes) {
            if (node instanceof HTMLDivElement && node.hidden && !node.isConnected) node.replaceChildren();
        }
    }
}).observe(document.documentElement, { childList: true });

// Effects that post keep their inputs here, so they fire only when a derived key changes (1.7).
// A WeakMap rather than a data attribute: a morph drops attributes the server did not render.
const keys = new WeakMap();

/** Stores the joined parts for `el`; true when they differ from the last call. */
window.nfsenChanged = (el, ...parts) => {
    const key = parts.map((part) => String(part)).join('\u001f');
    if (keys.get(el) === key) return false;
    keys.set(el, key);
    return true;
};
