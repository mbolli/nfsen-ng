/**
 * nfsen-ip-info-modal - Web component for IP info lookup.
 *
 * On click, posts to the server-side ip-info action. The server fetches geo data
 * and hostname, renders the full modal HTML, and pushes it as a Datastar
 * patchElements fragment into #ip-modal-placeholder. JS then calls Bootstrap .show().
 *
 * Usage:
 *   <nfsen-ip-info-modal data-ip="1.2.3.4">
 *     <a href="#">1.2.3.4</a>
 *   </nfsen-ip-info-modal>
 */
class NfsenIpInfoModal extends HTMLElement {
    connectedCallback() {
        const ip = this.getAttribute('data-ip');
        if (!ip) return;
        const link = this.querySelector('a');
        if (link) {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this._post(ip);
            });
        }
    }

    _post(ip) {
        const url = (window.__nfsen ?? {}).ipInfoActionUrl;
        if (!url) return;
        // Trigger a Datastar POST — the server renders and patches the modal HTML
        const trigger = document.createElement('span');
        trigger.style.display = 'none';
        trigger.setAttribute('data-init', '@post(\'' + url + '?ip=' + encodeURIComponent(ip) + '\')');
        document.body.appendChild(trigger);
        setTimeout(() => { if (trigger.parentNode) trigger.remove(); }, 3000);
    }
}

customElements.define('nfsen-ip-info-modal', NfsenIpInfoModal);
