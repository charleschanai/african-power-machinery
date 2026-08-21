<template>
    <div
        v-if="pagination && pagination.last_page > 1"
        class="mt-5 flex flex-col items-center justify-between gap-3 border-t border-slate-100 pt-4 sm:flex-row"
    >
        <p class="text-xs text-slate-500">
            Page <span class="font-semibold text-slate-700">{{ pagination.current_page }}</span>
            of <span class="font-semibold text-slate-700">{{ pagination.last_page }}</span>
        </p>
        <div class="flex items-center gap-2">
            <button
                type="button"
                class="btn btn-secondary px-3! py-1.5! text-xs"
                :disabled="pagination.current_page <= 1"
                @click="$emit('change', pagination.current_page - 1)"
            >
                <ChevronLeft class="h-4 w-4" />
                Previous
            </button>
            <div class="flex items-center gap-1">
                <button
                    v-for="page in visiblePages"
                    :key="page"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-sm font-semibold transition"
                    :class="page === pagination.current_page ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100'"
                    @click="$emit('change', page)"
                >
                    {{ page }}
                </button>
            </div>
            <button
                type="button"
                class="btn btn-secondary px-3! py-1.5! text-xs"
                :disabled="pagination.current_page >= pagination.last_page"
                @click="$emit('change', pagination.current_page + 1)"
            >
                Next
                <ChevronRight class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from '@lucide/vue';

const props = defineProps({
    pagination: { type: Object, default: null },
});

defineEmits(['change']);

const visiblePages = computed(() => {
    if (!props.pagination) return [];
    const current = props.pagination.current_page;
    const last = props.pagination.last_page;
    const start = Math.max(1, current - 2);
    const end = Math.min(last, current + 2);
    const pages = [];
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
});
</script>
