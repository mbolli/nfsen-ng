/**
 * nfsen-tooltip Web Component
 * A reusable tooltip component with optional info icon
 *
 * Usage:
 * <nfsen-tooltip text="Your tooltip text here"></nfsen-tooltip>
 * <nfsen-tooltip text="Tooltip without icon" no-icon></nfsen-tooltip>
 *
 * Attributes:
 * - text: The tooltip content (required)
 * - placement: Tooltip placement (default: "right") - top, bottom, left, right, auto
 * - no-icon: If present, wraps the slotted content instead of showing info icon
 */
class NfsenTooltip extends HTMLElement {
    constructor() {
        super();
        this.tooltipInstance = null;
    }

    connectedCallback() {
        this.render();
        // Use setTimeout to ensure Bootstrap is loaded and DOM is ready
        setTimeout(() => this.initTooltip(), 0);
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
            // Add tooltip attributes directly to the first child element
            const firstChild = this.firstElementChild;
            if (firstChild) {
                firstChild.classList.add('nfsen-tooltip-trigger');
                firstChild.setAttribute('data-bs-toggle', 'tooltip');
                firstChild.setAttribute('data-bs-placement', placement);
                firstChild.setAttribute('title', this.escapeHtml(text));
            }
        } else {
            // Show info icon with tooltip
            this.innerHTML = `
                <span 
                    class="nfsen-tooltip-trigger" 
                    aria-hidden="true" 
                    data-bs-toggle="tooltip" 
                    data-bs-placement="${placement}" 
                    title="${this.escapeHtml(text)}"
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
        if (trigger && typeof bootstrap !== 'undefined') {
            this.tooltipInstance = new bootstrap.Tooltip(trigger);
        }
    }

    disposeTooltip() {
        if (this.tooltipInstance) {
            this.tooltipInstance.dispose();
            this.tooltipInstance = null;
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Register the custom element
customElements.define('nfsen-tooltip', NfsenTooltip);

export { NfsenTooltip };
