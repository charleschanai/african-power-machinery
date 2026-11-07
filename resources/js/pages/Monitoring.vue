<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="page-title">System Monitoring</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Six checks that map to how this app actually breaks. Last checked {{ lastCheckedAt || 'never' }}.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                    <input v-model="autoRefresh" type="checkbox" class="rounded border-slate-300 text-emerald-600" />
                    Auto-refresh (30s)
                </label>

                <button class="btn btn-secondary" :disabled="loading" @click="load">
                    <RefreshCw :class="loading ? 'animate-spin' : ''" class="h-4 w-4" />
                    Refresh
                </button>

                <button class="btn btn-secondary" :disabled="pruning" @click="prune">
                    <Trash2 class="h-4 w-4" />
                    {{ pruning ? 'Pruning…' : 'Prune metrics' }}
                </button>

                <button class="btn btn-primary" :disabled="checking" @click="runChecks">
                    <Activity class="h-4 w-4" />
                    {{ checking ? 'Checking…' : 'Run checks now' }}
                </button>
            </div>
        </div>

        <!-- Overall banner -->
        <div v-if="loading && !data" class="card flex justify-center py-16">
            <div class="animate-spin rounded-full h-8 w-8 border-4 border-slate-200 border-t-emerald-600"></div>
        </div>

        <div v-else-if="error" class="card border-rose-200 bg-rose-50">
            <div class="flex items-start gap-3">
                <CircleAlert class="h-5 w-5 shrink-0 text-rose-600" />
                <div>
                    <p class="font-medium text-rose-800">Could not load monitoring data</p>
                    <p class="mt-1 text-sm text-rose-700">{{ error }}</p>
                </div>
            </div>
        </div>

        <template v-else-if="data">
            <!-- Status banner -->
            <div
                class="rounded-2xl p-5 ring-1"
                :class="{
                    'bg-emerald-50 ring-emerald-200': health.status === 'ok',
                    'bg-amber-50 ring-amber-200': health.status === 'degraded',
                    'bg-rose-50 ring-rose-200': health.status === 'down',
                }"
            >
                <div class="flex items-start gap-3">
                    <component
                        :is="health.status === 'ok' ? CircleCheck : health.status === 'degraded' ? TriangleAlert : OctagonAlert"
                        class="h-6 w-6 shrink-0"
                        :class="{
                            'text-emerald-600': health.status === 'ok',
                            'text-amber-600': health.status === 'degraded',
                            'text-rose-600': health.status === 'down',
                        }"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-lg font-semibold text-slate-900">
                            {{ health.status === 'ok' ? 'All systems operational' : health.status === 'degraded' ? 'Some checks need attention' : 'A check is failing' }}
                        </p>
                        <p class="mt-0.5 text-sm text-slate-600">
                            <template v-if="problemCount === 0">
                                All {{ checkList.length }} checks are healthy.
                            </template>
                            <template v-else>
                                {{ problemCount }} of {{ checkList.length }} checks
                                {{ problemCount === 1 ? 'needs' : 'need' }} attention.
                            </template>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Checks that need attention, shown in full -->
            <div v-if="problems.length" class="card">
                <h2 class="card-title">Needs attention</h2>

                <div class="mt-4 space-y-3">
                    <div
                        v-for="check in problems"
                        :key="check.name"
                        class="rounded-xl border p-4"
                        :class="rowClass(check.status)"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex h-2.5 w-2.5 rounded-full"
                                :class="dotClass(check.status)"
                                aria-hidden="true"
                            ></span>
                            <span class="font-medium capitalize text-slate-800">{{ title(check.name) }}</span>
                            <span class="badge" :class="badgeClass(check.status)">{{ check.status }}</span>
                        </div>

                        <dl v-if="checkPairs(check).length" class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                            <div v-for="pair in checkPairs(check)" :key="pair[0]">
                                <dt class="text-xs uppercase tracking-wide text-slate-400">{{ label(pair[0]) }}</dt>
                                <dd class="text-sm font-medium text-slate-700">{{ pair[1] }}</dd>
                            </div>
                        </dl>

                        <p v-if="check.reason" class="mt-2 text-sm font-medium" :class="check.status === 'down' ? 'text-rose-700' : 'text-amber-700'">
                            {{ check.reason }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Healthy checks, one compact line each -->
            <div class="card">
                <h2 class="card-title">Healthy</h2>

                <div v-if="healthy.length" class="mt-3 divide-y divide-slate-100">
                    <div
                        v-for="check in healthy"
                        :key="check.name"
                        class="flex flex-wrap items-center justify-between gap-2 py-2.5"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex h-2 w-2 rounded-full bg-emerald-500"
                                aria-hidden="true"
                            ></span>
                            <span class="text-sm font-medium text-slate-700">{{ title(check.name) }}</span>
                        </div>
                        <span class="text-xs text-slate-500">{{ headline(check) }}</span>
                    </div>
                </div>

                <p v-else class="mt-4 text-sm text-slate-500">Nothing is healthy right now.</p>
            </div>

            <!-- Recent events -->
            <div class="card">
                <h2 class="card-title">Recent events</h2>

                <div v-if="events.length" class="mt-4 space-y-2">
                    <div
                        v-for="event in events"
                        :key="event.id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="badge" :class="badgeClass(event.level)">{{ event.level }}</span>
                            <span class="truncate font-mono text-xs text-slate-600">{{ event.metric }}</span>
                            <span v-if="event.route" class="truncate font-mono text-xs text-slate-400">{{ event.route }}</span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-slate-500">
                            <span v-if="event.status_code">{{ event.status_code }}</span>
                            <span v-if="event.duration_ms">{{ event.duration_ms }}ms</span>
                            <span>{{ formatDateTime(event.created_at) }}</span>
                        </div>
                    </div>
                </div>

                <p v-else class="mt-6 text-center text-sm text-slate-500">
                    No warnings or errors recorded. Good.
                </p>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Activity,
    CircleAlert,
    CircleCheck,
    OctagonAlert,
    RefreshCw,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import monitoringService from '../services/monitoring';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const data = ref(null);
const loading = ref(false);
const checking = ref(false);
const pruning = ref(false);
const error = ref(null);
const lastCheckedAt = ref(null);
const autoRefresh = ref(false);

let timer = null;

const health = computed(() => data.value?.health ?? { status: 'unknown', checks: {} });
const events = computed(() => data.value?.events ?? []);
const checkList = computed(() =>
    Object.entries(health.value.checks ?? {}).map(([name, check]) => ({ name, ...check }))
);

/**
 * Problems first. An operator opening this page needs the failures in the first
 * glance, not after scrolling past six green rows.
 */
const problems = computed(() => checkList.value.filter((c) => c.status !== 'ok'));
const healthy = computed(() => checkList.value.filter((c) => c.status === 'ok'));
const problemCount = computed(() => problems.value.length);

const CHECK_LABELS = {
    database: 'Database',
    queue: 'Queues',
    disk: 'Disk space',
    performance: 'Performance',
    schedule: 'Scheduler',
    providers: 'WhatsApp / SMS',
};

function title(name) {
    return CHECK_LABELS[name] ?? name.replace(/_/g, ' ');
}

/**
 * The one number worth reading on a collapsed, healthy row. Everything else is
 * hidden until something actually breaks.
 */
function headline(check) {
    switch (check.name) {
        case 'database':
            return `${check.latency_ms ?? '—'}ms · ${formatMb(check.size_mb)}`;
        case 'queue': {
            const waiting = Object.values(check.queues ?? {}).reduce(
                (total, size) => total + (typeof size === 'number' ? size : 0),
                0
            );
            return `${check.failed ?? 0} failed · ${waiting} waiting`;
        }
        case 'disk':
            return `${formatMb(check.free_mb)} free`;
        case 'performance':
            return `${formatPercent(check.error_rate)} errors · ${check.slow_requests ?? 0} slow (1h)`;
        case 'schedule':
            return check.last_run
                ? `last ran ${check.minutes_since_run ?? 0} min ago`
                : 'no heartbeat yet';
        case 'providers':
            return `${(check.missing ?? []).length ? `missing ${check.missing.join(', ')}` : 'all channels configured'}`;
        default:
            return '';
    }
}

function formatMb(value) {
    if (value === null || value === undefined) return '—';

    return value >= 1024 ? `${(value / 1024).toFixed(2)} GB` : `${Math.round(value)} MB`;
}

function formatPercent(value) {
    if (value === null || value === undefined) return '—';

    return `${(Number(value) * 100).toFixed(1)}%`;
}

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await monitoringService.index();
        data.value = response.data.data;
        lastCheckedAt.value = new Date().toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    } catch (e) {
        error.value =
            e.response?.data?.message ||
            (e.response ? `Request failed (${e.response.status}).` : 'Could not reach the server.');
    } finally {
        loading.value = false;
    }
}

