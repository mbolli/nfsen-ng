/**
 * State a Rocket host keeps across a move (nfsen/host-state, ROCKET-SPEC K14). A move that is
 * not atomic runs cleanup and setup again, so state lives here, keyed by the host, not in setup.
 */
const states = new WeakMap();

/** The state kept for `host`, made by `create()` on first use. */
export function hostState(host, create) {
    let state = states.get(host);
    if (state === undefined) {
        state = create();
        states.set(host, state);
    }
    return state;
}

/** The state kept for `host`, or undefined. */
export function peekState(host) {
    return states.get(host);
}

/**
 * For a cleanup: after a microtask, a host no move reconnected drops its state, empties itself and leaves
 * its old parent, which Datastar's hold on every removed host (D3) would otherwise keep alive with it.
 */
export function whenGone(host, release) {
    queueMicrotask(() => {
        if (host.isConnected) return;
        const state = states.get(host);
        states.delete(host);
        if (state !== undefined) release?.(state);
        host.replaceChildren();
        host.shadowRoot?.replaceChildren();
        // Also resets the props, so no copy of a payload or message stays; Rocket dropped the host's prop
        // observers at disconnect, so no observeProps handler sees it.
        for (const name of host.getAttributeNames()) host.removeAttribute(name);
        host.remove();
    });
}
