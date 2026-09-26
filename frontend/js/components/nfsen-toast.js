/**
 * Toast (4.0.6): the message is text, never markup. An auto-dismissing toast waits while the
 * pointer or the focus is on it.
 */
const LEVELS = ['success', 'info', 'warning', 'error'];
const DURATION = 5000;
const FADE = 220;

class NfsenToast extends HTMLElement {
    connectedCallback() {
        if (this._notice) return;
        const level = LEVELS.includes(this.dataset.type) ? this.dataset.type : 'info';
        this.render(level, this.dataset.message ?? '', this.dataset.autoDismiss === 'true');
    }

    disconnectedCallback() {
        clearTimeout(this._timer);
    }

    render(level, message, autoDismiss) {
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
        close.addEventListener('click', () => this.dismiss());

        notice.append(glyph, text, close);
        if (autoDismiss) {
            const progress = document.createElement('span');
            progress.className = 'toast-progress';
            progress.setAttribute('aria-hidden', 'true');
            progress.style.setProperty('--toast-duration', `${DURATION}ms`);
            notice.append(progress);
            this.startTimer(DURATION);
        }
        this._notice = notice;
        this.replaceChildren(notice);
        // A status region reads out what changes in it, not what it was inserted with.
        requestAnimationFrame(() =>
            requestAnimationFrame(() => {
                text.textContent = message;
            })
        );
    }

    startTimer(remaining) {
        let started = 0;
        let left = remaining;
        let paused = true;
        const run = () => {
            if (!paused || held()) return;
            paused = false;
            started = Date.now();
            this._timer = setTimeout(() => this.dismiss(), left);
        };
        const pause = () => {
            if (paused) return;
            paused = true;
            clearTimeout(this._timer);
            left = Math.max(0, left - (Date.now() - started));
        };
        const held = () => this.matches(':hover, :focus-within');
        this.addEventListener('pointerenter', pause);
        this.addEventListener('focusin', pause);
        this.addEventListener('pointerleave', run);
        this.addEventListener('focusout', () => setTimeout(run));
        run();
    }

    /** Fades the notice out, then removes the toast. */
    dismiss() {
        if (this._dismissing) return;
        this._dismissing = true;
        clearTimeout(this._timer);
        this.querySelector('.notice.dismissible')?.classList.add('is-dismissing');
        setTimeout(() => {
            this.dispatchEvent(new CustomEvent('nfsen-toast-dismissed', { bubbles: true }));
            this.remove();
        }, FADE);
    }
}

customElements.define('nfsen-toast', NfsenToast);

/**
 * Shows a toast. containerSelector: an optional target, which should carry data-ignore-morph
 * so a sync keeps it; the shell's toast stack otherwise.
 */
window.showMessage = (type, message, autoDismiss = false, containerSelector = null) => {
    const toast = document.createElement('nfsen-toast');
    toast.dataset.type = type;
    toast.dataset.message = String(message ?? '');
    if (autoDismiss) toast.dataset.autoDismiss = 'true';

    const container =
        (containerSelector && document.querySelector(containerSelector)) ||
        document.getElementById('alerts-toast-container') ||
        document.body;
    container.appendChild(toast);
    return toast;
};
