<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Cards</h1>
                <p class="page-subtitle">Manage all E-Cards and their types.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-secondary" :disabled="copyingLinks" @click="copyAllLinks">
                    <Link2 class="h-4 w-4" />
                    {{ copyingLinks ? 'Copying...' : 'Copy All Links' }}
                </button>
                <button type="button" class="btn btn-secondary" :disabled="copyingWa" @click="copyAllWaMessages">
                    <Smartphone class="h-4 w-4" />
                    {{ copyingWa ? 'Copying...' : 'Copy WA Messages' }}
                </button>
                <button type="button" class="btn btn-secondary" :disabled="sendingEmails" @click="sendBulkEmails">
                    <Mail class="h-4 w-4" />
                    {{ sendingEmails ? 'Sending...' : 'Send Emails' }}
                </button>
                <button type="button" class="btn btn-secondary" :disabled="sendingSmsBulk" @click="sendBulkSms">
                    <Smartphone class="h-4 w-4" />
                    {{ sendingSmsBulk ? 'Sending...' : 'Send SMS' }}
                </button>
                <button type="button" class="btn btn-secondary" :disabled="exporting" @click="doExport">
                    <Download class="h-4 w-4" />
                    {{ exporting ? 'Downloading...' : 'Download CSV' }}
                </button>
                <button type="button" class="btn btn-secondary" @click="openImport">
                    <Upload class="h-4 w-4" />
                    Import Cards
                </button>
                <router-link to="/guests" class="btn btn-primary">
                    <Plus class="h-4 w-4" />
                    Create Card
                </router-link>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-4 sm:p-5">
                <div class="flex flex-col gap-3 lg:flex-row">
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            class="input pl-10!"
                            placeholder="Search by card number or guest name..."
                            @input="debouncedSearch"
                        />
                    </div>
                    <div class="flex gap-3">
                        <div class="relative flex-1 lg:w-44">
                            <Filter class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <select v-model="statusFilter" class="input pl-10! appearance-none" @change="load">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="used">Used</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="relative flex-1 lg:w-44">
                            <Filter class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <select v-model="typeFilter" class="input pl-10! appearance-none" @change="load">
                                <option value="">All Types</option>
                                <option value="single">Single</option>
                                <option value="double">Double</option>
                            </select>
                        </div>
                        <div class="relative flex-1 lg:w-52">
                            <Filter class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <select v-model="templateFilter" class="input pl-10! appearance-none" @change="load">
                                <option value="">All Templates</option>
                                <option v-for="template in templates" :key="template.id" :value="template.id">
                                    {{ template.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="loading" class="flex justify-center py-16">
                <span class="h-8 w-8 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
            </div>

            <div v-else-if="cards.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[950px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Number</th>
                            <th class="table-header">Guest</th>
                            <th class="table-header">Template</th>
                            <th class="table-header">Type</th>
                            <th class="table-header">Usage</th>
                            <th class="table-header">Status</th>
                            <th class="table-header text-right!">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="card in cards" :key="card.id">
                            <td class="table-cell">
                                <span class="font-mono text-sm font-bold text-slate-900">{{ card.card_number }}</span>
                            </td>
                            <td class="table-cell">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600">
                                        {{ card.guest?.full_name?.charAt(0)?.toUpperCase() }}
                                    </span>
                                    <span class="font-semibold text-slate-900">{{ card.guest?.full_name }}</span>
                                </div>
                            </td>
                            <td class="table-cell">
                                <div class="flex items-center gap-2">
                                    <div v-if="card.cardTemplate" class="relative">
                                        <img
                                            :src="card.cardTemplate.front_design"
                                            :alt="card.cardTemplate.name"
                                            class="h-12 w-16 rounded-lg object-cover ring-1 ring-slate-200"
                                        />
                                        <div class="absolute bottom-0 right-0 rounded bg-white p-px shadow-sm">
                                            <QRCodeCanvas :value="card.card_url || `${baseUrl}/e-card/${card.qr_token}`" :size="18" />
                                        </div>
                                    </div>
                                    <span v-else class="flex h-12 w-16 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
                                        <CreditCard class="h-4 w-4" />
                                    </span>
                                    <div v-if="card.cardTemplate">
                                        <p class="max-w-32 truncate text-sm font-medium text-slate-700">{{ card.cardTemplate.name }}</p>
                                        <p class="text-xs text-slate-400">{{ card.guest?.phone || '—' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="card.card_type === 'single' ? 'badge-success' : 'badge-info'">
                                    {{ cardTypeLabel(card.card_type) }}
                                </span>
                            </td>
                            <td class="table-cell">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            class="h-full rounded-full transition-all duration-300"
                                            :class="usageRatio(card) >= 100 ? 'bg-rose-500' : usageRatio(card) > 0 ? 'bg-amber-500' : 'bg-emerald-500'"
                                            :style="{ width: `${usageRatio(card)}%` }"
                                        ></div>
                                    </div>
                                    <span class="text-sm font-semibold" :class="card.used_people >= card.allowed_people ? 'text-rose-600' : 'text-slate-700'">
                                        {{ card.used_people }}/{{ card.allowed_people }}
                                    </span>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="statusBadge(card.status, card.expires_at)">
                                    {{ cardStatusLabel(card.status, card.expires_at) }}
                                </span>
                            </td>
                            <td class="table-cell">
                                <div class="flex justify-end gap-1.5">
                                    <router-link
                                        :to="`/cards/${card.id}`"
                                        class="btn btn-secondary px-3! py-1.5! text-xs"
                                    >
                                        <Eye class="h-3.5 w-3.5" />
                                        View
                                    </router-link>
                                    <button
                                        type="button"
                                        class="btn btn-secondary px-3! py-1.5! text-xs"
                                        @click="openEdit(card)"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                        Edit
                                    </button>
                                    <button
                                        v-if="card.status === 'active'"
                                        type="button"
                                        class="btn btn-warning px-3! py-1.5! text-xs"
                                        @click="askCancel(card)"
                                    >
                                        <Ban class="h-3.5 w-3.5" />
                                        Cancel
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <CreditCard class="h-7 w-7" stroke-width="1.5" />
                </span>
                <div>
                    <p class="font-semibold text-slate-700">No cards</p>
                    <p class="mt-0.5 text-sm text-slate-400">No cards match these filters.</p>
                </div>
            </div>

            <div class="px-5 py-4">
                <Pagination :pagination="pagination" @change="changePage" />
            </div>
        </div>

        <ConfirmDialog
            :open="cancelTarget !== null"
            title="Cancel Card"
            :message="`Are you sure you want to cancel card ${cancelTarget?.card_number}? The guest will no longer be able to enter with this card.`"
            confirm-label="Cancel Card"
            :loading="cancelling"
            @confirm="doCancel"
            @close="cancelTarget = null"
        />

        <Teleport to="body">
            <div
                v-if="showEdit"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
                @click.self="closeEdit"
            >
                <div class="w-full max-w-lg animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10">
                                <Pencil class="h-5 w-5" />
                            </span>
                            <div>
                                <h3 class="text-lg font-bold tracking-tight text-slate-900">Edit Card</h3>
                                <p class="font-mono text-xs text-slate-400">{{ editTarget?.card_number }}</p>
                                <p v-if="editTarget?.guest" class="mt-1 flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500">{{ editTarget.card_number }}</span>
                                    <span class="text-slate-300">&middot;</span>
                                    <span>for {{ editTarget.guest.full_name || editTarget.guest.name }}</span>
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="closeEdit"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="mt-5 space-y-4" @submit.prevent="saveEdit">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label">Card Type</label>
                                <select v-model="editForm.card_type" class="input">
                                    <option value="single">Single</option>
                                    <option value="double">Double</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Template (optional)</label>
                                <select v-model="editForm.card_template_id" class="input">
                                    <option value="">None</option>
                                    <option v-for="template in templates" :key="template.id" :value="template.id">
                                        {{ template.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="edit-event-name" class="label">Event Name</label>
                                <input id="edit-event-name" v-model="editForm.event_name" type="text" class="input" placeholder="e.g. Alice and Bob's Wedding" />
                            </div>
                            <div>
                                <label for="edit-venue" class="label">Venue</label>
                                <input id="edit-venue" v-model="editForm.venue" type="text" class="input" placeholder="e.g. Garden Plaza Hall" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="edit-event-date" class="label">Date</label>
                                <input id="edit-event-date" v-model="editForm.event_date" type="date" class="input" />
                            </div>
                            <div>
                                <label for="edit-event-time" class="label">Time</label>
                                <input id="edit-event-time" v-model="editForm.event_time" type="time" class="input" />
                            </div>
                            <div>
                                <label for="edit-table-number" class="label">Table</label>
                                <input id="edit-table-number" v-model="editForm.table_number" type="text" class="input" placeholder="e.g. A12" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="edit-seat-number" class="label">Seat</label>
                                <input id="edit-seat-number" v-model="editForm.seat_number" type="text" class="input" placeholder="e.g. 5" />
                            </div>
                            <div>
                                <label for="edit-expires-at" class="label">Expires (optional)</label>
                                <input id="edit-expires-at" v-model="editForm.expires_at" type="datetime-local" class="input" />
                            </div>
                        </div>

                        <div>
                            <label for="edit-special-message" class="label">Special Message</label>
                            <textarea id="edit-special-message" v-model="editForm.special_message" rows="2" class="input" placeholder="Message displayed on the guest's card"></textarea>
                        </div>

                        <p
                            v-if="editError"
                            class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                        >
                            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ editError }}</span>
                        </p>

                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button type="button" class="btn btn-secondary" @click="closeEdit">
                                Close
                            </button>
                            <button type="submit" class="btn btn-primary" :disabled="editing">
                                <span v-if="editing" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showImport"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
                @click.self="closeImport"
            >
                <div class="w-full max-w-lg animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10">
                                <Upload class="h-5 w-5" />
                            </span>
                            <div>
                                <h3 class="text-lg font-bold tracking-tight text-slate-900">Bulk Import Cards</h3>
                                <p class="text-xs text-slate-400">Upload a CSV file of guests and cards</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="closeImport"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <p class="mt-4 text-sm leading-relaxed text-slate-600">
                        Upload a CSV file containing guest details. The system will create a guest and one card
                        per row, including a QR code. You can provide your own card number,
                        or let the system generate one.
                    </p>

                    <button type="button" class="btn btn-secondary mt-4 w-full py-2.5! text-sm" @click="downloadTemplate">
                        <FileDown class="h-4 w-4" />
                        Download CSV Template
                    </button>

                    <div class="mt-4">
                        <label for="import-file" class="label">CSV File</label>
                        <label
                            class="group flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center transition hover:border-emerald-400 hover:bg-emerald-50/40"
                        >
                            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white text-slate-400 ring-1 ring-inset ring-slate-200 transition group-hover:text-emerald-600">
                                <FileUp class="h-5 w-5" />
                            </span>
                            <span v-if="selectedFile" class="max-w-full truncate text-sm font-semibold text-emerald-700">
                                {{ selectedFile.name }}
                            </span>
                            <span v-else class="text-sm text-slate-500">
                                Click to choose a CSV file
                            </span>
                            <span class="text-xs text-slate-400">Columns: name, phone, email (optional), type, card_number (optional), expires_at (optional)</span>
                            <input
                                id="import-file"
                                type="file"
                                accept=".csv,text/csv"
                                class="hidden"
                                @change="onFileSelected"
                            />
                        </label>
                    </div>

                    <p
                        v-if="importError"
                        class="mt-4 flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ importError }}</span>
                    </p>

                    <div
                        v-if="importResult"
                        class="mt-4 rounded-xl p-4 text-sm ring-1 ring-inset"
                        :class="importResult.data.skipped === 0 ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/10' : 'bg-amber-50 text-amber-800 ring-amber-600/10'"
                    >
                        <p class="font-bold">{{ importResult.message }}</p>
                        <ul v-if="importResult.data.errors.length" class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs">
                            <li v-for="(error, index) in importResult.data.errors" :key="index" class="text-rose-700">
                                {{ error }}
                            </li>
                        </ul>
                    </div>

                    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn btn-secondary" @click="closeImport">
                            Close
                        </button>
                        <button type="button" class="btn btn-primary" :disabled="importing || !selectedFile" @click="handleImport">
                            <span v-if="importing" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            <template v-else>
                                <Upload class="h-4 w-4" />
                            </template>
                            {{ importing ? 'Importing...' : 'Import Cards' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { AlertCircle, Ban, CreditCard, Download, Eye, FileDown, FileUp, Filter, Link2, Mail, Pencil, Plus, Search, Smartphone, Upload, X } from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import QRCodeCanvas from '../components/QRCodeCanvas.vue';
import { cardsService } from '../services/cards';
import { templatesService } from '../services/templates';
import { useToastStore } from '../stores/toast';
import { cardStatusLabel, cardTypeLabel, statusBadge } from '../utils/labels';

const toast = useToastStore();

/*
 * Only a fallback now.
 *
 * The QR codes in the list read card.card_url, which the server builds from the
 * address guests can actually open. This stays only so an older server that
 * does not send that field still draws something, because a blank square in a
 * table of cards looks like a broken page rather than an old version.
 */
const baseUrl = window.location.origin;

const cards = ref([]);
const pagination = ref(null);
const loading = ref(true);
const search = ref('');
const statusFilter = ref('');
const typeFilter = ref('');
const templateFilter = ref('');
const templates = ref([]);
const cancelTarget = ref(null);
const cancelling = ref(false);
const exporting = ref(false);
const copyingLinks = ref(false);
const copyingWa = ref(false);
const sendingEmails = ref(false);
const sendingSmsBulk = ref(false);
const showImport = ref(false);
const importing = ref(false);
const selectedFile = ref(null);
const importError = ref('');
const importResult = ref(null);
const showEdit = ref(false);
const editing = ref(false);
const editError = ref('');
const editTarget = ref(null);
const editForm = ref(emptyEditForm());
let searchTimer = null;

function emptyEditForm() {
    return {
        card_type: 'single',
        card_template_id: '',
        event_name: '',
        event_date: '',
        event_time: '',
        venue: '',
        table_number: '',
        seat_number: '',
        special_message: '',
        expires_at: '',
    };
}

function openEdit(card) {
    editTarget.value = card;
    editForm.value = {
        card_type: card.card_type,
        card_template_id: card.card_template_id ?? '',
        event_name: card.event_name || '',
        event_date: card.event_date || '',
        event_time: card.event_time || '',
        venue: card.venue || '',
        table_number: card.table_number || '',
        seat_number: card.seat_number || '',
        special_message: card.special_message || '',
        expires_at: card.expires_at ? card.expires_at.slice(0, 16) : '',
    };
    editError.value = '';
    showEdit.value = true;
}

function closeEdit() {
    if (editing.value) return;
    showEdit.value = false;
}

async function saveEdit() {
    if (!editTarget.value) return;
    editing.value = true;
    editError.value = '';
    try {
        const payload = {
            ...editForm.value,
            card_template_id: editForm.value.card_template_id || null,
            expires_at: editForm.value.expires_at || null,
        };
        await cardsService.update(editTarget.value.id, payload);
        toast.success('Card updated.');
        showEdit.value = false;
        await load();
    } catch (error) {
        const errors = error.response?.data?.errors;
        editError.value = errors ? Object.values(errors).flat()[0] : 'Failed to save the card.';
    } finally {
        editing.value = false;
    }
}

async function loadTemplates() {
    try {
        const data = await templatesService.index({ page: 1 });
        templates.value = data.data;
    } catch {
        templates.value = [];
    }
}

async function doExport() {
    exporting.value = true;
    try {
        const params = {};
        if (search.value.trim()) params.q = search.value.trim();
        if (statusFilter.value) params.status = statusFilter.value;
        if (typeFilter.value) params.card_type = typeFilter.value;
        if (templateFilter.value) params.card_template_id = templateFilter.value;
        await cardsService.export(params);
        toast.success('CSV file is being downloaded.');
    } catch {
        toast.error('Failed to download the file.');
    } finally {
        exporting.value = false;
    }
}

function usageRatio(card) {
    if (!card.allowed_people) return 0;
    return Math.round((card.used_people / card.allowed_people) * 100);
}

function openImport() {
    showImport.value = true;
    selectedFile.value = null;
    importError.value = '';
    importResult.value = null;
}

function closeImport() {
    if (importing.value) return;
    showImport.value = false;
    selectedFile.value = null;
    importError.value = '';
    importResult.value = null;
}

function onFileSelected(event) {
    const file = event.target.files?.[0];
    selectedFile.value = file || null;
    importError.value = '';
    importResult.value = null;
}

async function downloadTemplate() {
    try {
        await cardsService.importTemplate();
        toast.success('CSV template is being downloaded.');
    } catch {
        toast.error('Failed to download the template.');
    }
}

async function handleImport() {
    if (!selectedFile.value) return;

    importing.value = true;
    importError.value = '';
    importResult.value = null;

    try {
        const response = await cardsService.importCards(selectedFile.value);
        importResult.value = response;
        if (response.data.imported > 0) {
            await load();
        }
    } catch (error) {
        importError.value = error.response?.data?.message || 'Failed to import cards.';
    } finally {
        importing.value = false;
    }
}

async function load() {
    loading.value = true;
    try {
        const params = { page: pagination.value?.current_page || 1 };
        if (search.value.trim()) params.q = search.value.trim();
        if (statusFilter.value) params.status = statusFilter.value;
        if (typeFilter.value) params.card_type = typeFilter.value;
        if (templateFilter.value) params.card_template_id = templateFilter.value;
        const data = await cardsService.index(params);
        cards.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
        };
    } catch {
        toast.error('Failed to fetch cards.');
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

function askCancel(card) {
    cancelTarget.value = card;
}

async function copyAllLinks() {
    copyingLinks.value = true;
    try {
        const params = {};
        if (search.value.trim()) params.q = search.value.trim();
        if (statusFilter.value) params.status = statusFilter.value;
        if (typeFilter.value) params.card_type = typeFilter.value;
        if (templateFilter.value) params.card_template_id = templateFilter.value;

        const result = await cardsService.exportLinks(params);

        const text = result.links.map(link =>
            `${link.guest} | ${link.card_number} | ${link.card_url}`
        ).join('\n');

        await navigator.clipboard.writeText(text);
        toast.success(`Copied ${result.total} link(s) to clipboard!`);
    } catch {
        toast.error('Failed to copy links.');
    } finally {
        copyingLinks.value = false;
    }
}

async function copyAllWaMessages() {
    copyingWa.value = true;
    try {
        const params = {};
        if (search.value.trim()) params.q = search.value.trim();
        if (statusFilter.value) params.status = statusFilter.value;
        if (typeFilter.value) params.card_type = typeFilter.value;
        if (templateFilter.value) params.card_template_id = templateFilter.value;

        const result = await cardsService.exportLinks(params);

        const messages = result.links.map(link => {
            const name = link.guest;
            let msg = `Hello ${name},\n\nYou have been invited.\n\nCard: ${link.card_number}\n\nOpen your E-Card:\n${link.card_url}\n\nThank you.`;
            const phone = link.phone.replace(/\D/g, '');
            const waUrl = `https://wa.me/${phone}?text=${encodeURIComponent(msg)}`;
            return `--- ${name} (${link.phone}) ---\nWhatsApp: ${waUrl}\n\n${msg}`;
        }).join('\n\n');

        await navigator.clipboard.writeText(messages);
        toast.success(`Copied ${result.total} WhatsApp message(s)!`);
    } catch {
        toast.error('Failed to copy messages.');
    } finally {
        copyingWa.value = false;
    }
}

async function sendBulkEmails() {
    if (!cards.value.length) {
        toast.error('No cards to send emails to.');
        return;
    }

    const cardIds = cards.value.map(c => c.id);

    sendingEmails.value = true;
    try {
        const result = await cardsService.sendEmails(cardIds);
        toast.success(result.message);
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send emails.');
    } finally {
        sendingEmails.value = false;
    }
}

async function sendBulkSms() {
    if (!cards.value.length) {
        toast.error('No cards to send SMS to.');
        return;
    }

    const cardIds = cards.value.map(c => c.id);

    sendingSmsBulk.value = true;
    try {
        const result = await cardsService.sendSmsBulk(cardIds);
        toast[result.success ? 'success' : 'warning'](result.message);
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send SMS.');
    } finally {
        sendingSmsBulk.value = false;
    }
}

async function doCancel() {
    if (!cancelTarget.value) return;
    cancelling.value = true;
    try {
        await cardsService.cancel(cancelTarget.value.id);
        toast.success('Card cancelled.');
        cancelTarget.value = null;
        await load();
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to cancel the card.');
        cancelTarget.value = null;
    } finally {
        cancelling.value = false;
    }
}

onMounted(() => {
    load();
    loadTemplates();
});
</script>
