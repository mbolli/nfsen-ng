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
 * For a cleanup: a move reconnects the host before the microtask runs, a removal does not.
 * Datastar keeps every removed host (D3), so a removed one drops its state and empties itself.
 */
export function whenGone(host, release) {
    queueMicrotask(() => {
        if (host.isConnected) return;
        const state = states.get(host);
        states.delete(host);
        if (state !== undefined) release?.(state);
        host.replaceChildren();
        host.shadowRoot?.replaceChildren();
    });
}
