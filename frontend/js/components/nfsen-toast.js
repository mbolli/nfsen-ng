/**
 * <nfsen-toast level message auto-dismiss> (ROCKET-SPEC 6.1, shape B): the notice is built in setup, the message is always text.
 * An auto-dismissing toast waits while the pointer or the focus is on it.
 */
import { rocket } from 'datastar';
import { hostState, peekState, whenGone } from 'nfsen/host-state';

const LEVELS = ['success', 'info', 'warning', 'error'];
const DURATION = 5000;
const FADE = 220;

const levelOf = (value) => (LEVELS.includes(value) ? value : 'info');

/** Glyph, message, close button and, for a toast that times out, the time left. */
function build(host, level, autoDismiss) {
    const notice = document.createElement('div');
    notice.className = 'notice dismissible';
    notice.dataset.level = level;
    // Each toast is its own live region; errors and warnings interrupt.
    notice.setAttribute('role', level === 'error' || level === 'warning' ? 'alert' : 'status');

    const glyph = document.createElement('span');
    glyph.className = 'status-dot';
    glyph.dataset.level = level;
    glyph.setAttribute('aria-hidden', 'true');

    const text = document.createElement('span');
    text.className = 'toast-message';

    const close = document.createElement('button');
    close.type = 'button';
    close.dataset.variant = 'close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.title = 'Dismiss';

    notice.append(glyph, text, close);
    if (autoDismiss) {
        const progress = document.createElement('span');
        progress.className = 'toast-progress';
        progress.setAttribute('aria-hidden', 'true');
        progress.style.setProperty('--toast-duration', `${DURATION}ms`);
        notice.append(progress);
    }
    host.replaceChildren(notice);
    return text;
}

/** Fades the notice out, then removes the toast. */
function dismiss(host, emit) {
    const state = peekState(host);
    if (!state || state.dismissing) return;
    state.dismissing = true;
    state.running = false;
    clearTimeout(state.timer);
    host.querySelector('.notice.dismissible')?.classList.add('is-dismissing');
    state.fade = setTimeout(() => {
        emit('nfsen-toast-dismissed', null);
        host.remove();
    }, FADE);
}

rocket('nfsen-toast', {
    mode: 'light',
    props: ({ bool, oneOf, string }) => ({
        level: oneOf(...LEVELS)
            .default('info')
            .docs({ description: 'success, info, warning or error. Warnings and errors are alerts, the rest status messages.' }),
        message: string.docs({ description: 'The text of the toast, never read as markup.' }),
        autoDismiss: bool.docs({ description: 'Dismiss after 5 s, counting only while neither the pointer nor the focus is on it.' }),
    }),
    manifest: {
        events: [
            {
                name: 'nfsen-toast-dismissed',
                kind: 'custom-event',
                bubbles: true,
                composed: true,
                description: 'The toast is about to be removed: by its close button, dismiss() or the timeout.',
            },
        ],
    },
    setup: ({ cleanup, defineHostProp, emit, host, props }) => {
        const state = hostState(host, () => ({ left: DURATION, started: 0, running: false, timer: 0, fade: 0, dismissing: false }));
        if (!host.firstChild) {
            const text = build(host, levelOf(props.level), props.autoDismiss);
            // A status region reads out what changes in it, not what it was inserted with.
            requestAnimationFrame(() =>
                requestAnimationFrame(() => {
                    text.textContent = props.message;
                })
            );
        } else if (props.autoDismiss) {
            // A move that is not atomic restarts the animation; it resumes where the timer stands.
            host.querySelector('.toast-progress')?.style.setProperty('animation-delay', `${state.left - DURATION}ms`);
        }

        defineHostProp('dismiss', { value: () => dismiss(host, emit) });

        const held = () => host.matches(':hover, :focus-within');
        const run = () => {
            if (state.running || state.dismissing || held()) return;
            state.running = true;
            state.started = Date.now();
            state.timer = setTimeout(() => dismiss(host, emit), state.left);
        };
        const pause = () => {
            if (!state.running) return;
            state.running = false;
            clearTimeout(state.timer);
            state.left = Math.max(0, state.left - (Date.now() - state.started));
        };
        const listeners = {
            click: (event) => {
                if (event.target instanceof Element && event.target.closest('button[data-variant="close"]')) dismiss(host, emit);
            },
        };
        if (props.autoDismiss) {
            Object.assign(listeners, {
                pointerenter: pause,
                focusin: pause,
                pointerleave: run,
                // Focus moving between the toast's own controls leaves it held.
                focusout: () => setTimeout(run),
            });
            run();
        }
        for (const [type, listener] of Object.entries(listeners)) host.addEventListener(type, listener);

        cleanup(() => {
            for (const [type, listener] of Object.entries(listeners)) host.removeEventListener(type, listener);
            pause();
            whenGone(host, (gone) => {
                clearTimeout(gone.timer);
                clearTimeout(gone.fade);
            });
        });
    },
});

const queued = window.showMessage?.queue ?? [];

/**
 * Shows a toast in containerSelector (keep it data-ignore-morph), else in the open modal's own stack
 * (a modal makes the page inert), else in the shell's stack.
 */
window.showMessage = (type, message, autoDismiss = false, containerSelector = null) => {
    const toast = document.createElement('nfsen-toast');
    toast.level = levelOf(type);
    toast.message = String(message ?? '');
    toast.autoDismiss = !!autoDismiss;

    const modal = [...document.querySelectorAll('dialog:modal')].pop();
    const container =
        (containerSelector && document.querySelector(containerSelector)) ||
        modal?.querySelector(':scope > .toast-stack') ||
        document.getElementById('alerts-toast-container') ||
        document.body;
    container.appendChild(toast);
    return toast;
};

// Calls made before this module ran, queued by the layout's stand-in (a fired alert on the first sync).
for (const args of queued.splice(0)) window.showMessage(...args);
