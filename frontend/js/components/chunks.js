/**
 * Chunk pulls (nfsen/chunks, D26): a large result arrives one `data-chunk` piece at a time. Loaded
 * before the Datastar bundle, so a data-effect on first load finds window.nfsenPullChunks (K9).
 */
const RETRY_MS = 8000;
const RETRIES = 3;

/**
 * Asks for a result's remaining chunks one at a time: an arrival asks for the next, and a
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
