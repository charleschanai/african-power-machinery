import { defineStore } from 'pinia';

let id = 0;

export const useToastStore = defineStore('toast', {
    state: () => ({
        toasts: [],
    }),

    actions: {
        success(message) {
            this.push('success', message);
        },
        error(message) {
            this.push('error', message);
        },
        warning(message) {
            this.push('warning', message);
        },
        info(message) {
            this.push('info', message);
        },
        push(type, message) {
            const toast = { id: ++id, type, message };
            this.toasts.push(toast);
            setTimeout(() => this.remove(toast.id), 4500);
        },
        remove(toastId) {
            this.toasts = this.toasts.filter((t) => t.id !== toastId);
        },
    },
});
