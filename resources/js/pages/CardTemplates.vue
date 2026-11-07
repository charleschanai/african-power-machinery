<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-slate-900">Card Templates</h1>
                <p class="text-sm text-slate-500">Upload your card design and assign it to guests.</p>
            </div>
            <button type="button" class="btn btn-primary justify-center" @click="openCreate">
                <Plus class="h-4 w-4" />
                Add Template
            </button>
        </div>

        <div class="card p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search templates..."
                        class="input pl-9!"
                        @input="debouncedSearch"
                    />
                </div>
                <div class="flex flex-wrap gap-3">
                    <select v-model="typeFilter" class="input w-auto" @change="load">
                        <option value="">All Types</option>
                        <option value="single">Single</option>
                        <option value="double">Double</option>
                    </select>
                    <select v-model="statusFilter" class="input w-auto" @change="load">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <div v-else-if="templates.length" class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table w-full min-w-[900px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Design</th>
                            <th class="table-header">Name</th>
                            <th class="table-header">Type</th>
                            <th class="table-header">Cards</th>
                            <th class="table-header">Status</th>
                            <th class="table-header">Added</th>
                            <th class="table-header text-right!">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="template in templates" :key="template.id" class="hover:bg-slate-50/60">
                            <td class="table-cell">
                                <div class="flex gap-2">
                                    <img
                                        :src="template.front_design"
                                        :alt="template.name"
                                        class="h-14 w-20 rounded-lg object-cover ring-1 ring-slate-200"
                                    />
                                    <img
                                        v-if="template.back_design"
                                        :src="template.back_design"
                                        :alt="`${template.name} back`"
                                        class="h-14 w-20 rounded-lg object-cover ring-1 ring-slate-200"
                                    />
                                </div>
                            </td>
                            <td class="table-cell font-semibold text-slate-900">{{ template.name }}</td>
                            <td class="table-cell">
                                <span class="badge" :class="template.card_type === 'single' ? 'badge-success' : 'badge-info'">
                                    {{ template.card_type_label }}
                                </span>
                            </td>
                            <td class="table-cell">{{ template.cards_count }}</td>
                            <td class="table-cell">
                                <span class="badge" :class="template.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ template.status_label }}
                                </span>
                            </td>
                            <td class="table-cell text-slate-500">{{ formatDate(template.created_at) }}</td>
                            <td class="table-cell">
                                <div class="flex justify-end gap-1.5">
                                    <button type="button" class="btn btn-secondary px-3! py-1.5! text-xs" @click="openEdit(template)">
                                        <Pencil class="h-3.5 w-3.5" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="btn px-3! py-1.5! text-xs"
                                        :class="template.status === 'active' ? 'btn-warning' : 'btn-secondary'"
                                        @click="toggleStatus(template)"
                                    >
                                        {{ template.status === 'active' ? 'Turn off' : 'Turn on' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-danger px-3! py-1.5! text-xs"
                                        :disabled="template.cards_count > 0"
                                        :title="template.cards_count > 0 ? 'Cannot be deleted: it is used by cards.' : ''"
                                        @click="askDelete(template)"
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

            <div class="border-t border-slate-100 p-4">
                <Pagination :pagination="pagination" @change="changePage" />
            </div>
        </div>

        <div v-else class="card flex flex-col items-center justify-center gap-3 py-20 text-center">
            <ImagePlus class="h-12 w-12 text-slate-300" stroke-width="1.5" />
            <p class="text-sm font-semibold text-slate-600">No templates yet.</p>
            <p class="max-w-sm text-xs text-slate-400">
                Upload your card design (Front, and Back for DOUBLE) then assign it to guests.
            </p>
            <button type="button" class="btn btn-primary mt-2" @click="openCreate">
                <Plus class="h-4 w-4" />
                Add Template
            </button>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" @click="closeModal"></div>
                <div class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title">{{ editing ? 'Edit Template' : 'Add Template' }}</h2>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100" @click="closeModal">
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="mt-5 space-y-4" @submit.prevent="submit">
                        <div>
                            <label for="template-name" class="label">Template Name</label>
                            <input id="template-name" v-model="form.name" type="text" class="input" placeholder="e.g. Wedding Gold Design" />
                        </div>

                        <div>
                            <label class="label">Card Type</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    class="rounded-xl border-2 p-4 text-left transition"
                                    :class="form.card_type === 'single' ? 'border-emerald-500 bg-emerald-50/60' : 'border-slate-200 hover:border-slate-300'"
                                    @click="form.card_type = 'single'"
                                >
                                    <p class="text-sm font-bold text-slate-900">Single (SINGLE)</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Front only</p>
                                </button>
                                <button
                                    type="button"
                                    class="rounded-xl border-2 p-4 text-left transition"
                                    :class="form.card_type === 'double' ? 'border-emerald-500 bg-emerald-50/60' : 'border-slate-200 hover:border-slate-300'"
                                    @click="form.card_type = 'double'"
                                >
                                    <p class="text-sm font-bold text-slate-900">Double (DOUBLE)</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Front + Back</p>
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label">Front Design *</label>
                                <label
                                    class="group flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center transition hover:border-emerald-400 hover:bg-emerald-50/40"
                                >
                                    <ImagePlus class="h-6 w-6 text-slate-400 transition group-hover:text-emerald-600" />
                                    <span v-if="frontPreview" class="max-w-full truncate text-xs font-semibold text-emerald-700">
                                        {{ frontPreview }}
                                    </span>
                                    <span v-else-if="form.front_design && typeof form.front_design === 'string'" class="max-w-full truncate text-xs font-semibold text-slate-600">
                                        {{ form.front_design }}
                                    </span>
                                    <span v-else class="text-xs text-slate-500">PNG / JPG / JPEG (max 5MB)</span>
                                    <input type="file" accept="image/png,image/jpeg,image/jpg" class="hidden" @change="onFrontSelected" />
                                </label>
                                <img v-if="form.front_design && typeof form.front_design === 'string'" :src="form.front_design" class="mt-2 h-24 w-full rounded-lg object-cover ring-1 ring-slate-200" />
                            </div>

                            <div>
                                <label class="label">
                                    Back Design
                                    <span v-if="form.card_type === 'double'" class="text-rose-500"> *</span>
                                </label>
                                <label
                                    class="group flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center transition hover:border-emerald-400 hover:bg-emerald-50/40"
                                >
                                    <Layers class="h-6 w-6 text-slate-400 transition group-hover:text-emerald-600" />
                                    <span v-if="backPreview" class="max-w-full truncate text-xs font-semibold text-emerald-700">
                                        {{ backPreview }}
                                    </span>
                                    <span v-else-if="form.back_design && typeof form.back_design === 'string'" class="max-w-full truncate text-xs font-semibold text-slate-600">
                                        {{ form.back_design }}
                                    </span>
                                    <span v-else class="text-xs text-slate-500">
                                        {{ form.card_type === 'double' ? 'Required for DOUBLE' : 'Not required for SINGLE' }}
                                    </span>
                                    <input type="file" accept="image/png,image/jpeg,image/jpg" class="hidden" @change="onBackSelected" />
                                </label>
                                <img v-if="form.back_design && typeof form.back_design === 'string'" :src="form.back_design" class="mt-2 h-24 w-full rounded-lg object-cover ring-1 ring-slate-200" />
                            </div>
                        </div>

                        <div>
                            <label for="template-desc" class="label">Description</label>
                            <textarea id="template-desc" v-model="form.description" rows="2" class="input" placeholder="Template description (optional)"></textarea>
                        </div>

                        <div>
                            <label class="label">Status</label>
                            <select v-model="form.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <p v-if="error" class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10">
                            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ error }}</span>
                        </p>

                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button type="button" class="btn btn-secondary" @click="closeModal">Close</button>
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                <span v-if="saving" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                {{ editing ? 'Save Changes' : 'Add Template' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <ConfirmDialog
            :open="deleteTarget !== null"
            title="Delete Template"
            :message="deleteMessage"
            confirm-label="Delete"
            :loading="deleting"
            @confirm="doDelete"
            @close="deleteTarget = null"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { AlertCircle, ImagePlus, Layers, Pencil, Plus, Search, Trash2, X } from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import { templatesService } from '../services/templates';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const templates = ref([]);
const pagination = ref(null);
const loading = ref(true);
const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');
const showModal = ref(false);
const editing = ref(null);
const saving = ref(false);
const deleting = ref(false);
const deleteTarget = ref(null);
const error = ref('');
const frontPreview = ref('');
const backPreview = ref('');
let searchTimer = null;

const emptyForm = () => ({
    name: '',
    card_type: 'single',
    front_design: '',
    back_design: '',
    description: '',
    status: 'active',
});

const form = ref(emptyForm());

const deleteMessage = computed(() =>
    deleteTarget.value ? `Are you sure you want to delete template "${deleteTarget.value.name}"?` : '',
);

function openCreate() {
    editing.value = null;
    form.value = emptyForm();
    frontPreview.value = '';
    backPreview.value = '';
    error.value = '';
    showModal.value = true;
}

function openEdit(template) {
    editing.value = template;
    form.value = {
        name: template.name,
        card_type: template.card_type,
        front_design: template.front_design,
        back_design: template.back_design || '',
        description: template.description || '',
        status: template.status,
    };
    frontPreview.value = '';
    backPreview.value = '';
    error.value = '';
    showModal.value = true;
}

function closeModal() {
    if (saving.value) return;
    showModal.value = false;
}

/**
 * Largest design the server accepts, in bytes. Kept in step with
 * StoreCardTemplateRequest::MAX_DESIGN_KB, which is 5120 KB.
 */
const MAX_DESIGN_BYTES = 5 * 1024 * 1024;

function humanSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + 'MB';
    return Math.max(1, Math.round(bytes / 1024)) + 'KB';
}

/**
 * Rejects an oversized design at the moment it is chosen, and says how far over
 * it is. Waiting for the server to answer only tells the admin "must not exceed
 * 5MB", which gives no idea whether the file is a kilobyte over or twenty
 * megabytes over.
 */
function acceptDesign(event, field, previewRef) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file) return;

    if (file.size > MAX_DESIGN_BYTES) {
        toast.error(
            `${file.name} is ${humanSize(file.size)}, over the 5MB limit. ` +
            'Save it smaller: on a phone pick Medium or Low quality, or on a computer export it as JPG.'
        );
        input.value = '';
        form.value[field] = null;
        previewRef.value = '';
        return;
    }

    form.value[field] = file;
    previewRef.value = `${file.name} (${humanSize(file.size)})`;
}

