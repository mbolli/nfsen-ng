/**
 * nfsen-ip-info-modal - Web component for displaying IP whois and host information
 *
 * Usage:
 *   <nfsen-ip-info-modal data-ip="192.168.1.1">
 *     <a href="#" class="ip-link">192.168.1.1</a>
 *   </nfsen-ip-info-modal>
 *
 * Attributes:
 *   - data-ip: The IP address to look up (required)
 */
class NfsenIpInfoModal extends HTMLElement {
    constructor() {
        super();
        this.modal = null;
        this.modalElement = null;
        this.ignoredFields = ['country_', 'timezone_', 'currency_'];
    }

    connectedCallback() {
        const ip = this.getAttribute('data-ip');
        if (!ip) {
            console.error('nfsen-ip-info-modal: data-ip attribute is required');
            return;
        }

        // Add click handler to the link
        const link = this.querySelector('a');
        if (link) {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.showModal(ip);
            });
        }
    }

    /**
     * Show modal and fetch data
     */
    showModal(ip) {
        this.createModal();
        this.modal.show();
        this.fetchAndDisplay(ip);
    }

    /**
     * Create modal structure
     */
    createModal() {
        // Create modal element if it doesn't exist
        if (!this.modalElement) {
            this.modalElement = document.createElement('div');
            this.modalElement.className = 'modal fade';
            this.modalElement.tabIndex = -1;
            this.modalElement.setAttribute('aria-labelledby', 'ipModalLabel');
            this.modalElement.setAttribute('aria-hidden', 'true');
            this.modalElement.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h1 class="modal-title fs-5" id="ipModalLabel">Loading...</h1>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="ip-modal-body">
                            <div class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">Loading</span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(this.modalElement);

            // Initialize Bootstrap modal
            this.modal = new bootstrap.Modal(this.modalElement);

            // Clean up when modal is hidden
            this.modalElement.addEventListener('hidden.bs.modal', () => {
                this.modalElement.remove();
                this.modalElement = null;
                this.modal = null;
            });
        }
    }

    /**
     * Fetch and display IP information
     */
    async fetchAndDisplay(ip) {
        const modalTitle = this.modalElement.querySelector('.modal-title');
        const modalBody = this.modalElement.querySelector('.modal-body');

        modalTitle.textContent = 'Info for IP: ' + ip;

        try {
            // Fetch IP whois data
            const ipWhoisResponse = await fetch('https://ipwhois.app/json/' + ip);
            const ipWhoisData = await ipWhoisResponse.json();

            // Create table
            let markup = '<table class="table table-striped table-hover">';
            for (const [key, value] of Object.entries(ipWhoisData)) {
                // Skip ignored fields
                if (this.ignoredFields.some((field) => key.startsWith(field))) continue;
                markup += '<tr><th>' + key + '</th><td>' + value + '</td></tr>';
            }
            markup += '</table>';

            // Add heading and flag
            let flag = ipWhoisData.country_flag
                ? '<img src="' +
                  ipWhoisData.country_flag +
                  '" alt="' +
                  ipWhoisData.country +
                  '" title="' +
                  ipWhoisData.country +
                  '" style="width: 3rem" />'
                : '';
            let heading = '<h3>' + ip + ' ' + flag + '</h3>';

            // Add placeholder for hostname.
            // The server's host action stores the resolved name in the _hostResult signal.
            // We bind data-text to that signal ID so Datastar updates the span when the
            // SSE pushes the result. A hidden trigger element fires the POST action.
            const cfg = window.__nfsen ?? {};
            const hostResultId = cfg.hostResultId ?? '_hostResult';
            const hostActionUrl = cfg.hostActionUrl;
            heading += '<div id="ip-host-result"><h4>Host: <span data-text="$' + hostResultId + '">Looking up\u2026</span></h4></div>';

            // Trigger the host action via a transient element with data-on:init.
            // Datastar's MutationObserver picks it up immediately; we remove it after 3 s.
            if (hostActionUrl) {
                const trigger = document.createElement('span');
                trigger.style.display = 'none';
                trigger.setAttribute('data-on:init', '@post(\'' + hostActionUrl + '?ip=' + encodeURIComponent(ip) + '\')');
                document.body.appendChild(trigger);
                setTimeout(() => { if (trigger.parentNode) trigger.remove(); }, 3000);
            }

            // Replace loader with content
            modalBody.innerHTML = heading + markup;
        } catch (error) {
            console.error('Error fetching IP data:', error);
            modalBody.innerHTML = '<div class="alert alert-danger">Error loading IP information</div>';
        }
    }
}

customElements.define('nfsen-ip-info-modal', NfsenIpInfoModal);
