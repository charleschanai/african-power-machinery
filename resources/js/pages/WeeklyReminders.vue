<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Weekly Reminders</h1>
                <p class="page-subtitle">
                    Reminder SMS are sent to all guests every Sunday at 8:00 AM.
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="btn btn-secondary text-sm"
                    :disabled="loading"
                    title="Reload reminders"
                    @click="load"
                >
                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                    Refresh
                </button>
                <button
                    type="button"
                    class="btn btn-primary text-sm"
                    :disabled="sendingNow"
                    :title="smsConfigured ? 'Send this week\'s reminders immediately' : 'SMS is not configured yet'"
                    @click="onSendNowClick"
                >
                    <span v-if="sendingNow" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    <Send v-else class="h-4 w-4" />
                    Send Now
                </button>
            </div>
        </div>

        <div v-if="!smsConfigured" class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-600/20">
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div class="text-sm text-amber-900">
                <p class="font-semibold">SMS is not configured.</p>
                <p class="mt-0.5 text-amber-700">
                    Set <code>NEXTSMS_USERNAME</code> and <code>NEXTSMS_PASSWORD</code> in your <code>.env</code> file,
                    then run <code>php artisan config:clear</code>.
                </p>
            </div>
        </div>

        <div v-if="latestWeek" class="grid gap-4 sm:grid-cols-3">
            <StatCard
                label="Latest week"
                :value="`Week ${latestWeek.week_number}`"
                :hint="String(latestWeek.year)"
                :icon="CalendarRange"
                tone="indigo"
            />
            <StatCard
                label="Delivered"
                :value="latestWeek.total_sent"
                hint="SMS sent successfully"
                :icon="CircleCheck"
                tone="emerald"
            />
            <StatCard
                label="Failed"
                :value="latestWeek.total_failed"
                hint="Could not be delivered"
                :icon="TriangleAlert"
                tone="rose"
            />
        </div>

        <div v-if="latestWeek" class="card">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="card-title">Latest Week Details</h2>
                <p class="mt-0.5 text-xs text-slate-400">
                    Delivery log for week {{ latestWeek.week_number }} of {{ latestWeek.year }}.
                </p>
            </div>
            <div v-if="latestWeek.logs?.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[700px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Guest</th>
                            <th class="table-header">Card</th>
                            <th class="table-header">Phone</th>
                            <th class="table-header">Status</th>
                            <th class="table-header">Sent at</th>
                            <th class="table-header">Error</th>
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
                                    {{ log.status === 'sent' ? 'Delivered' : 'Failed' }}
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
                <Bell class="h-10 w-10 text-slate-300" stroke-width="1.5" />
                <p class="text-sm text-slate-500">No reminders for this week yet.</p>
            </div>
        </div>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="card-title">Reminder History</h2>
                <p class="mt-0.5 text-xs text-slate-400">Every reminder sent across different weeks.</p>
            </div>

            <div v-if="loading && !history.data?.length" class="flex justify-center py-12">
                <span class="h-6 w-6 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
            </div>

            <div v-else-if="history.data?.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[850px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Guest</th>
                            <th class="table-header">Card</th>
                            <th class="table-header">Phone</th>
                            <th class="table-header">Week</th>
                            <th class="table-header">Status</th>
                            <th class="table-header">Message</th>
                            <th class="table-header">Sent at</th>
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
                                <span class="text-sm text-slate-700">{{ log.year }} · W{{ log.week_number }}</span>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="log.status === 'sent' ? 'badge-success' : 'badge-danger'">
                                    {{ log.status === 'sent' ? 'Delivered' : 'Failed' }}
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
                <Bell class="h-10 w-10 text-slate-300" stroke-width="1.5" />
                <p class="font-semibold text-slate-700">No reminders yet</p>
                <p class="max-w-sm text-sm text-slate-400">
                    The first reminder will go out next Sunday at 8:00 AM, or send one now with the button above.
                </p>
            </div>

            <div class="px-5 py-4">
                <Pagination :pagination="historyPagination" @change="changePage" />
            </div>
        </div>

        <div class="card">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="card-title">How It Works</h2>
            </div>
            <div class="grid gap-5 px-5 py-5 sm:grid-cols-2">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 ring-1 ring-inset ring-emerald-600/10">
                        <Clock class="h-4.5 w-4.5 text-emerald-600" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Automatic every Sunday</p>
                        <p class="text-sm text-slate-500">Reminder SMS are sent at 8:00 AM each week.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 ring-1 ring-inset ring-emerald-600/10">
                        <Users class="h-4.5 w-4.5 text-emerald-600" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">All guests</p>
                        <p class="text-sm text-slate-500">Every guest with a valid phone number receives a reminder.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 ring-1 ring-inset ring-emerald-600/10">
                        <MessageSquare class="h-4.5 w-4.5 text-emerald-600" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Complete details</p>
                        <p class="text-sm text-slate-500">Each message includes the date, time, venue and event summary.</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rose-50 ring-1 ring-inset ring-rose-600/10">
                        <Ban class="h-4.5 w-4.5 text-rose-600" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">No duplicates</p>
                        <p class="text-sm text-slate-500">Each person receives only one SMS per week. The system never sends twice.</p>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :open="showSendNowConfirm"
            title="Send Reminders Now?"
            message="This will send reminder SMS to all guests right away. Each guest receives only one."
            confirm-label="Send Now"
            :loading="sendingNow"
            @confirm="doSendNow"
            @close="showSendNowConfirm = false"
        />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import {
    AlertTriangle,
    Ban,
    Bell,
    CalendarRange,
    CircleCheck,
    Clock,
    MessageSquare,
    RefreshCw,
    Send,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import StatCard from '../components/StatCard.vue';
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
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
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
        toast.error('Failed to load reminder history.');
    } finally {
        loading.value = false;
    }
}

function onSendNowClick() {
    if (!smsConfigured.value) {
        toast.warning('SMS is not configured. Set NEXTSMS_USERNAME and NEXTSMS_PASSWORD in .env first.');
        return;
    }
    showSendNowConfirm.value = true;
}

async function doSendNow() {
    sendingNow.value = true;
    try {
        const response = await weeklyRemindersService.sendNow();
        toast[response.success ? 'success' : 'warning'](response.message);
        showSendNowConfirm.value = false;
        await load();
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send reminders.');
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
