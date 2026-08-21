<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle">Real-time statistics for the E-Card system.</p>
            </div>
            <button
                type="button"
                class="btn btn-secondary text-sm"
                :disabled="loading"
                @click="load"
            >
                <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                Refresh
            </button>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <div class="flex flex-col items-center gap-3 text-slate-400">
                <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
                <p class="text-sm">Loading statistics...</p>
            </div>
        </div>

        <template v-else-if="stats">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                    label="Total Guests"
                    :value="stats.totals.guests"
                    :icon="UsersRound"
                    tone="emerald"
                />
                <StatCard
                    label="Total Cards"
                    :value="stats.totals.cards"
                    :icon="CreditCard"
                    tone="indigo"
                />
                <StatCard
                    label="People Entered"
                    :value="stats.totals.entered_people"
                    :icon="UserCheck"
                    tone="sky"
                />
                <StatCard
                    label="Denied"
                    :value="stats.totals.denied_entries"
                    :icon="UserX"
                    tone="rose"
                />
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                    label="Card Templates"
                    :value="stats.totals.templates"
                    :icon="LayoutTemplate"
                    tone="emerald"
                />
                <StatCard
                    label="Single"
                    :value="stats.totals.single_cards"
                    :icon="Layers"
                    tone="emerald"
                />
                <StatCard
                    label="Double"
                    :value="stats.totals.double_cards"
                    :icon="Copy"
                    tone="indigo"
                />
                <StatCard
                    label="Active Cards"
                    :value="stats.totals.active_cards"
                    :icon="Zap"
                    tone="amber"
                />
                <StatCard
                    label="Used Cards"
                    :value="stats.totals.used_cards"
                    :icon="CheckCheck"
                    tone="slate"
                />
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="card p-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="card-title">Guests Entered per Hour</h2>
                            <p class="mt-0.5 text-xs text-slate-400">Hours of the day (00:00 - 23:00)</p>
                        </div>
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10">
                            <Clock class="h-4 w-4" />
                        </span>
                    </div>

                    <div class="mt-6 flex h-48 items-end gap-1.5">
                        <div
                            v-for="(value, index) in stats.entries_by_hour.values"
                            :key="index"
                            class="group relative flex-1 rounded-t-md bg-gradient-to-t from-emerald-600 to-emerald-400 transition-all duration-300 hover:from-emerald-700 hover:to-emerald-500"
                            :style="{ height: barHeight(value, stats.entries_by_hour.values) }"
                        >
                            <span
                                class="pointer-events-none absolute -top-7 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[10px] font-semibold text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100"
                            >
                                {{ value }} guest
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card p-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title">Entry Results</h2>
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-50 text-rose-600 ring-1 ring-inset ring-rose-600/10">
                            <Activity class="h-4 w-4" />
                        </span>
                    </div>

                    <div class="mt-6 space-y-4">
                        <div v-for="item in stats.result_breakdown" :key="item.label">
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="text-slate-600">{{ item.label }}</span>
                                <span class="font-bold text-slate-900">{{ item.value }}</span>
                            </div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200/60">
                                <div
                                    class="h-full rounded-full transition-all duration-500"
                                    :class="item.label === 'Allowed' ? 'bg-gradient-to-r from-emerald-600 to-emerald-400' : 'bg-gradient-to-r from-rose-600 to-rose-400'"
                                    :style="{ width: percentage(item.value) }"
                                ></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8">
                        <h2 class="card-title">Card Breakdown</h2>
                        <div v-if="stats.type_breakdown" class="mt-5 space-y-4">
                            <div v-for="(item, index) in stats.type_breakdown" :key="item.label">
                                <div class="mb-1.5 flex items-center justify-between text-sm">
                                    <span class="text-slate-600">{{ item.label }}</span>
                                    <span class="font-bold text-slate-900">{{ item.value }}</span>
                                </div>
                                <div class="h-2.5 overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200/60">
                                    <div
                                        class="h-full rounded-full transition-all duration-500"
                                        :class="index === 0 ? 'bg-gradient-to-r from-emerald-600 to-emerald-400' : 'bg-gradient-to-r from-indigo-600 to-indigo-400'"
                                        :style="{ width: typePercentage(item.value) }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8">
                        <h2 class="card-title">Single vs. Double</h2>
                        <div class="mt-5 flex items-center justify-center gap-10">
                            <div class="flex flex-col items-center">
                                <div
                                    class="relative flex h-28 w-28 items-center justify-center rounded-full"
                                    :style="{ background: `conic-gradient(#10b981 ${singlePercent}%, #d1fae5 0)` }"
                                >
                                    <div class="flex h-[76px] w-[76px] flex-col items-center justify-center rounded-full bg-white shadow-inner">
                                        <span class="text-xl font-bold text-emerald-700">{{ singlePercent }}%</span>
                                        <span class="text-[10px] text-slate-400">Single</span>
                                    </div>
                                </div>
                                <p class="mt-3 text-sm font-bold text-slate-900">{{ stats.totals.single_cards }}</p>
                            </div>
                            <div class="flex flex-col items-center">
                                <div
                                    class="relative flex h-28 w-28 items-center justify-center rounded-full"
                                    :style="{ background: `conic-gradient(#6366f1 ${doublePercent}%, #e0e7ff 0)` }"
                                >
                                    <div class="flex h-[76px] w-[76px] flex-col items-center justify-center rounded-full bg-white shadow-inner">
                                        <span class="text-xl font-bold text-indigo-700">{{ doublePercent }}%</span>
                                        <span class="text-[10px] text-slate-400">Double</span>
                                    </div>
                                </div>
                                <p class="mt-3 text-sm font-bold text-slate-900">{{ stats.totals.double_cards }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="card-title">Recent Activity</h2>
                        <p class="mt-0.5 text-xs text-slate-400">Latest entry attempts</p>
                    </div>
                    <router-link
                        to="/entry-logs"
                        class="text-xs font-semibold text-emerald-600 transition hover:text-emerald-700"
                    >
                        View all
                    </router-link>
                </div>

                <div v-if="stats.recent_logs.length">
                    <div
                        v-for="log in stats.recent_logs"
                        :key="log.id"
                        class="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors duration-150 hover:bg-slate-50/70 sm:px-6"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                :class="log.result === 'allowed' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'"
                            >
                                <LogIn v-if="log.result === 'allowed'" class="h-4 w-4" />
                                <Ban v-else class="h-4 w-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ log.guest_name }}</p>
                                <p class="text-xs text-slate-500">{{ log.card_number }} · {{ log.processor_name }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span
                                class="badge"
                                :class="log.result === 'allowed' ? 'badge-success' : 'badge-danger'"
                            >
                                {{ log.result_label }}
                            </span>
                            <span class="hidden whitespace-nowrap text-xs text-slate-400 sm:block">
                                {{ log.scanned_at }}
                            </span>
                        </div>
                    </div>
                </div>
                <div v-else class="flex flex-col items-center justify-center gap-2 py-14 text-slate-400">
                    <ClipboardList class="h-10 w-10" stroke-width="1.5" />
                    <p class="text-sm">No activity yet.</p>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import {
    Activity,
    Ban,
    CheckCheck,
    ClipboardList,
    Clock,
    Copy,
    CreditCard,
    Layers,
    LayoutTemplate,
    LogIn,
    RefreshCw,
    UserCheck,
    UsersRound,
    UserX,
    Zap,
} from '@lucide/vue';
import StatCard from '../components/StatCard.vue';
import { dashboardService } from '../services/dashboard';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();
const stats = ref(null);
const loading = ref(true);

async function load() {
    loading.value = true;
    try {
        stats.value = await dashboardService.statistics();
    } catch {
        toast.error('Failed to load statistics.');
    } finally {
        loading.value = false;
    }
}

function barHeight(value, values) {
    const max = Math.max(...values, 1);
    return `${Math.max(6, Math.round((value / max) * 100))}%`;
}

function percentage(value) {
    const total = stats.value.result_breakdown.reduce((sum, item) => sum + item.value, 0);
    if (total === 0) return '0%';
    return `${Math.round((value / total) * 100)}%`;
}

function typePercentage(value) {
    const total = stats.value.type_breakdown.reduce((sum, item) => sum + item.value, 0);
    if (total === 0) return '0%';
    return `${Math.round((value / total) * 100)}%`;
}

const singlePercent = computed(() => {
    const total = stats.value.totals.single_cards + stats.value.totals.double_cards;
    if (total === 0) return 0;
    return Math.round((stats.value.totals.single_cards / total) * 100);
});

const doublePercent = computed(() => {
    const total = stats.value.totals.single_cards + stats.value.totals.double_cards;
    if (total === 0) return 0;
    return Math.round((stats.value.totals.double_cards / total) * 100);
});

onMounted(load);
</script>
