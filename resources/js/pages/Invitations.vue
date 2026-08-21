<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Invitations</h1>
                <p class="page-subtitle">
                    Send invitation SMS with direct E-Card links to all guests.
                </p>
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

        <div class="card">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="card-title">Send SMS Invitations</h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Send SMS to all guests with a direct link to their E-Card.
                    </p>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="sendingSms || (preview?.guests ?? 0) === 0 || !report?.configured?.sms"
                        @click="showSmsConfirm = true"
                    >
                        <span v-if="sendingSms" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                        <template v-else>
                            <Smartphone class="h-4 w-4" />
                        </template>
                        Send SMS to All Guests
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-amber-400 px-4 py-2.5 text-sm font-bold text-slate-900 shadow-sm transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="sending || (preview?.guests ?? 0) === 0"
                        @click="showSendConfirm = true"
                    >
                        <span v-if="sending" class="h-4 w-4 animate-spin rounded-full border-2 border-emerald-900/30 border-t-emerald-900"></span>
                        <template v-else>
                            <Send class="h-4 w-4" />
                        </template>
                        Send All (SMS + WhatsApp)
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="card-title">Schedule SMS</h2>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Pick a future date and time. SMS invitations will be sent automatically at that time.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        v-model="scheduledDate"
                        type="datetime-local"
                        class="input"
                        aria-label="Schedule date and time"
                    />
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="schedulingSms || !scheduledDate || (preview?.guests ?? 0) === 0 || !report?.configured?.sms"
                        @click="showScheduleConfirm = true"
                    >
                        <span v-if="schedulingSms" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                        <template v-else>
                            <Clock class="h-4 w-4" />
                        </template>
                        Schedule SMS
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-6 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
                <p class="max-w-xl text-sm leading-relaxed text-slate-500">
                    Every guest with a valid phone number will receive an SMS with their
                    personal E-Card link. When they tap the link, the card opens directly
                    showing all their invitation details.
                </p>

                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm font-semibold">
                    <span class="inline-flex items-center gap-2">
                        <Users class="h-4 w-4 text-emerald-500" />
                        Guests: {{ preview?.guests ?? '...' }}
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <Smartphone class="h-4 w-4 text-emerald-500" />
                        SMS Ready: {{ preview?.sms_messages ?? '...' }}
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <MessageSquare class="h-4 w-4 text-emerald-500" />
                        WhatsApp: {{ preview?.whatsapp_messages ?? '...' }}
                    </span>
                </div>
            </div>

            <div class="border-t border-slate-100 px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">SMS Preview</p>
                <div class="rounded-xl bg-slate-50 p-4 text-sm leading-relaxed text-slate-600 ring-1 ring-inset ring-slate-200">
                    <p>Hello <strong>[Guest Name]</strong>.</p>
                    <p>You're invited to <strong>[Event Name]</strong>!</p>
                    <p>Date: [Date], Time: [Time], Venue: [Venue].</p>
                    <p>Card: [Card Number]</p>
                    <p class="mt-2 text-emerald-600 font-semibold">View your E-Card (tap to open):</p>
                    <p class="text-emerald-600 underline">http://127.0.0.1:8000/e-card/[qr_token]</p>
                    <p class="mt-2">Present this at the gate. Thank you!</p>
                </div>
            </div>
        </div>

        <div
            v-if="report && !report.configured.sms"
            class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-600/20"
        >
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div class="text-sm text-amber-900">
                <p class="font-semibold">SMS is not configured.</p>
                <p class="mt-0.5 text-amber-700">
                    Set <code>NEXTSMS_USERNAME</code> and <code>NEXTSMS_PASSWORD</code> in your <code>.env</code> file,
                    then run <code>php artisan config:clear</code>.
                </p>
            </div>
        </div>

        <div v-if="loading && !report" class="flex justify-center py-20">
            <span class="h-8 w-8 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <template v-else-if="report">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                <StatCard
                    label="SMS Sent"
                    :value="report.sms.sent"
                    :hint="smsHint"
                    :icon="Smartphone"
                    tone="indigo"
                />
                <StatCard
                    label="WhatsApp Sent"
                    :value="report.whatsapp.sent"
                    :hint="whatsAppHint"
                    :icon="MessageSquare"
                    tone="sky"
                />
                <StatCard
                    label="Failed"
                    :value="report.total_failed"
                    hint="Messages that failed to send"
                    :icon="AlertTriangle"
                    tone="danger"
                />
            </div>

            <div class="card">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="card-title">Send History</h2>
                        <p class="mt-0.5 text-xs text-slate-400">Status of each SMS and WhatsApp message per guest.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn btn-secondary px-3! py-1.5! text-xs"
                            :disabled="retrying"
                            @click="doRetry('sms')"
                        >
                            <RotateCcw v-if="retrying !== 'sms'" class="h-3.5 w-3.5 text-emerald-600" />
                            <span v-else class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-emerald-500/30 border-t-emerald-600"></span>
                            Retry SMS
                        </button>
                        <button
                            type="button"
                            class="btn btn-secondary px-3! py-1.5! text-xs"
                            :disabled="retrying"
                            @click="doRetry('whatsapp')"
                        >
                            <RotateCcw v-if="retrying !== 'whatsapp'" class="h-3.5 w-3.5 text-emerald-600" />
                            <span v-else class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-emerald-500/30 border-t-emerald-600"></span>
                            Retry WhatsApp
                        </button>
                        <button
                            type="button"
                            class="btn btn-secondary px-3! py-1.5! text-xs"
                            :disabled="retrying"
                            @click="doRetry()"
                        >
                            <RotateCcw v-if="retrying !== 'all'" class="h-3.5 w-3.5 text-emerald-600" />
                            <span v-else class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-emerald-500/30 border-t-emerald-600"></span>
                            Retry All
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                    <div class="relative flex-1 sm:max-w-xs">
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search guest or card number..."
                            class="input pl-9!"
                            @input="debouncedSearch"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <select v-model="channelFilter" class="input" @change="reloadSends">
                            <option value="">All Channels</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="sms">SMS</option>
                        </select>
                        <select v-model="statusFilter" class="input" @change="reloadSends">
                            <option value="">All Statuses</option>
                            <option value="queued">Queued</option>
                            <option value="sent">Sent</option>
                            <option value="delivered">Delivered</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                </div>

                <div v-if="sendsLoading" class="flex justify-center py-12">
                    <span class="h-6 w-6 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
                </div>

                <div v-else-if="sends.data?.length" class="overflow-x-auto">
                    <table class="data-table w-full min-w-[850px] text-left">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="table-header">Guest</th>
                                <th class="table-header">Card Number</th>
                                <th class="table-header">Channel</th>
                                <th class="table-header">Phone</th>
                                <th class="table-header">Status</th>
                                <th class="table-header">Sent</th>
                                <th class="table-header">Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="send in sends.data" :key="send.id">
                                <td class="table-cell">
                                    <p class="text-sm font-semibold text-slate-900">{{ send.card?.guest?.full_name || '—' }}</p>
                                </td>
                                <td class="table-cell">
                                    <span class="font-mono text-sm font-bold text-slate-900">{{ send.card?.card_number || '—' }}</span>
                                </td>
                                <td class="table-cell">
                                    <span class="badge" :class="send.channel === 'whatsapp' ? 'badge-success' : 'badge-info'">
                                        {{ channelLabel(send.channel) }}
                                    </span>
                                </td>
                                <td class="table-cell font-mono text-sm text-slate-600">
                                    {{ send.phone_e164 ? '+' + send.phone_e164 : '—' }}
                                </td>
                                <td class="table-cell">
                                    <span class="badge" :class="statusBadge(send.status)">
                                        {{ statusLabel(send.status) }}
                                    </span>
                                </td>
                                <td class="table-cell text-sm text-slate-500">
                                    {{ formatTime(send.sent_at) }}
                                </td>
                                <td class="table-cell">
                                    <p v-if="send.error" class="max-w-56 truncate text-xs text-rose-600" :title="send.error">
                                        {{ send.error }}
                                    </p>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                        <Send class="h-7 w-7" stroke-width="1.5" />
                    </span>
                    <div>
                        <p class="font-semibold text-slate-700">No messages sent yet</p>
                        <p class="mt-0.5 text-sm text-slate-400">
                            Click "Send SMS to All Guests" to start.
                        </p>
                    </div>
                </div>

                <div class="px-5 py-4">
                    <Pagination :pagination="pagination" @change="changePage" />
                </div>
            </div>
        </template>

        <ConfirmDialog
            :open="showSmsConfirm"
            title="Send SMS to All Guests?"
            :message="`This will send an SMS with a direct E-Card link to ${preview?.guests ?? 0} guests via NextSMS. Each SMS includes the guest's card details and a link they can tap to open their card.`"
            confirm-label="Send SMS Now"
            :loading="sendingSms"
            @confirm="doSendSmsOnly"
            @close="showSmsConfirm = false"
        />

        <ConfirmDialog
            :open="showSendConfirm"
            title="Send All Invitations (SMS + WhatsApp)?"
            :message="`This will send both WhatsApp AND SMS to ${preview?.guests ?? 0} guests. Expected messages: ${preview?.total_messages ?? 0}.`"
            confirm-label="Send All"
            :loading="sending"
            @confirm="doSendAll"
            @close="showSendConfirm = false"
        />

        <ConfirmDialog
            :open="showScheduleConfirm"
            title="Schedule SMS Invitations?"
            :message="`This will queue SMS invitations for ${preview?.guests ?? 0} guests. They will be sent automatically on ${scheduledDateLabel || scheduledDate}.`"
            confirm-label="Schedule SMS"
            :loading="schedulingSms"
            @confirm="doScheduleSms"
            @close="showScheduleConfirm = false"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    AlertTriangle,
    Clock,
    MessageSquare,
    RefreshCw,
    RotateCcw,
    Search,
    Send,
    Smartphone,
    Users,
} from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import StatCard from '../components/StatCard.vue';
import { invitationsService } from '../services/invitations';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const report = ref(null);
const preview = ref(null);
const loading = ref(true);
const sending = ref(false);
const sendingSms = ref(false);
const retrying = ref(null);
const showSendConfirm = ref(false);
const showSmsConfirm = ref(false);
const scheduledDate = ref('');
const showScheduleConfirm = ref(false);
const schedulingSms = ref(false);

