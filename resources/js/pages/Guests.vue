<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Guests</h1>
                <p class="page-subtitle">Add, edit and manage invited guests.</p>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary" :disabled="exporting" @click="doExport">
                    <Download class="h-4 w-4" />
                    {{ exporting ? 'Downloading...' : 'Download CSV' }}
                </button>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Add Guest
                </button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-4 sm:p-5">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        class="input pl-10!"
                        placeholder="Search guest by name, phone number or email..."
                        @input="debouncedSearch"
                    />
                </div>
            </div>

            <div v-if="loading" class="flex justify-center py-16">
                <span class="h-8 w-8 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
            </div>

            <div v-else-if="guests.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[780px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header w-16 text-center">Number</th>
                            <th class="table-header">Guest</th>
                            <th class="table-header">Phone Number</th>
                            <th class="table-header">Email</th>
                            <th class="table-header text-center">Cards</th>
                            <th class="table-header text-right!">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(guest, index) in guests" :key="guest.id">
                            <td class="table-cell text-center"><span class="inline-flex h-7 min-w-7 items-center justify-center rounded-lg bg-slate-100 px-2 text-xs font-bold tabular-nums text-slate-600">{{ guestRowNumber(index) }}</span></td>
                            <td class="table-cell">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-sm font-bold text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
                                        {{ guest.full_name?.charAt(0)?.toUpperCase() }}
                                    </span>
                                    <span class="font-semibold text-slate-900">{{ guest.full_name }}</span>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span class="inline-flex items-center gap-1.5">
                                    <Phone class="h-3.5 w-3.5 text-slate-400" />
                                    {{ guest.phone }}
                                </span>
                            </td>
                            <td class="table-cell">{{ guest.email || '—' }}</td>
                            <td class="table-cell text-center">
                                <span class="badge badge-success">
                                    <CreditCard class="h-3 w-3" />
                                    {{ guest.cards_count }}
                                </span>
                            </td>
                            <td class="table-cell">
                                <div class="flex justify-end gap-1.5">
                                    <router-link
                                        :to="`/guests/${guest.id}`"
                                        class="btn btn-secondary px-3! py-1.5! text-xs"
                                    >
                                        <Eye class="h-3.5 w-3.5" />
                                        View
                                    </router-link>
                                    <button
                                        type="button"
                                        class="btn btn-secondary px-3! py-1.5! text-xs"
                                        @click="openEdit(guest)"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-danger px-3! py-1.5! text-xs"
                                        @click="askDelete(guest)"
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
                    <Users class="h-7 w-7" stroke-width="1.5" />
                </span>
                <div>
                    <p class="font-semibold text-slate-700">No guests</p>
                    <p class="mt-0.5 text-sm text-slate-400">
                        Click "Add Guest" to add your first guest.
                    </p>
                </div>
            </div>

            <div class="px-5 py-4">
                <Pagination :pagination="pagination" @change="changePage" />
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
                @click.self="showModal = false"
            >
                <div class="w-full max-w-md animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10"
                            >
                                <UserPlus v-if="!editing" class="h-5 w-5" />
                                <Pencil v-else class="h-5 w-5" />
                            </span>
                            <div>
                                <h3 class="text-lg font-bold tracking-tight text-slate-900">
                                    {{ editing ? 'Edit Guest' : 'Add Guest' }}
                                </h3>
                                <p class="text-xs text-slate-400">
                                    {{ editing ? 'Change guest details.' : 'Add a new guest to the system.' }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="showModal = false"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="mt-6 space-y-4" @submit.prevent="save">
                        <div>
                            <label for="full_name" class="label">Full Name</label>
                            <input id="full_name" v-model="form.full_name" type="text" class="input" required />
                        </div>
                        <div>
                            <label for="phone" class="label">Phone Number</label>
                            <input id="phone" v-model="form.phone" type="tel" class="input" placeholder="2557xxxxxxxxx" required />
                            <p class="mt-1.5 text-xs text-slate-400">Use the international format (e.g. 2557xxxxxxxxx) for WhatsApp.</p>
                        </div>
                        <div>
                            <label for="email" class="label">Email</label>
                            <input id="email" v-model="form.email" type="email" class="input" />
                        </div>
                        <div>
                            <label for="notes" class="label">Notes</label>
                            <textarea id="notes" v-model="form.notes" rows="3" class="input resize-none"></textarea>
                        </div>

                        <p
                            v-if="formError"
                            class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                        >
                            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ formError }}</span>
                        </p>

                        <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                            <button type="button" class="btn btn-secondary" @click="showModal = false">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                <span v-if="saving" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                <template v-else>
                                    <Check v-if="editing" class="h-4 w-4" />
                                    <UserPlus v-else class="h-4 w-4" />
                                </template>
                                {{ editing ? 'Update' : 'Add' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <ConfirmDialog
            :open="deleteTarget !== null"
            title="Delete Guest"
            :message="`Are you sure you want to delete ${deleteTarget?.full_name}? This action cannot be undone.`"
            confirm-label="Delete"
            @confirm="doDelete"
            @close="deleteTarget = null"
        />
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { AlertCircle, Check, CreditCard, Download, Eye, Pencil, Phone, Plus, Search, Trash2, UserPlus, Users, X } from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import { guestsService } from '../services/guests';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const guests = ref([]);
const pagination = ref(null);
const loading = ref(true);
const search = ref('');

const showModal = ref(false);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const form = reactive({ full_name: '', phone: '', email: '', notes: '' });

const deleteTarget = ref(null);
const exporting = ref(false);
let searchTimer = null;

function guestRowNumber(index) {
    return ((pagination.value?.current_page ?? 1) - 1) * 15 + index + 1;
}

async function doExport() {
    exporting.value = true;
    try {
        const params = {};
        if (search.value.trim()) params.q = search.value.trim();
        await guestsService.export(params);
        toast.success('CSV file is being downloaded.');
    } catch {
        toast.error('Failed to download the file.');
    } finally {
        exporting.value = false;
    }
}

async function load() {
    loading.value = true;
    try {
        const params = {};
        if (search.value.trim()) params.q = search.value.trim();
        const data = await guestsService.index(params);
        guests.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
        };
    } catch {
        toast.error('Failed to fetch guests.');
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    loadWithPage(page);
}

async function loadWithPage(page) {
    loading.value = true;
    try {
        const params = { page };
        if (search.value.trim()) params.q = search.value.trim();
        const data = await guestsService.index(params);
        guests.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
        };
    } finally {
        loading.value = false;
    }
}

function debouncedSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 400);
}

