/**
 * `<nfsen-tooltip text="..." [placement="top|bottom|left|right"] [no-icon]>`: a tip on an info
 * icon, or with no-icon on the wrapped element. Hoverable, persistent and dismissible (WCAG 1.4.13).
 */
const INFO_ICON =
    '<svg class="icon" viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/></svg>';
const GAP = 6;
const HIDE_DELAY = 120;
let nextId = 0;

class NfsenTooltip extends HTMLElement {
    static get observedAttributes() {
        return ['text', 'placement', 'no-icon'];
    }

    connectedCallback() {
        // On upgrade attributeChangedCallback has already built the tip.
        this.disposeTooltip();
        this.render();
        this.initTooltip();
    }

    disconnectedCallback() {
        this.disposeTooltip();
    }

    attributeChangedCallback(_name, oldValue, newValue) {
        if (oldValue !== newValue && this.isConnected) {
            this.disposeTooltip();
            this.render();
            this.initTooltip();
        }
    }

    render() {
        if (this.hasAttribute('no-icon')) {
            this.firstElementChild?.classList.add('nfsen-tooltip-trigger');
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'tip-icon nfsen-tooltip-trigger';
        button.setAttribute('aria-label', 'More information');
        button.innerHTML = INFO_ICON;
        this.replaceChildren(button);
    }

    initTooltip() {
        const trigger = this.querySelector('.nfsen-tooltip-trigger');
        if (!trigger) return;

        const tip = document.createElement('div');
        tip.className = 'nfsen-tip-popup';
        tip.id = `nfsen-tip-${++nextId}`;
        tip.setAttribute('role', 'tooltip');
        tip.textContent = this.getAttribute('text') || '';
        tip.hidden = true;
        (document.getElementById('nfsen-tip-container') ?? document.body).appendChild(tip);
        trigger.setAttribute('aria-describedby', tip.id);

        const placement = this.getAttribute('placement') || 'right';
        let hideTimer = 0;
        // Escape closes it wherever the focus is, so a hover tip can be dismissed too.
        const onKeydown = (event) => {
            if (event.key === 'Escape' && !tip.hidden) {
                event.stopPropagation();
                hideNow();
            }
        };
        const show = () => {
            clearTimeout(hideTimer);
            if (tip.hidden) document.addEventListener('keydown', onKeydown, true);
            tip.hidden = false;
            const r = trigger.getBoundingClientRect();
            const t = tip.getBoundingClientRect();
            let left;
            let top;
            switch (placement) {
                case 'top':
                    left = r.left + r.width / 2 - t.width / 2;
                    top = r.top - t.height - GAP;
                    break;
                case 'bottom':
                    left = r.left + r.width / 2 - t.width / 2;
                    top = r.bottom + GAP;
                    break;
                case 'left':
                    left = r.left - t.width - GAP;
                    top = r.top + r.height / 2 - t.height / 2;
                    break;
                default:
                    left = r.right + GAP;
                    top = r.top + r.height / 2 - t.height / 2;
            }
            tip.style.left = `${Math.max(4, Math.min(left, window.innerWidth - t.width - 4))}px`;
            tip.style.top = `${Math.max(4, Math.min(top, window.innerHeight - t.height - 4))}px`;
        };
        const hideNow = () => {
            clearTimeout(hideTimer);
            tip.hidden = true;
            document.removeEventListener('keydown', onKeydown, true);
        };
        // Stays while the pointer is on the trigger or the tip, or the keyboard focus on the trigger.
        const hide = () => {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(() => {
                if (!trigger.matches(':hover, :focus-visible, :has(:focus-visible)') && !tip.matches(':hover')) hideNow();
            }, HIDE_DELAY);
        };

        const listeners = [
            [trigger, 'mouseenter', show],
            [trigger, 'mouseleave', hide],
            [trigger, 'focusin', show],
            [trigger, 'focusout', hide],
            [tip, 'mouseenter', show],
            [tip, 'mouseleave', hide],
        ];
        for (const [el, type, fn] of listeners) el.addEventListener(type, fn);
        this._cleanup = () => {
            hideNow();
            for (const [el, type, fn] of listeners) el.removeEventListener(type, fn);
            if (trigger.getAttribute('aria-describedby') === tip.id) trigger.removeAttribute('aria-describedby');
            tip.remove();
        };
    }

    disposeTooltip() {
        this._cleanup?.();
        this._cleanup = null;
    }
}

customElements.define('nfsen-tooltip', NfsenTooltip);

export { NfsenTooltip };
