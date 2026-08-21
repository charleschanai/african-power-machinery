<template>
    <div class="flex items-center gap-3">
        <div
            class="shrink-0 overflow-hidden rounded-full shadow-lg shadow-emerald-900/40 ring-2 ring-emerald-500/60"
            :class="sizeClass"
        >
            <img :src="theWedding" alt="Save the Wedding" class="h-full w-full object-cover" />
        </div>
        <div v-if="showText" class="min-w-0">
            <p class="text-sm font-bold tracking-tight text-white" :class="titleClass">
                E-Card System
            </p>
            <p class="text-xs text-slate-400">Guest Entry Management</p>
        </div>
    </div>
</template>

<script setup>
import theWedding from '../../images/the-wedding.jpg';

defineProps({
    showText: { type: Boolean, default: true },
    sizeClass: { type: String, default: 'h-10 w-10' },
    titleClass: { type: String, default: '' },
});
</script>
