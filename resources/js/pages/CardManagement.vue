<template>
    <section class="mx-auto max-w-7xl space-y-6">
        <header class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex sm:items-center sm:justify-between sm:p-6">
            <div><h1 class="page-title">{{ label }} Card Management</h1><p class="page-subtitle">SMS and shared work for {{ label.toLowerCase() }} cards.</p></div>
            <div class="mt-4 inline-flex rounded-xl bg-slate-100 p-1 sm:mt-0"><router-link v-for="kind in ['double', 'single']" :key="kind" :to="`/card-management/${kind}`" class="rounded-lg px-3 py-2 text-sm font-semibold capitalize" :class="type === kind ? 'bg-white text-emerald-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'">{{ kind }} card</router-link></div>
        </header>
        <nav class="flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm">
            <button v-for="item in tabs" :key="item.key" class="shrink-0 rounded-lg px-3 py-2.5 text-sm font-semibold transition sm:px-4" :class="tab === item.key ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100'" @click="tab = item.key">{{ item.name }}</button>
        </nav>

        <div v-if="tab === 'sms'" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-bold text-slate-900">{{ label }} Card Normal SMS</h2><p class="mb-5 mt-1 text-sm text-slate-500">Choose one or more {{ label.toLowerCase() }} card recipients.</p>
            <label class="mb-2 block text-sm font-semibold text-slate-700">Recipients <span class="font-normal text-slate-400">({{ selected.length }} selected)</span></label>
            <select v-model="selected" multiple class="input min-h-44 w-full rounded-xl"><option v-for="person in recipients" :key="person.card_id" :value="person.card_id">{{ person.name }} · {{ person.phone }} · {{ person.card_number }}</option></select>
            <label for="normal-sms-message" class="mb-2 mt-5 block text-sm font-semibold text-slate-700">Message</label><textarea id="normal-sms-message" v-model="message" class="input min-h-32 w-full rounded-xl" maxlength="5000" placeholder="Write your message" />
            <div class="mt-4 flex flex-wrap items-center gap-3"><button class="btn btn-primary" :disabled="busy || !selected.length || !message.trim()" @click="send">{{ busy ? 'Sending...' : `Send SMS to ${selected.length || 0} recipient${selected.length === 1 ? '' : 's'}` }}</button><p v-if="notice" class="text-sm font-medium text-slate-600">{{ notice }}</p></div>
        </div>

        <div v-else-if="tab === 'work'" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-bold text-slate-900">{{ work.id ? 'Edit' : 'Add' }} {{ label }} Card Work</h2><p class="mb-5 mt-1 text-sm text-slate-500">Share a task with its complete title and description.</p>
            <label for="work-title" class="mb-2 block text-sm font-semibold text-slate-700">Work Title</label><input id="work-title" v-model="work.title" class="input w-full rounded-xl" maxlength="255" placeholder="Enter work title" />
            <label for="work-description" class="mb-2 mt-5 block text-sm font-semibold text-slate-700">Work Description</label><textarea id="work-description" v-model="work.description" class="input min-h-40 w-full rounded-xl" maxlength="20000" placeholder="Enter the full work description" />
            <div class="mt-4 flex gap-2"><button class="btn btn-primary" :disabled="busy || !work.title.trim() || !work.description.trim()" @click="saveWork">{{ busy ? 'Saving...' : work.id ? 'Update Work' : 'Share Work' }}</button><button v-if="work.id" class="btn btn-secondary" @click="resetWork">Cancel</button></div>
        </div>

        <div v-else-if="tab === 'sms-history'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-5 sm:p-6"><h2 class="text-lg font-bold text-slate-900">{{ label }} Card SMS History</h2><p class="mt-1 text-sm text-slate-500">Normal SMS, invitations and reminders sent to {{ label.toLowerCase() }} card holders.</p></div>
            <div v-if="smsHistory.length" class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50"><tr><th class="table-header">Date</th><th class="table-header">Source</th><th class="table-header">Recipient</th><th class="table-header">Phone</th><th class="table-header">Message</th><th class="table-header">Status</th></tr></thead><tbody><tr v-for="row in smsHistory" :key="row.id" class="border-t border-slate-100"><td class="p-3 text-slate-500">{{ row.created_at }}</td><td class="p-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ row.source }}<span v-if="row.kind !== 'Message'"> · {{ row.kind }}</span></span></td><td class="p-3 font-medium text-slate-800">{{ row.recipient_name }}</td><td class="p-3">{{ row.phone }}</td><td class="max-w-sm truncate p-3" :title="row.message">{{ row.message }}</td><td class="p-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold capitalize" :class="['sent', 'delivered'].includes(row.status) ? 'bg-emerald-50 text-emerald-700' : row.status === 'queued' ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700'">{{ row.status }}</span><p v-if="row.error" class="mt-1 max-w-xs text-xs text-rose-600">{{ row.error }}</p></td></tr></tbody></table></div>
            <p v-else class="p-8 text-center text-sm text-slate-500">No SMS history yet.</p>
        </div>

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-lg font-bold text-slate-900">{{ label }} Card Sharing History</h2><p class="mt-1 text-sm text-slate-500">Shared work stays separate from {{ label === 'Double' ? 'Single' : 'Double' }} Card.</p></div><button class="btn btn-primary" @click="tab = 'work'; resetWork()">Add Work</button></div>
            <article v-for="row in workHistory" :key="row.id" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><h3 class="text-base font-bold text-slate-900">{{ row.title }}</h3><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{{ row.description }}</p><p class="mt-4 text-xs text-slate-400">Shared {{ row.created_at }}</p></div><div class="flex shrink-0 gap-2"><button class="btn btn-secondary" @click="editWork(row)">Edit</button><button v-if="row.created_by === userId || isAdmin" class="btn btn-secondary text-rose-600" @click="deleteWork(row)">Delete</button></div></div></article>
            <div v-if="!workHistory.length" class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center"><p class="font-semibold text-slate-700">No shared work yet</p><p class="mt-1 text-sm text-slate-500">Add work to create the first history record.</p><button class="btn btn-primary mt-4" @click="tab = 'work'; resetWork()">Add Work</button></div>
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../services/api';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';