const sends = ref({ data: [] });
const sendsLoading = ref(false);
const pagination = ref(null);
const search = ref('');
const channelFilter = ref('');
const statusFilter = ref('');
let searchTimer = null;

const whatsAppHint = computed(() => {
    if (!report.value) return '';
    const { sent, delivered, failed, pending } = report.value.whatsapp;
    return `Sent: ${sent} · Failed: ${failed} · Pending: ${pending}`;
});

const smsHint = computed(() => {
    if (!report.value) return '';
    const { sent, delivered, failed, pending } = report.value.sms;
    return `Sent: ${sent} · Failed: ${failed} · Pending: ${pending}`;
});

const scheduledDateLabel = computed(() => {
    if (!scheduledDate.value) return '';
    return new Date(scheduledDate.value).toLocaleString('sw-TZ', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

function channelLabel(channel) {
    return channel === 'whatsapp' ? 'WhatsApp' : 'SMS';
}

function statusLabel(status) {
    return {
        queued: 'Queued',
        sent: 'Sent',
        delivered: 'Delivered',
        failed: 'Failed',
    }[status] || status;
}

function statusBadge(status) {
    return {
        queued: 'badge-neutral',
        sent: 'badge-info',
        delivered: 'badge-success',
        failed: 'badge-danger',
    }[status] || 'badge-neutral';
}

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
        const [reportData, previewData] = await Promise.all([
            invitationsService.report(),
            invitationsService.preview(),
        ]);
        report.value = reportData.data;
        preview.value = previewData.data;
    } catch {
        toast.error('Failed to fetch the invitations report.');
    } finally {
        loading.value = false;
    }
}

async function doSendSmsOnly() {
    sendingSms.value = true;
    try {
        const response = await invitationsService.sendSmsOnly();
        toast[response.success ? 'success' : 'warning'](response.message);
        showSmsConfirm.value = false;
        await Promise.all([load(), reloadSends()]);
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send SMS invitations.');
    } finally {
        sendingSms.value = false;
    }
}

async function doScheduleSms() {
    schedulingSms.value = true;
    try {
        const response = await invitationsService.sendSmsScheduled(scheduledDate.value);
        toast[response.success ? 'success' : 'warning'](response.message);
        showScheduleConfirm.value = false;
        await Promise.all([load(), reloadSends()]);
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to schedule SMS invitations.');
    } finally {
        schedulingSms.value = false;
    }
}

async function doSendAll() {
    sending.value = true;
    try {
        const response = await invitationsService.sendAll();
        toast[response.success ? 'success' : 'warning'](response.message);
        showSendConfirm.value = false;
        await Promise.all([load(), reloadSends()]);
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to schedule invitations.');
    } finally {
        sending.value = false;
    }
}

async function doRetry(channel = null) {
    retrying.value = channel || 'all';
    try {
        const response = await invitationsService.retryFailed(channel);
        toast[response.success ? 'success' : 'warning'](response.message);
        await Promise.all([load(), reloadSends()]);
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to retry.');
    } finally {
        retrying.value = null;
    }
}

async function reloadSends() {
    sendsLoading.value = true;
    try {
        const params = { page: pagination.value?.current_page || 1 };
        if (search.value.trim()) params.q = search.value.trim();
        if (channelFilter.value) params.channel = channelFilter.value;
        if (statusFilter.value) params.status = statusFilter.value;
        const data = await invitationsService.sends(params);
        sends.value = data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
        };
    } catch {
        toast.error('Failed to fetch send history.');
    } finally {
        sendsLoading.value = false;
    }
}

function changePage(page) {
    if (pagination.value) pagination.value.current_page = page;
    reloadSends();
}

function debouncedSearch() {
    clearTimeout(searchTimer);
    if (pagination.value) pagination.value.current_page = 1;
    searchTimer = setTimeout(reloadSends, 400);
}

onMounted(() => {
    load();
    reloadSends();
});

onUnmounted(() => {
    clearTimeout(searchTimer);
});
</script>