function onFrontSelected(event) {
    acceptDesign(event, 'front_design', frontPreview);
}

function onBackSelected(event) {
    acceptDesign(event, 'back_design', backPreview);
}

async function submit() {
    saving.value = true;
    error.value = '';
    try {
        if (editing.value) {
            const payload = { ...form.value, front_design: form.value.front_design, back_design: form.value.back_design };
            await templatesService.update(editing.value.id, payload);
            toast.success('Template updated.');
        } else {
            await templatesService.store(form.value);
            toast.success('Template added.');
        }
        showModal.value = false;
        await load();
    } catch (e) {
        const errors = e.response?.data?.errors;
        if (errors && Object.keys(errors).length) {
            error.value = Object.values(errors)
                .flat()
                .join(' ');
        } else {
            error.value = e.response?.data?.message || 'Failed to save the template. Check the file and try again.';
        }
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(template) {
    try {
        await templatesService.toggleStatus(template.id);
        toast.success(template.status === 'active' ? 'Template turned off.' : 'Template turned on.');
        await load();
    } catch {
        toast.error('Failed to change status.');
    }
}

function askDelete(template) {
    deleteTarget.value = template;
}

async function doDelete() {
    if (!deleteTarget.value) return;
    deleting.value = true;
    try {
        await templatesService.destroy(deleteTarget.value.id);
        toast.success('Template deleted.');
        deleteTarget.value = null;
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to delete the template.');
        deleteTarget.value = null;
    } finally {
        deleting.value = false;
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

async function load() {
    loading.value = true;
    try {
        const params = { page: pagination.value?.current_page || 1 };
        if (search.value.trim()) params.q = search.value.trim();
        if (typeFilter.value) params.card_type = typeFilter.value;
        if (statusFilter.value) params.status = statusFilter.value;
        const data = await templatesService.index(params);
        templates.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
        };
    } catch {
        toast.error('Failed to fetch templates.');
    } finally {
        loading.value = false;
    }
}

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('sw-TZ');
}

onMounted(load);
</script>
