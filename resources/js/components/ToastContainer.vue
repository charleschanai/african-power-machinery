<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4">
        <transition-group name="toast">
            <div
                v-for="toast in toasts.toasts"
                :key="toast.id"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl bg-white p-4 text-sm font-medium shadow-lg shadow-slate-900/10 ring-1 animate-slide-up"
                :class="ringFor(toast.type)"
            >
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full" :class="iconBgFor(toast.type)">
                    <component :is="iconFor(toast.type)" class="h-3.5 w-3.5" stroke-width="2.5" />
                </span>
                <p class="flex-1 text-slate-800">{{ toast.message }}</p>
                <button
                    type="button"
                    class="rounded-md p-0.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                    @click="toasts.remove(toast.id)"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        </transition-group>
    </div>
</template>

<script setup>
import { CheckCircle2, Info, TriangleAlert, X, XCircle } from '@lucide/vue';
import { useToastStore } from '../stores/toast';

const toasts = useToastStore();

function ringFor(type) {
    const styles = {
        success: 'ring-emerald-200',
        error: 'ring-rose-200',
        warning: 'ring-amber-200',
        info: 'ring-sky-200',
    };
    return styles[type] || styles.info;
}

function iconBgFor(type) {
    const styles = {
        success: 'bg-emerald-50 text-emerald-600',
        error: 'bg-rose-50 text-rose-600',
        warning: 'bg-amber-50 text-amber-600',
        info: 'bg-sky-50 text-sky-600',
    };
    return styles[type] || styles.info;
}

function iconFor(type) {
    const icons = {
        success: CheckCircle2,
        error: XCircle,
        warning: TriangleAlert,
        info: Info,
    };
    return icons[type] || icons.info;
}
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.3s ease;
}

.toast-enter-from {
    opacity: 0;
    transform: translateY(-12px) scale(0.98);
}

.toast-leave-to {
    opacity: 0;
    transform: translateY(-12px) scale(0.98);
}
</style>
