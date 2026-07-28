/**
 * Toast Message System
 * Shared toast notification manager for the Order Management System.
 * Used by: couriers.php, pending_order_list.php, and any other page.
 *
 * Usage:
 *   toastManager.success('Operation completed');
 *   toastManager.error('Something went wrong');
 *   toastManager.warning('Check your input');
 *   toastManager.info('Here is some info');
 */
class ToastManager {
    constructor() {
        this.createContainer();
    }

    createContainer() {
        if (!document.getElementById('toast-container')) {
            const container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
    }

    show(message, type = 'info', duration = 5000) {
        const container = document.getElementById('toast-container');
        const toast = this.createToast(message, type);

        container.appendChild(toast);

        // Trigger animation
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);

        // Auto remove
        if (duration > 0) {
            setTimeout(() => {
                this.remove(toast);
            }, duration);
        }

        return toast;
    }

    createToast(message, type) {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;

        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        const titles = {
            success: 'Success',
            error: 'Error',
            warning: 'Warning',
            info: 'Information'
        };

        toast.innerHTML = `
            <div class="toast-header">
                <i class="toast-icon ${icons[type] || icons.info}"></i>
                <span>${titles[type] || titles.info}</span>
                <button class="toast-close" onclick="toastManager.remove(this.closest('.toast'))">&times;</button>
            </div>
            <div class="toast-body">${message}</div>
        `;

        return toast;
    }

    remove(toast) {
        if (toast && toast.parentNode) {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }
    }

    success(message, duration = 5000) {
        return this.show(message, 'success', duration);
    }

    error(message, duration = 8000) {
        return this.show(message, 'error', duration);
    }

    warning(message, duration = 6000) {
        return this.show(message, 'warning', duration);
    }

    info(message, duration = 5000) {
        return this.show(message, 'info', duration);
    }
}

// Initialize global toast manager
const toastManager = new ToastManager();
