<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Ukumbusho wa Kila Wiki</h1>
                <p class="page-subtitle">
                    SMS za ukumbusho hutumwa kila Jumapili saa 8:00 mchana kwa wageni wote.
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="btn btn-secondary text-sm"
                    :disabled="loading"
                    @click="load"
                >
                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                    Refresh
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="sendingNow || !smsConfigured"
                    @click="showSendNowConfirm = true"
                >
                    <span v-if="sendingNow" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    <template v-else>
                        <Send class="h-4 w-4" />
                    </template>
                    Tuma Sasa
                </button>
            </div>
        </div>

        <div v-if="!smsConfigured" class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-600/20">
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div class="text-sm text-amber-900">
                <p class="font-semibold">SMS haijawekwa.</p>
                <p class="mt-0.5 text-amber-700">
                    Weka <code>NEXTSMS_USERNAME</code> na <code>NEXTSMS_PASSWORD</code> kwenye faili yako ya <code>.env</code>,
                    kisha utimize <code>php artisan config:clear</code>.
                </p>
            </div>
        </div>

        <div v-if="latestWeek" class="card">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="card-title">Wiki ya Mwisho: {{ latestWeek.year }} Wiki {{ latestWeek.week_number }}</h2>
                <p class="mt-0.5 text-xs text-slate-400">
                    {{ latestWeek.total_sent }} zimetumwa, {{ latestWeek.total_failed }} zimefaili.
                </p>
            </div>
            <div v-if="latestWeek.logs?.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[700px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Mgeni</th>
                            <th class="table-header">Kadi</th>
                            <th class="table-header">Simu</th>
                            <th class="table-header">Hali</th>
                            <th class="table-header">Tarehe</th>
                            <th class="table-header">Kosa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in latestWeek.logs" :key="log.id">
                            <td class="table-cell">
                                <p class="text-sm font-semibold text-slate-900">{{ log.guest_name }}</p>
                            </td>
                            <td class="table-cell">
                                <span class="font-mono text-sm font-bold text-slate-900">{{ log.card?.card_number || '—' }}</span>
                            </td>
                            <td class="table-cell font-mono text-sm text-slate-600">
                                {{ log.phone_e164 ? '+' + log.phone_e164 : '—' }}
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="log.status === 'sent' ? 'badge-success' : 'badge-danger'">
                                    {{ log.status === 'sent' ? 'Imetumwa' : 'Imefaili' }}
                                </span>
                            </td>
                            <td class="table-cell text-sm text-slate-500">
                                {{ formatTime(log.sent_at) }}
                            </td>
                            <td class="table-cell">
                                <p v-if="log.error" class="max-w-48 truncate text-xs text-rose-600" :title="log.error">
                                    {{ log.error }}
                                </p>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="flex flex-col items-center justify-center gap-3 py-12 text-center">
                <Bell class="h-10 w-10 text-slate-300" />
                <p class="text-sm text-slate-500">Hakuna ukumbusho kwa wiki hii bado.</p>
            </div>
        </div>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="card-title">Historia ya Ukumbusho</h2>
                <p class="mt-0.5 text-xs text-slate-400">Ukumbusho wote uliotumwa kwa wiki tofauti.</p>
            </div>

            <div v-if="loading && !history.data?.length" class="flex justify-center py-12">
                <span class="h-6 w-6 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
            </div>

            <div v-else-if="history.data?.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[850px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Mgeni</th>
                            <th class="table-header">Kadi</th>
                            <th class="table-header">Simu</th>
                            <th class="table-header">Wiki</th>
                            <th class="table-header">Hali</th>
                            <th class="table-header">Ujumbe</th>
                            <th class="table-header">Tarehe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in history.data" :key="log.id">
                            <td class="table-cell">
                                <p class="text-sm font-semibold text-slate-900">{{ log.guest_name }}</p>
                            </td>
                            <td class="table-cell">
                                <span class="font-mono text-sm font-bold text-slate-900">{{ log.card?.card_number || '—' }}</span>
                            </td>
                            <td class="table-cell font-mono text-sm text-slate-600">
                                {{ log.phone_e164 ? '+' + log.phone_e164 : '—' }}
                            </td>
                            <td class="table-cell">
                                <span class="text-sm text-slate-700">{{ log.year }} W{{ log.week_number }}</span>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="log.status === 'sent' ? 'badge-success' : 'badge-danger'">
                                    {{ log.status === 'sent' ? 'Imetumwa' : 'Imefaili' }}
                                </span>
                            </td>
                            <td class="table-cell">
                                <p class="max-w-64 truncate text-xs text-slate-500" :title="log.message_sent">
                                    {{ log.message_sent }}
                                </p>
                            </td>
                            <td class="table-cell text-sm text-slate-500">
                                {{ formatTime(log.sent_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <Bell class="h-10 w-10 text-slate-300" />
                <p class="font-semibold text-slate-700">Hakuna ukumbusho bado</p>
                <p class="text-sm text-slate-400">Ukumbusho wa kwanza utatumwa Jumapili ijayo saa 8:00 mchana.</p>
            </div>

            <div class="px-5 py-4">
                <Pagination :pagination="historyPagination" @change="changePage" />
            </div>
        </div>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="card-title">Jinsi Inavyofanya Kazi</h2>
            </div>
            <div class="space-y-4 px-5 py-5">
                <div class="flex items-start gap-3">
                    <Clock class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Automatiki kila Jumapili</p>
                        <p class="text-sm text-slate-500">SMS hutumwa saa 8:00 mchana kila wiki.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <Users class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Kwa wageni wote</p>
                        <p class="text-sm text-slate-500">Kila mgeni mwenye nambari ya simu sahihi atapokea ukumbusho.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <MessageSquare class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Taarifa kamili</p>
                        <p class="text-sm text-slate-500">Ujumbe unajumuisha tarehe, muda, mahali, na muhtasari wa sherehe.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <Ban class="mt-0.5 h-5 w-5 shrink-0 text-rose-600" />
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Hakuna kurudia</p>
                        <p class="text-sm text-slate-500">Kila mtu apokea SMS moja tu kwa wiki. Mfumo hautuma mara mbili.</p>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :open="showSendNowConfirm"
            title="Tuma Ukumbusho Sasa?"
            :message="`Hii itatuma SMS ukumbusho kwa wageni wote sasa hivi. Kila mgeni atapokea moja tu.`"
            confirm-label="Tuma Sasa"
            :loading="sendingNow"
            @confirm="doSendNow"
            @close="showSendNowConfirm = false"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import {
    AlertTriangle,
    Ban,
    Bell,
    Clock,
    MessageSquare,
    RefreshCw,
    Send,
    Users,
} from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import { weeklyRemindersService } from '../services/weeklyReminders';
import { invitationsService } from '../services/invitations';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const loading = ref(true);
const sendingNow = ref(false);
const showSendNowConfirm = ref(false);
const smsConfigured = ref(false);

const history = ref({ data: [] });
const historyPagination = ref(null);
const latestWeek = ref(null);

function formatTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('sw-TZ', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function load() {
    loading.value = true;
    try {
        const [reportData, historyData] = await Promise.all([
            invitationsService.report(),
            weeklyRemindersService.history({ per_page: 50 }),
        ]);
        smsConfigured.value = reportData.data?.configured?.sms ?? false;
        history.value = historyData.data;
        historyPagination.value = {
            current_page: historyData.data.current_page,
            last_page: historyData.data.last_page,
        };

        if (historyData.data.data?.length) {
            const firstLog = historyData.data.data[0];
            latestWeek.value = {
                year: firstLog.year,
                week_number: firstLog.week_number,
                total_sent: historyData.data.data.filter(
                    (l) => l.year === firstLog.year && l.week_number === firstLog.week_number && l.status === 'sent',
                ).length,
                total_failed: historyData.data.data.filter(
                    (l) => l.year === firstLog.year && l.week_number === firstLog.week_number && l.status === 'failed',
                ).length,
                logs: historyData.data.data.filter(
                    (l) => l.year === firstLog.year && l.week_number === firstLog.week_number,
                ),
            };
        }
    } catch {
        toast.error('Imeshindikana kupata historia ya ukumbusho.');
    } finally {
        loading.value = false;
    }
}

async function doSendNow() {
    sendingNow.value = true;
    try {
        const response = await weeklyRemindersService.sendNow();
        toast[response.success ? 'success' : 'warning'](response.message);
        showSendNowConfirm.value = false;
        await load();
    } catch (error) {
        toast.error(error.response?.data?.message || 'Imeshindikana kutuma ukumbusho.');
    } finally {
        sendingNow.value = false;
    }
}

function changePage(page) {
    if (historyPagination.value) historyPagination.value.current_page = page;
    weeklyRemindersService.history({ page, per_page: 50 }).then((data) => {
        history.value = data.data;
        historyPagination.value = {
            current_page: data.data.current_page,
            last_page: data.data.last_page,
        };
    });
}

onMounted(load);
</script>