async function runChecks() {
    checking.value = true;

    try {
        const response = await monitoringService.runChecks();
        const result = response.data.data;

        toast[result.status === 'ok' ? 'success' : 'warning'](response.data.message);

        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Health check failed.');
    } finally {
        checking.value = false;
    }
}

async function prune() {
    if (!window.confirm('Delete metrics older than 30 days? This cannot be undone.')) return;

    pruning.value = true;

    try {
        const response = await monitoringService.prune(30);
        toast.success(response.data.message);
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not prune metrics.');
    } finally {
        pruning.value = false;
    }
}

/** Flatten a check into the scalar pairs worth showing. */
function checkPairs(check) {
    const skip = new Set(['status', 'reason', 'queues', 'missing']);
    const out = [];

    for (const [key, value] of Object.entries(check)) {
        if (skip.has(key)) continue;
        if (value === null || typeof value === 'object') continue;
        out.push([key, typeof value === 'boolean' ? (value ? 'yes' : 'no') : String(value)]);
    }

    return out;
}

function label(key) {
    return key.replace(/_/g, ' ');
}

function formatDateTime(value) {
    if (!value) return '—';

    return new Date(value.replace(' ', 'T')).toLocaleString('en-GB');
}

function rowClass(status) {
    if (status === 'down') return 'border-rose-200 bg-rose-50/50';
    if (status === 'degraded') return 'border-amber-200 bg-amber-50/50';

    return 'border-slate-200';
}

function dotClass(status) {
    if (status === 'down') return 'bg-rose-500';
    if (status === 'degraded') return 'bg-amber-500';

    return 'bg-emerald-500';
}

function badgeClass(status) {
    if (status === 'down') return 'badge-danger';
    if (status === 'degraded') return 'badge-warning';
    if (status === 'error' || status === 'critical') return 'badge-danger';
    if (status === 'warning') return 'badge-warning';

    return 'badge-success';
}

onMounted(() => {
    load();
});

onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});

// Poll only while the tab is visible: a hidden tab burning API calls every
// 30 seconds helps nobody.
watch(autoRefresh, (enabled) => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }

    if (enabled) {
        timer = setInterval(() => {
            if (document.visibilityState === 'visible') load();
        }, 30000);
    }
});
</script>