const route = useRoute();
const auth = useAuthStore();
const toast = useToastStore();
const type = computed(() => route.params.type === 'double' ? 'double' : 'single');
const label = computed(() => type.value === 'double' ? 'Double' : 'Single');
const tabs = [{ key: 'sms', name: 'Normal SMS' }, { key: 'work', name: 'Share Work' }, { key: 'sms-history', name: 'SMS History' }, { key: 'work-history', name: 'Sharing History' }];
const tab = ref('sms');
watch(() => route.query.tab, value => { if (tabs.some(item => item.key === value)) tab.value = value; }, { immediate: true });
const recipients = ref([]); const selected = ref([]); const message = ref(''); const smsHistory = ref([]); const workHistory = ref([]);
const work = ref({ id: null, title: '', description: '' }); const busy = ref(false); const notice = ref('');
const userId = computed(() => Number(auth.user?.id)); const isAdmin = computed(() => auth.user?.role === 'admin');
const base = () => `/card-management/${type.value}`;
async function load() {
    try { const [r, s, w] = await Promise.all([api.get(`${base()}/recipients`), api.get(`${base()}/sms-history`), api.get(`${base()}/work`)]); recipients.value = r.data.data; smsHistory.value = s.data.data; workHistory.value = w.data.data; }
    catch (error) { toast.error(error.response?.data?.message || 'Could not load card management data.'); }
}
async function send() {
    busy.value = true; notice.value = '';
    try { const { data } = await api.post(`${base()}/send-sms`, { card_ids: selected.value, message: message.value }); notice.value = data.message; await load(); }
    catch (error) { toast.error(error.response?.data?.message || 'Could not send SMS.'); } finally { busy.value = false; }
}
function resetWork() { work.value = { id: null, title: '', description: '' }; }
function editWork(row) { work.value = { id: row.id, title: row.title, description: row.description }; tab.value = 'work'; }
async function saveWork() {
    busy.value = true;
    try { const url = work.value.id ? `${base()}/work/${work.value.id}` : `${base()}/work`; await api[work.value.id ? 'put' : 'post'](url, work.value); toast.success('Work saved.'); resetWork(); tab.value = 'work-history'; await load(); }
    catch (error) { toast.error(error.response?.data?.message || 'Could not save work.'); } finally { busy.value = false; }
}
async function deleteWork(row) {
    if (!window.confirm(`Delete "${row.title}"?`)) return;
    try { await api.delete(`${base()}/work/${row.id}`); toast.success('Work deleted.'); await load(); }
    catch (error) { toast.error(error.response?.data?.message || 'Could not delete work.'); }
}
watch(type, load, { immediate: true });
</script>
