<template>
    <transition name="result">
        <div
            v-if="result"
            class="fixed inset-0 z-40 flex items-center justify-center p-4"
            :class="result.success ? 'bg-emerald-50/95 backdrop-blur-sm' : 'bg-rose-50/95 backdrop-blur-sm'"
        >
            <div class="w-full max-w-md animate-scale-in text-center">
                <div
                    class="mx-auto mb-6 flex h-28 w-28 items-center justify-center rounded-full shadow-lg"
                    :class="result.success ? 'bg-emerald-100 text-emerald-600 shadow-emerald-200' : 'bg-rose-100 text-rose-600 shadow-rose-200'"
                >
                    <CheckCircle2 v-if="result.success" class="h-14 w-14" stroke-width="1.5" />
                    <XCircle v-else class="h-14 w-14" stroke-width="1.5" />
                </div>

                <h2
                    class="text-2xl font-bold tracking-tight"
                    :class="result.success ? 'text-emerald-700' : 'text-rose-700'"
                >
                    {{ result.success ? 'ENTRY ALLOWED' : 'ENTRY DENIED' }}
                </h2>

                <p class="mt-3 text-xl font-bold text-slate-900">
                    {{ result.data?.guest?.full_name || 'Unknown' }}
                </p>
                <p v-if="result.data?.guest?.phone" class="mt-1 text-sm font-medium text-slate-500">
                    {{ result.data.guest.phone }}
                </p>

                <div v-if="result.data?.card" class="mt-6 space-y-3 text-sm">
                    <div class="mx-auto flex max-w-xs items-center justify-between gap-3 rounded-2xl bg-white/80 px-4 py-3 shadow-sm ring-1 ring-slate-200/60">
                        <span class="text-slate-500">Card Number</span>
                        <span class="font-mono font-semibold text-slate-900">{{ result.data.card.card_number }}</span>
                    </div>
                    <div class="mx-auto flex max-w-xs items-center justify-between gap-3 rounded-2xl bg-white/80 px-4 py-3 shadow-sm ring-1 ring-slate-200/60">
                        <span class="text-slate-500">Card Type</span>
                        <span class="font-semibold text-slate-900">{{ result.data.card.card_type_label }}</span>
                    </div>
                    <div class="mx-auto flex max-w-xs items-center justify-between gap-3 rounded-2xl bg-white/80 px-4 py-3 shadow-sm ring-1 ring-slate-200/60">
                        <span class="text-slate-500">Card Status</span>
                        <span class="font-semibold text-slate-900">{{ result.data.card.status_label }}</span>
                    </div>
                    <div class="mx-auto flex max-w-xs items-center justify-between gap-3 rounded-2xl bg-white/80 px-4 py-3 shadow-sm ring-1 ring-slate-200/60">
                        <span class="text-slate-500">Usage</span>
                        <span class="font-semibold text-slate-900">
                            {{ result.data.card.used_people }}/{{ result.data.card.allowed_people }}
                        </span>
                    </div>
                    <p
                        v-if="result.data.card.remaining > 0 && result.success"
                        class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-4 py-1.5 font-semibold text-emerald-700"
                    >
                        <Users class="h-4 w-4" />
                        Remaining people: {{ result.data.card.remaining }}
                    </p>
                </div>

                <div
                    class="mt-6 rounded-2xl px-4 py-3.5 text-sm font-medium"
                    :class="result.success ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                >
                    <template v-if="result.success">
                        <span class="inline-flex items-center gap-1.5">
                            <Sparkles class="h-4 w-4" />
                            Welcome!
                        </span>
                        <p v-if="result.data?.entry" class="mt-1.5 text-xs">
                            Entry attempt: #{{ result.data.entry.entry_number }}
                            <span v-if="result.data.entry.gate_name"> · {{ result.data.entry.gate_name }}</span>
                            <template v-if="result.data.entry.number_of_guests">
                                · {{ result.data.entry.number_of_guests }} people
                            </template>
                        </p>
                        <p v-if="result.data?.entry?.verification_method" class="mt-1 text-xs">
                            Method: {{ verificationMethodLabel(result.data.entry.verification_method) }}
                        </p>
                    </template>
                    <template v-else>
                        <p>Reason:</p>
                        <p class="mt-1 font-semibold">{{ result.message }}</p>
                    </template>
                </div>

                <button
                    type="button"
                    class="btn btn-primary mt-8 w-full py-4! text-base"
                    @click="$emit('reset')"
                >
                    <UserPlus class="h-5 w-5" />
                    NEW GUEST
                </button>
            </div>
        </div>
    </transition>
</template>

<script setup>
import { CheckCircle2, Sparkles, UserPlus, Users, XCircle } from '@lucide/vue';
import { verificationMethodLabel } from '../utils/labels';

defineProps({
    result: { type: Object, default: null },
});

defineEmits(['reset']);
</script>

<style scoped>
.result-enter-active,
.result-leave-active {
    transition: opacity 0.25s ease;
}

.result-enter-from,
.result-leave-to {
    opacity: 0;
}
</style>
