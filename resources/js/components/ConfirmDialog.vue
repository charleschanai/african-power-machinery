<template>
    <Teleport to="body">
        <transition name="dialog">
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
                @click.self="close"
            >
                <div class="w-full max-w-md animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 ring-1 ring-inset ring-amber-600/20">
                            <TriangleAlert class="h-5 w-5" />
                        </span>
                        <div class="flex-1">
                            <h3 class="text-base font-bold tracking-tight text-slate-900">{{ title }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ message }}</p>
                        </div>
                    </div>
                    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn btn-secondary" @click="close">
                            {{ cancelLabel }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger"
                            :disabled="loading"
                            @click="confirm"
                        >
                            <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            <template v-else>
                                <Check class="h-4 w-4" />
                            </template>
                            {{ confirmLabel }}
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<script setup>
import { Check, TriangleAlert } from '@lucide/vue';

defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    message: { type: String, default: 'This action cannot be undone.' },
    confirmLabel: { type: String, default: 'Confirm' },
    cancelLabel: { type: String, default: 'Cancel' },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'close']);

function confirm() {
    emit('confirm');
}

function close() {
    if (loading) return;
    emit('close');
}
</script>

<style scoped>
.dialog-enter-active,
.dialog-leave-active {
    transition: opacity 0.2s ease;
}

.dialog-enter-from,
.dialog-leave-to {
    opacity: 0;
}
</style>
