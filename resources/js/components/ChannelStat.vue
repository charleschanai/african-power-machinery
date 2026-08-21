<template>
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold tracking-tight text-slate-900">{{ title }}</h3>
            <span
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider"
                :class="toneClass"
            >
                <Send class="h-3 w-3" />
                {{ sent }}
            </span>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-xl bg-white px-2 py-3 ring-1 ring-inset ring-slate-200">
                <p class="text-lg font-black text-slate-700">{{ delivered }}</p>
                <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Delivered</p>
            </div>
            <div class="rounded-xl bg-rose-50 px-2 py-3 ring-1 ring-inset ring-rose-600/10">
                <p class="text-lg font-black text-rose-700">{{ failed }}</p>
                <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wider text-rose-600/70">Failed</p>
            </div>
            <div class="rounded-xl bg-slate-50 px-2 py-3 ring-1 ring-inset ring-slate-500/10">
                <p class="text-lg font-black text-slate-700">{{ pending }}</p>
                <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500/70">Pending</p>
            </div>
        </div>

        <div class="mt-3 flex items-center justify-center gap-1.5 text-[11px] font-medium text-slate-500">
            <span class="inline-flex items-center gap-1">
                <CheckCircle2 class="h-3.5 w-3.5 text-slate-400" />
                Sent: {{ sent }}
            </span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { CheckCircle2, Send } from '@lucide/vue';

const props = defineProps({
    title: { type: String, required: true },
    sent: { type: Number, default: 0 },
    delivered: { type: Number, default: 0 },
    failed: { type: Number, default: 0 },
    pending: { type: Number, default: 0 },
    tone: { type: String, default: 'emerald' },
});

const tones = {
    emerald: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/10',
    indigo: 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-600/10',
    slate: 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-500/10',
};

const toneClass = computed(() => tones[props.tone] || tones.emerald);
</script>
