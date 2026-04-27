/**
 * Toast Notification Web Component
 * Displays Bootstrap-styled alert messages with auto-dismiss functionality
 * Each instance is a single toast that removes itself when dismissed
 */
class NfsenToast extends HTMLElement {
    constructor() {
        super();
        this.autoDismissDelay = 5000; // 5 seconds for success messages
    }

    connectedCallback() {
        // Get data from attributes
        const type = this.dataset.type || 'info';
        const message = this.dataset.message || '';
        const autoDismiss = this.dataset.autoDismiss === 'true';

        // Render the toast
        this.render(type, message);

        // Auto-dismiss if requested
        if (autoDismiss) {
            setTimeout(() => {
                this.dismiss();
            }, this.autoDismissDelay);
        }
    }

    /**
     * Render the toast as a Bootstrap alert
     */
    render(type, message) {
        const alertType = type === 'error' ? 'danger' : type;
        const icon = this.getIcon(type);

        this.innerHTML = `
            <div class="alert alert-${alertType} alert-dismissible fade show" role="alert">
                ${icon}&nbsp;${message}
                <button type="button" class="btn-close" aria-label="Close"></button>
            </div>
        `;

        // Add click handler for close button
        const closeBtn = this.querySelector('.btn-close');
        closeBtn.addEventListener('click', () => {
            this.dismiss();
        });
    }

    /**
     * Get Bootstrap icon for message type
     */
    getIcon(type) {
        const icons = {
            success:
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check-circle-fill" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>',
            error: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-triangle-fill" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>',
            warning:
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-triangle-fill" viewBox="0 0 16 16"><path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-info-circle-fill" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16m.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2"/></svg>',
        };
        return icons[type] || icons.info;
    }

    /**
     * Dismiss this toast with animation and remove from DOM
     */
    dismiss() {
        const alert = this.querySelector('.alert');
        if (alert) {
            alert.classList.remove('show');
        }
        setTimeout(() => {
            this.dispatchEvent(new CustomEvent('nfsen-toast-dismissed', { bubbles: true }));
            this.remove();
        }, 150); // Match Bootstrap's fade transition time
    }
}

// Define the custom element
customElements.define('nfsen-toast', NfsenToast);

// Global helper function for client-side usage
// containerSelector: optional CSS selector for the target container (should have data-ignore-morph)
window.showMessage = function (type, message, autoDismiss = false, containerSelector = null) {
    // Create a new toast element
    const toast = document.createElement('nfsen-toast');
    toast.dataset.type = type;
    toast.dataset.message = message;
    if (autoDismiss) {
        toast.dataset.autoDismiss = 'true';
    }

    // Prefer the specified container, then fall back to body
    const container =
        (containerSelector && document.querySelector(containerSelector)) || document.body;
    container.appendChild(toast);
};
