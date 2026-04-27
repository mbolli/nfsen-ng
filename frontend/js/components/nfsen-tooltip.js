/**
 * nfsen-tooltip Web Component
 * A reusable tooltip component with optional info icon.
 * Uses a lightweight JS tooltip (no Bootstrap.Tooltip / Popper dependency).
 *
 * Usage:
 * <nfsen-tooltip text="Your tooltip text here"></nfsen-tooltip>
 * <nfsen-tooltip text="Tooltip without icon" no-icon></nfsen-tooltip>
 *
 * Attributes:
 * - text: The tooltip content (required)
 * - placement: Tooltip placement (default: "right") — top, bottom, left, right
 * - no-icon: If present, wraps the slotted content instead of showing info icon
 */
class NfsenTooltip extends HTMLElement {
    constructor() {
        super();
        this._tip = null;
    }

    connectedCallback() {
        this.render();
        this.initTooltip();
    }

    disconnectedCallback() {
        this.disposeTooltip();
    }

    static get observedAttributes() {
        return ['text', 'placement', 'no-icon'];
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue !== newValue && this.isConnected) {
            this.disposeTooltip();
            this.render();
            this.initTooltip();
        }
    }

    render() {
        const text = this.getAttribute('text') || 'No tooltip text provided';
        const placement = this.getAttribute('placement') || 'right';
        const noIcon = this.hasAttribute('no-icon');

        if (noIcon) {
            // Add a marker class to the first child element so initTooltip() can find it
            const firstChild = this.firstElementChild;
            if (firstChild) {
                firstChild.classList.add('nfsen-tooltip-trigger');
            }
        } else {
            // Show info icon with tooltip trigger
            this.innerHTML = `
                <span
                    class="nfsen-tooltip-trigger"
                    aria-hidden="true"
                    tabindex="0"
                    style="cursor: help; display: inline-flex; align-items: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-info-circle-fill" viewBox="0 0 16 16">
                        <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/>
                    </svg>
                </span>
            `;
        }
    }

    initTooltip() {
        const trigger = this.querySelector('.nfsen-tooltip-trigger');
        if (!trigger) return;

        const text = this.getAttribute('text') || '';
        const placement = this.getAttribute('placement') || 'right';

        // Create floating tip element. Append to #nfsen-tip-container (data-ignore-morph)
        // so Datastar's full-page SSE morph won't remove it.
        const tip = document.createElement('div');
        tip.className = 'nfsen-tip-popup';
        tip.textContent = text;
        tip.setAttribute('role', 'tooltip');
        tip.style.cssText = 'position:fixed;z-index:9999;display:none;';
        const container = document.getElementById('nfsen-tip-container') ?? document.body;
        container.appendChild(tip);
        this._tip = tip;

        const show = () => {
            tip.style.display = '';
            const r = trigger.getBoundingClientRect();
            const t = tip.getBoundingClientRect();
            const gap = 6;
            let left, top;
            switch (placement) {
                case 'top':
                    left = r.left + r.width / 2 - t.width / 2;
                    top  = r.top - t.height - gap;
                    break;
                case 'bottom':
                    left = r.left + r.width / 2 - t.width / 2;
                    top  = r.bottom + gap;
                    break;
                case 'left':
                    left = r.left - t.width - gap;
                    top  = r.top + r.height / 2 - t.height / 2;
                    break;
                default: // right
                    left = r.right + gap;
                    top  = r.top + r.height / 2 - t.height / 2;
            }
            // Keep inside viewport
            left = Math.max(4, Math.min(left, window.innerWidth  - t.width  - 4));
            top  = Math.max(4, Math.min(top,  window.innerHeight - t.height - 4));
            tip.style.left = left + 'px';
            tip.style.top  = top  + 'px';
        };
        const hide = () => { tip.style.display = 'none'; };

        trigger.addEventListener('mouseenter', show);
        trigger.addEventListener('mouseleave', hide);
        trigger.addEventListener('focusin',    show);
        trigger.addEventListener('focusout',   hide);

        // Store handlers for cleanup
        this._showHandler = show;
        this._hideHandler = hide;
        this._triggerEl   = trigger;
    }

    disposeTooltip() {
        if (this._tip) {
            this._tip.remove();
            this._tip = null;
        }
        if (this._triggerEl) {
            this._triggerEl.removeEventListener('mouseenter', this._showHandler);
            this._triggerEl.removeEventListener('mouseleave', this._hideHandler);
            this._triggerEl.removeEventListener('focusin',    this._showHandler);
            this._triggerEl.removeEventListener('focusout',   this._hideHandler);
            this._triggerEl = null;
        }
    }
}

// Register the custom element
customElements.define('nfsen-tooltip', NfsenTooltip);

export { NfsenTooltip };

