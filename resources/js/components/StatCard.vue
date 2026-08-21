<template>
    <div
        class="group relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md hover:shadow-slate-200/60"
    >
        <div
            v-if="tone !== 'none'"
            class="absolute -right-6 -top-6 h-20 w-20 rounded-full opacity-10 transition-opacity duration-200 group-hover:opacity-20"
            :class="toneBg"
        ></div>

        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-slate-500">{{ label }}</p>
                <p class="mt-1.5 text-3xl font-bold tracking-tight text-slate-900" :class="valueClass">
                    {{ value }}
                </p>
                <p v-if="hint" class="mt-1 text-sm text-slate-500">{{ hint }}</p>
            </div>
            <div
                v-if="icon"
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-110"
                :class="iconClass"
            >
                <component :is="icon" class="h-6 w-6" stroke-width="2" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    hint: { type: String, default: null },
    valueClass: { type: String, default: '' },
    icon: { type: [Object, Function], default: null },
    tone: {
        type: String,
        default: 'none',
        validator: (v) => ['none', 'emerald', 'sky', 'amber', 'rose', 'indigo', 'slate'].includes(v),
    },
});

const tones = {
    emerald: { bg: 'bg-emerald-100', icon: 'bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10' },
    sky: { bg: 'bg-sky-100', icon: 'bg-sky-50 text-sky-600 ring-1 ring-inset ring-sky-600/10' },
    amber: { bg: 'bg-amber-100', icon: 'bg-amber-50 text-amber-600 ring-1 ring-inset ring-amber-600/10' },
    rose: { bg: 'bg-rose-100', icon: 'bg-rose-50 text-rose-600 ring-1 ring-inset ring-rose-600/10' },
    indigo: { bg: 'bg-indigo-100', icon: 'bg-indigo-50 text-indigo-600 ring-1 ring-inset ring-indigo-600/10' },
    slate: { bg: 'bg-slate-100', icon: 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-500/10' },
    none: { bg: '', icon: '' },
};

const toneBg = computed(() => tones[props.tone]?.bg || '');
const iconClass = computed(() => tones[props.tone]?.icon || '');
</script>
