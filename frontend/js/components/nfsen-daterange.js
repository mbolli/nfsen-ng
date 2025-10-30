/**
 * NfsenDateRange Web Component
 * Encapsulates Ion.RangeSlider with Datastar integration
 */
export class NfsenDateRange extends HTMLElement {
    constructor() {
        super();
        this.slider = null;
        this.input = null;
    }

    connectedCallback() {
        this.render();
    }

    disconnectedCallback() {
        if (this.slider) {
            this.slider?.destroy();
            this.slider = null;
        }
    }

    render() {
        // Create input element for the slider
        this.innerHTML = '<input type="text" class="daterange-slider" />';
        this.input = this.querySelector('.daterange-slider');

        this.initializeSlider();
    }

    initializeSlider() {
        if (!window.jQuery || !window.jQuery.fn.ionRangeSlider) {
            console.warn('Ion.RangeSlider not available');
            return;
        }

        const min = parseInt(this.getAttribute('data-min') || Date.now() - 86400000);
        const max = parseInt(this.getAttribute('data-max') || Date.now());
        const from = parseInt(this.getAttribute('data-from') || min);
        const to = parseInt(this.getAttribute('data-to') || max);

        const $input = window.jQuery(this.input);

        $input.ionRangeSlider({
            type: 'double',
            grid: true,
            grid_num: 8,
            min: min,
            max: max,
            from: from,
            to: to,
            force_edges: true,
            drag_interval: true,
            prettify: (num) => {
                return new Date(num).toLocaleString();
            },
            onChange: (data) => {
                // Disable sync button when slider is manually changed
                const syncButton = document.querySelector('#date_syncing button.sync-date');
                if (syncButton) {
                    syncButton.disabled = true;
                }

                // Emit custom event for Datastar to catch
                this.dispatchEvent(
                    new CustomEvent('range-change', {
                        detail: {
                            from: data.from,
                            to: data.to,
                            fromDate: new Date(data.from),
                            toDate: new Date(data.to),
                        },
                        bubbles: true,
                        composed: true,
                    })
                );
            },
            onFinish: (data) => {
                // Disable sync button when user releases the slider
                const syncButton = document.querySelector('#date_syncing button.sync-date');
                if (syncButton) {
                    syncButton.disabled = true;
                }
            },
            onUpdate: (data) => {
                // Disable sync button when slider is programmatically updated
                const syncButton = document.querySelector('#date_syncing button.sync-date');
                if (syncButton) {
                    syncButton.disabled = true;
                }
            },
        });

        this.slider = $input.data('ionRangeSlider');
    }

    // Public method to update slider programmatically
    updateRange(from, to) {
        if (this.slider) {
            this.slider.update({
                from: from,
                to: to,
            });

            // Emit custom event for Datastar to catch
            this.dispatchEvent(
                new CustomEvent('range-change', {
                    detail: {
                        from: from,
                        to: to,
                        fromDate: new Date(from),
                        toDate: new Date(to),
                    },
                    bubbles: true,
                    composed: true,
                })
            );
        }
    }

    // Set range to a specific duration (adjusts 'from' while keeping 'to' fixed at current time)
    setQuickRange(durationSeconds) {
        if (!this.slider) return;

        // Set 'to' to max (current time) and calculate 'from' based on duration
        const newTo = this.slider.options.max;
        const newFrom = newTo - durationSeconds;

        this.updateRange(newFrom, newTo);
    }

    // Navigate forward or backward by current range duration
    navigate(direction) {
        if (!this.slider) return;

        const currentFrom = this.slider.result.from;
        const currentTo = this.slider.result.to;
        const duration = currentTo - currentFrom;

        if (direction === 'prev') {
            this.updateRange(currentFrom - duration, currentTo - duration);
        } else {
            // Convert to seconds for comparison
            const newTo = Math.min(currentTo + duration, this.slider.options.max);
            const newFrom = newTo - duration;
            this.updateRange(newFrom, newTo);
        }
    }

    // Jump to the end (most recent data) while keeping the same window size
    goToEnd() {
        if (!this.slider) return;

        const currentFrom = this.slider.result.from;
        const currentTo = this.slider.result.to;
        const duration = currentTo - currentFrom;

        // Set 'to' to max (current time) and 'from' to maintain the duration
        const newTo = this.slider.options.max;
        const newFrom = newTo - duration;

        this.updateRange(newFrom, newTo);
    }

    static get observedAttributes() {
        return ['data-min', 'data-max', 'data-from', 'data-to'];
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue !== newValue && this.isConnected && this.slider) {
            const updates = {};

            if (name === 'data-min') updates.min = parseInt(newValue);
            if (name === 'data-max') updates.max = parseInt(newValue);
            if (name === 'data-from') updates.from = parseInt(newValue);
            if (name === 'data-to') updates.to = parseInt(newValue);

            this.slider.update(updates);
        }
    }

    toJSON() {
        return this.tagName;
    }
}

customElements.define('nfsen-daterange', NfsenDateRange);
