import { defineStore } from 'pinia';

let id = 0;

export const useToastStore = defineStore('toast', {
    state: () => ({
        toasts: [],
    }),

    actions: {
        success(message, duration) {
            this.push('success', message, duration);
        },
        error(message, duration) {
            this.push('error', message, duration);
        },
        warning(message, duration) {
            this.push('warning', message, duration);
        },
        info(message, duration) {
            this.push('info', message, duration);
        },
        /**
         * @param {number} [duration] Milliseconds to stay on screen. Long
         *   messages, such as a phone number that needs explaining, would
         *   otherwise disappear before they have been read.
         */
        push(type, message, duration = 4500) {
            const toast = { id: ++id, type, message };
            this.toasts.push(toast);
            setTimeout(() => this.remove(toast.id), duration);
        },
        remove(toastId) {
            this.toasts = this.toasts.filter((t) => t.id !== toastId);
        },
    },
});
