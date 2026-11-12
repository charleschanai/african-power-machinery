<template>
    <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Invitation &amp; SMS Delivery Report</h2>
                <p class="mt-1 text-sm text-slate-500">Preview and print a filtered, multi-page PDF report.</p>
            </div>
            <div class="flex gap-2">
                <button class="btn btn-secondary text-sm" :disabled="loading" @click="load">{{ loading ? 'Loading…' : 'VIEW REPORT' }}</button>
            </div>
        </div>
        <div class="grid gap-3 border-b border-slate-100 p-5 sm:grid-cols-2 lg:grid-cols-5">
            <label class="text-xs font-semibold text-slate-600">Event<select v-model="filters.event" class="input mt-1"><option value="">All events</option><option v-for="event in report?.events ?? []" :key="event" :value="event">{{ event }}</option></select></label>
            <label class="text-xs font-semibold text-slate-600">Invitation Campaign<select v-model="filters.campaign" class="input mt-1"><option value="all">Invitation SMS</option><option value="reminder">Reminder SMS</option></select></label>
            <label class="text-xs font-semibold text-slate-600">From<input v-model="filters.from" type="date" class="input mt-1"></label>
            <label class="text-xs font-semibold text-slate-600">To<input v-model="filters.to" type="date" class="input mt-1"></label>
            <label class="text-xs font-semibold text-slate-600">SMS Result<select v-model="filters.status" class="input mt-1"><option value="all">All guests</option><option value="delivered">Successfully delivered</option><option value="failed">Failed SMS</option><option value="pending">Pending SMS</option></select></label>
        </div>
        <div class="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-3">
            <button class="btn btn-secondary text-xs" :disabled="downloading" @click="downloadPdf('all')">Complete Report PDF</button>
            <button class="btn btn-secondary text-xs" :disabled="downloading" @click="downloadPdf('delivered')">Delivered Guests PDF</button>
            <button class="btn btn-secondary text-xs" :disabled="downloading" @click="downloadPdf('failed')">Failed SMS PDF</button>
            <button class="btn btn-secondary text-xs" :disabled="downloading" @click="downloadPdf('pending')">Pending SMS PDF</button>
        </div>
        <p v-if="error" class="p-5 text-sm text-rose-700">{{ error }}</p>
        <div v-if="report" class="overflow-x-auto p-5">
            <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
                <div v-for="card in summaryCards" :key="card.label" class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">{{ card.label }}</p><p class="mt-1 text-xl font-bold text-slate-900">{{ card.value }}</p></div>
            </div>
            <p class="mt-4 text-xs text-slate-500">{{ report.rows.length }} records - Generated {{ report.generated_at }} - Reference {{ report.campaign_reference }}</p>
            <table class="mt-4 min-w-full text-left text-sm">
                <thead class="bg-slate-100 text-xs uppercase text-slate-600"><tr><th v-for="heading in headings" :key="heading" class="px-3 py-2">{{ heading }}</th></tr></thead>
                <tbody><tr v-for="(row, index) in report.rows" :key="row.id" class="border-b border-slate-100"><td class="px-3 py-2">{{ index + 1 }}</td><td class="px-3 py-2">{{ row.guest_name }}</td><td class="px-3 py-2">{{ row.phone }}</td><td class="px-3 py-2">{{ row.card_number }}</td><td class="px-3 py-2 capitalize">{{ row.card_type }}</td><td class="px-3 py-2 capitalize">{{ row.sms_status }}</td><td class="px-3 py-2" :class="overallClass(row.sms_status)">{{ overall(row.sms_status) }}</td></tr></tbody>
            </table>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { invitationsService } from '../services/invitations';

const filters = reactive({ event: '', campaign: 'all', from: '', to: '', status: 'all' });
const report = ref(null);
const loading = ref(false);
const downloading = ref(false);
const error = ref('');
const headings = ['No.', 'Guest Name', 'Phone Number', 'Card Number', 'Card Type', 'SMS Status', 'Overall Status'];
const summaryCards = computed(() => report.value ? [
    { label: 'Total Registered Guests', value: report.value.summary.guests },
    { label: 'Invitation Cards Assigned', value: report.value.summary.cards },
    { label: 'SMS Messages Sent', value: report.value.summary.sent },
    { label: 'SMS Delivered', value: report.value.summary.delivered },
    { label: 'SMS Failed', value: report.value.summary.failed },
    { label: 'SMS Pending', value: report.value.summary.pending },
] : []);

async function load() {
    loading.value = true;
    error.value = '';
    try { report.value = await invitationsService.report(filters); }
    catch (e) { error.value = e?.response?.data?.message || 'Could not load the report.'; }
    finally { loading.value = false; }
}
watch(filters, load);
onMounted(load);

function overall(status) { return status === 'delivered' ? 'Successful' : status === 'failed' ? 'Failed' : 'Pending'; }
function overallClass(status) { return status === 'delivered' ? 'font-semibold text-emerald-700' : status === 'failed' ? 'font-semibold text-rose-700' : 'font-semibold text-amber-700'; }
function download() { return downloadPdf('all'); }
defineExpose({ download });
async function downloadPdf(kind) {
    downloading.value = true;
    error.value = '';
    try {
        await invitationsService.downloadReport(filters, kind);
    } catch (e) {
        error.value = e?.response?.data?.message || 'The PDF could not be downloaded. Please try again.';
    } finally {
        downloading.value = false;
    }
}
</script>