function openCreate() {
    editing.value = null;
    formError.value = '';
    Object.assign(form, { full_name: '', phone: '', email: '', notes: '' });
    showModal.value = true;
}

function openEdit(guest) {
    editing.value = guest;
    formError.value = '';
    Object.assign(form, {
        full_name: guest.full_name,
        phone: guest.phone,
        email: guest.email || '',
        notes: guest.notes || '',
    });
    showModal.value = true;
}

async function save() {
    saving.value = true;
    formError.value = '';
    try {
        if (editing.value) {
            await guestsService.update(editing.value.id, { ...form });
            toast.success('Guest updated.');
        } else {
            await guestsService.store({ ...form });
            toast.success('Guest added.');
        }
        showModal.value = false;
        await load();
    } catch (error) {
        const errors = error.response?.data?.errors;
        if (errors) {
            formError.value = Object.values(errors).flat()[0];
        } else {
            formError.value = 'Failed. Try again.';
        }
    } finally {
        saving.value = false;
    }
}

function askDelete(guest) {
    deleteTarget.value = guest;
}

async function doDelete() {
    if (!deleteTarget.value) return;
    try {
        await guestsService.destroy(deleteTarget.value.id);
        toast.success('Guest deleted.');
        deleteTarget.value = null;
        await load();
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to delete the guest.');
        deleteTarget.value = null;
    }
}

onMounted(load);
</script>
