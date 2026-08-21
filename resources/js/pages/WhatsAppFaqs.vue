<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-slate-900">Questions and Answers (FAQs)</h1>
                <p class="text-sm text-slate-500">The AI uses these questions to answer guests automatically.</p>
            </div>
            <button type="button" class="btn btn-primary justify-center" @click="openCreate">
                <Plus class="h-4 w-4" />
                Add Question
            </button>
        </div>

        <div class="card p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search question or answer..."
                        class="input pl-9!"
                        @input="debouncedSearch"
                    />
                </div>
                <select v-model="statusFilter" class="input w-auto" @change="load">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <div v-else-if="faqs.length" class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table w-full min-w-[700px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">Question</th>
                            <th class="table-header">Answer</th>
                            <th class="table-header">Status</th>
                            <th class="table-header text-right!">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="faq in faqs" :key="faq.id" class="hover:bg-slate-50/60">
                            <td class="table-cell max-w-[260px] font-semibold text-slate-900">{{ faq.question }}</td>
                            <td class="table-cell max-w-[340px] text-slate-600">{{ truncate(faq.answer, 120) }}</td>
                            <td class="table-cell">
                                <span class="badge" :class="faq.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ faq.status === 'active' ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="table-cell">
                                <div class="flex justify-end gap-1.5">
                                    <button type="button" class="btn btn-secondary px-3! py-1.5! text-xs" @click="openEdit(faq)">
                                        <Pencil class="h-3.5 w-3.5" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="btn px-3! py-1.5! text-xs"
                                        :class="faq.status === 'active' ? 'btn-warning' : 'btn-secondary'"
                                        @click="toggleStatus(faq)"
                                    >
                                        {{ faq.status === 'active' ? 'Turn off' : 'Turn on' }}
                                    </button>
                                    <button type="button" class="btn btn-danger px-3! py-1.5! text-xs" @click="askDelete(faq)">
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
            <HelpCircle class="h-12 w-12 text-slate-300" stroke-width="1.5" />
            <p class="text-sm font-semibold text-slate-600">No questions yet.</p>
            <p class="max-w-sm text-xs text-slate-400">
                Add frequently asked questions and answers so the AI can answer guests automatically.
            </p>
            <button type="button" class="btn btn-primary mt-2" @click="openCreate">
                <Plus class="h-4 w-4" />
                Add Question
            </button>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" @click="closeModal"></div>
                <div class="relative max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title">{{ editing ? 'Edit Question' : 'Add Question' }}</h2>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100" @click="closeModal">
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="mt-5 space-y-4" @submit.prevent="submit">
                        <div>
                            <label for="faq-question" class="label">Question *</label>
                            <input id="faq-question" v-model="form.question" type="text" class="input" placeholder="e.g. Where will the event take place?" />
                        </div>
                        <div>
                            <label for="faq-answer" class="label">Answer *</label>
                            <textarea id="faq-answer" v-model="form.answer" rows="4" class="input" placeholder="Write the answer given to the guest..."></textarea>
                        </div>
                        <div>
                            <label for="faq-status" class="label">Status</label>
                            <select id="faq-status" v-model="form.status" class="input">
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
                                {{ editing ? 'Save Changes' : 'Add Question' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <ConfirmDialog
            :open="deleteTarget !== null"
            title="Delete Question"
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
import { AlertCircle, HelpCircle, Pencil, Plus, Search, Trash2, X } from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import { whatsappService } from '../services/whatsapp';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const faqs = ref([]);
const pagination = ref(null);
const loading = ref(true);
const search = ref('');
const statusFilter = ref('');
const showModal = ref(false);
const editing = ref(null);
const saving = ref(false);
const deleting = ref(false);
const deleteTarget = ref(null);
const error = ref('');
let searchTimer = null;

const emptyForm = () => ({ question: '', answer: '', status: 'active' });
const form = ref(emptyForm());

const deleteMessage = computed(() =>
    deleteTarget.value ? `Are you sure you want to delete question "${deleteTarget.value.question}"?` : '',
);

function openCreate() {
    editing.value = null;
    form.value = emptyForm();
    error.value = '';
    showModal.value = true;
}

function openEdit(faq) {
    editing.value = faq;
    form.value = { question: faq.question, answer: faq.answer, status: faq.status };
    error.value = '';
    showModal.value = true;
}

function closeModal() {
    if (saving.value) return;
    showModal.value = false;
}

async function submit() {
    saving.value = true;
    error.value = '';
    try {
        if (editing.value) {
            await whatsappService.updateFaq(editing.value.id, form.value);
            toast.success('Question updated.');
        } else {
            await whatsappService.storeFaq(form.value);
            toast.success('Question added.');
        }
        showModal.value = false;
        await load();
    } catch (e) {
        error.value = e.response?.data?.message || 'Failed to save the question.';
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(faq) {
    try {
        await whatsappService.toggleFaq(faq.id);
        toast.success(faq.status === 'active' ? 'Question turned off.' : 'Question turned on.');
        await load();
    } catch {
        toast.error('Failed to change status.');
    }
}

function askDelete(faq) {
    deleteTarget.value = faq;
}

async function doDelete() {
    if (!deleteTarget.value) return;
    deleting.value = true;
    try {
        await whatsappService.destroyFaq(deleteTarget.value.id);
        toast.success('Question deleted.');
        deleteTarget.value = null;
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to delete the question.');
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
        if (statusFilter.value) params.status = statusFilter.value;
        const data = await whatsappService.getFaqs(params);
        faqs.value = data.data;
        pagination.value = { current_page: data.current_page, last_page: data.last_page };
    } catch {
        toast.error('Failed to fetch questions.');
    } finally {
        loading.value = false;
    }
}

function truncate(text, max) {
    if (!text) return '';
    return text.length > max ? `${text.slice(0, max)}...` : text;
}

onMounted(load);
</script>
