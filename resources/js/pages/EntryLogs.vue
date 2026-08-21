<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Entry Logs</h1>
                <p class="page-subtitle">Every entry attempt, whether successful or denied, is recorded here.</p>
            </div>
            <button type="button" class="btn btn-secondary whitespace-nowrap" :disabled="exporting" @click="doExport">
                <Download class="h-4 w-4" />
                {{ exporting ? 'Downloading...' : 'Download CSV' }}
            </button>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row">
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            class="input pl-10!"
                            placeholder="Search by guest name..."
                            @input="debouncedSearch"
                        />
                    </div>
                    <div class="relative sm:w-60">
                        <Filter class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <select v-model="resultFilter" class="input pl-10! appearance-none" @change="load">
                            <option value="">All results</option>
                            <option value="allowed">Allowed</option>
                            <option value="denied">Denied</option>
                            <option value="already_used">Card already used</option>
                            <option value="cancelled">Card cancelled</option>
                            <option value="expired">Card expired</option>
                            <option value="invalid">Invalid card</option>
                        </select>
                    </div>
                </div>
            </div>

            <div v-if="loading" class="flex justify-center py-16">
                <span class="h-8 w-8 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
            </div>

            <div v-else-if="logs.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[920px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Guest</th>
                            <th class="table-header">Card</th>
                            <th class="table-header text-center">Attempt</th>
                            <th class="table-header">Time</th>
                            <th class="table-header">Gate</th>
                            <th class="table-header">Method</th>
                            <th class="table-header text-center">Count</th>
                            <th class="table-header">Attendant</th>
                            <th class="table-header text-right!">Result</th>
                            <th class="table-header text-right!">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="log in logs" :key="log.id">
                            <td class="table-cell">
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                        :class="log.result === 'allowed' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'"
                                    >
                                        <UserCheck v-if="log.result === 'allowed'" class="h-4 w-4" />
                                        <UserX v-else class="h-4 w-4" />
                                    </span>
                                    <span class="font-semibold text-slate-900">{{ log.guest?.full_name || 'Unknown' }}</span>
                                </div>
                            </td>
                            <td class="table-cell font-mono text-sm font-semibold text-slate-900">{{ log.card?.card_number || '-' }}</td>
                            <td class="table-cell text-center">
                                <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-slate-100 px-1.5 text-xs font-bold text-slate-600">
                                    #{{ log.entry_number }}
                                </span>
                            </td>
                            <td class="table-cell whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5 text-slate-600">
                                    <Clock class="h-3.5 w-3.5 text-slate-400" />
                                    {{ formatDate(log.scanned_at) }}
                                </span>
                            </td>
                            <td class="table-cell">{{ log.gate_name || '-' }}</td>
                            <td class="table-cell">
                                <span class="inline-flex items-center gap-1.5 text-slate-600">
                                    <ScanLine v-if="log.verification_method?.value === 'qr' || log.verification_method === 'qr'" class="h-3.5 w-3.5 text-slate-400" />
                                    <Keyboard v-else-if="log.verification_method?.value || log.verification_method" class="h-3.5 w-3.5 text-slate-400" />
                                    <span>{{ verificationMethodLabel(log.verification_method?.value || log.verification_method) }}</span>
                                </span>
                            </td>
                            <td class="table-cell text-center">
                                <span v-if="log.number_of_guests" class="text-sm font-semibold text-slate-900">{{ log.number_of_guests }}</span>
                                <span v-else class="text-slate-400">-</span>
                            </td>
                            <td class="table-cell">{{ log.processor?.name || '-' }}</td>
                            <td class="table-cell">
                                <div class="flex justify-end">
                                    <span class="badge" :class="resultBadge(log.result)">
                                        {{ entryResultLabel(log.result) }}
                                    </span>
                                </div>
                            </td>
                            <td class="table-cell">
                                <div class="flex justify-end">
                                    <button
                                        type="button"
                                        class="btn btn-danger px-3! py-1.5! text-xs"
                                        :disabled="deleting"
                                        @click="askDelete(log)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <ClipboardList class="h-7 w-7" stroke-width="1.5" />
                </span>
                <div>
                    <p class="font-semibold text-slate-700">No records</p>
                    <p class="mt-0.5 text-sm text-slate-400">No entry records match these filters.</p>
                </div>
            </div>

            <div class="px-5 py-4">
                <Pagination :pagination="pagination" @change="changePage" />
            </div>
        </div>

        <ConfirmDialog
            :open="deleteTarget !== null"
            title="Delete Entry Log?"
            :message="`Are you sure you want to delete the entry log for ${deleteTarget?.guest?.full_name || 'Unknown'} (${deleteTarget?.card?.card_number || '-'})?`"
            confirm-label="Delete"
            :loading="deleting"
            @confirm="doDelete"
            @close="deleteTarget = null"
        />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { ClipboardList, Clock, Download, Filter, Keyboard, ScanLine, Search, Trash2, UserCheck, UserX } from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import { entryLogsService } from '../services/entry-logs';
import { useToastStore } from '../stores/toast';
import { entryResultLabel, verificationMethodLabel } from '../utils/labels';

const toast = useToastStore();

const logs = ref([]);
const pagination = ref(null);
const loading = ref(true);
const search = ref('');
const resultFilter = ref('');
const exporting = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);
let searchTimer = null;

async function doExport() {
    exporting.value = true;
    try {
        const params = {};
        if (search.value.trim()) params.q = search.value.trim();
        if (resultFilter.value) params.result = resultFilter.value;
        await entryLogsService.export(params);
        toast.success('The CSV file is being downloaded.');
    } catch {
        toast.error('Failed to download the file.');
    } finally {
        exporting.value = false;
    }
}

const resultClasses = {
    allowed: 'badge-success',
    denied: 'badge-danger',
    already_used: 'badge-danger',
    cancelled: 'badge-danger',
    expired: 'badge-warning',
    invalid: 'badge-neutral',
};

function resultBadge(result) {
    return resultClasses[result] || 'badge-neutral';
}

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('en-US');
}

async function load() {
    loading.value = true;
    try {
        const params = { page: pagination.value?.current_page || 1 };
        if (search.value.trim()) params.q = search.value.trim();
        if (resultFilter.value) params.result = resultFilter.value;
        const data = await entryLogsService.index(params);
        logs.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
        };
    } catch {
        toast.error('Failed to load records.');
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (pagination.value) pagination.value.current_page = page;
    load();
}

function debouncedSearch() {
    clearTimeout(searchTimer);
    if (pagination.value) pagination.value.current_page = 1;
    searchTimer = setTimeout(load, 400);
}

function askDelete(log) {
    deleteTarget.value = log;
}

async function doDelete() {
    if (!deleteTarget.value) return;
    deleting.value = true;
    try {
        await entryLogsService.remove(deleteTarget.value.id);
        toast.success('Entry log deleted.');
        deleteTarget.value = null;
        await load();
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to delete the log.');
    } finally {
        deleting.value = false;
    }
}

onMounted(load);
</script>
