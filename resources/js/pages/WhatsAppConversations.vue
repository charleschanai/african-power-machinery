<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900">WhatsApp Conversations</h1>
            <p class="text-sm text-slate-500">View and reply to guests' conversations.</p>
        </div>

        <div class="card p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by number or name..."
                        class="input pl-9!"
                        @input="debouncedSearch"
                    />
                </div>
                <div class="flex flex-wrap gap-3">
                    <select v-model="statusFilter" class="input w-auto" @change="load">
                        <option value="">All Statuses</option>
                        <option value="ai">AI</option>
                        <option value="manual">Admin</option>
                        <option value="blocked">Blocked</option>
                    </select>
                    <select v-model="matchedFilter" class="input w-auto" @change="load">
                        <option value="">All</option>
                        <option value="1">Matched to guest</option>
                        <option value="0">Not matched</option>
                    </select>
                </div>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <div v-else-if="conversations.length" class="grid gap-4 xl:grid-cols-3">
            <div class="card overflow-hidden xl:col-span-1">
                <div class="overflow-x-auto">
                    <table class="data-table w-full min-w-[520px] text-left">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="table-header">Guest</th>
                                <th class="table-header">Status</th>
                                <th class="table-header">Message</th>
                                <th class="table-header text-right!">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="conversation in conversations"
                                :key="conversation.id"
                                class="cursor-pointer transition"
                                :class="selected?.id === conversation.id ? 'bg-emerald-50/70' : 'hover:bg-slate-50/60'"
                                @click="select(conversation)"
                            >
                                <td class="table-cell">
                                    <p class="font-semibold text-slate-900">{{ conversation.guest_name || 'Unknown Guest' }}</p>
                                    <p class="text-xs text-slate-500">{{ formatPhone(conversation.phone) }}</p>
                                </td>
                                <td class="table-cell">
                                    <span class="badge" :class="statusBadge(conversation.status)">
                                        {{ conversation.status_label }}
                                    </span>
                                </td>
                                <td class="table-cell max-w-[160px]">
                                    <p class="truncate text-slate-600">{{ conversation.last_message || '-' }}</p>
                                </td>
                                <td class="table-cell whitespace-nowrap text-xs text-slate-500">
                                    {{ formatDate(conversation.last_message_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-100 p-4">
                    <Pagination :pagination="pagination" @change="changePage" />
                </div>
            </div>

            <div class="xl:col-span-2">
                <div v-if="selectedDetail" class="card flex h-full flex-col overflow-hidden">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 p-4">
                        <div class="min-w-0">
                            <h2 class="truncate text-sm font-bold text-slate-900">
                                {{ selectedDetail.conversation.guest_name || 'Unknown Guest' }}
                            </h2>
                            <p class="text-xs text-slate-500">{{ formatPhone(selectedDetail.conversation.phone) }}</p>
                            <div v-if="selectedDetail.guest" class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                <span class="badge badge-info">{{ selectedDetail.guest.full_name }}</span>
                                <span v-if="selectedDetail.guest.card_number" class="badge badge-success">
                                    Card: {{ selectedDetail.guest.card_number }}
                                </span>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-1.5">
                            <button
                                v-if="selectedDetail.conversation.status === 'ai'"
                                type="button"
                                class="btn btn-secondary px-3! py-1.5! text-xs"
                                @click="takeOver"
                            >
                                <UserCheck class="h-3.5 w-3.5" />
                                Take over
                            </button>
                            <button
                                v-if="selectedDetail.conversation.status !== 'blocked'"
                                type="button"
                                class="btn px-3! py-1.5! text-xs"
                                :class="selectedDetail.conversation.status === 'manual' ? 'btn-secondary' : 'btn-danger'"
                                @click="selectedDetail.conversation.status === 'manual' ? release() : block()"
                            >
                                {{ selectedDetail.conversation.status === 'manual' ? 'Return to AI' : 'Block' }}
                            </button>
                            <button
                                v-if="selectedDetail.conversation.status === 'blocked'"
                                type="button"
                                class="btn btn-secondary px-3! py-1.5! text-xs"
                                @click="unblock"
                            >
                                <Unlock class="h-3.5 w-3.5" />
                                Allow again
                            </button>
                        </div>
                    </div>

                    <div class="flex-1 space-y-3 overflow-y-auto bg-slate-50 p-4" style="max-height: 420px">
                        <div v-for="message in messages" :key="message.id">
                            <div
                                class="flex"
                                :class="message.direction === 'incoming' ? 'justify-start' : 'justify-end'"
                            >
                                <div
                                    class="max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-sm"
                                    :class="message.direction === 'incoming' ? 'rounded-tl-sm bg-white' : 'bg-emerald-500 text-white rounded-tr-sm'"
                                >
                                    <p class="whitespace-pre-wrap break-words">{{ message.body }}</p>
                                    <div
                                        class="mt-1 flex items-center justify-end gap-2 text-[10px]"
                                        :class="message.direction === 'incoming' ? 'text-slate-400' : 'text-emerald-100'"
                                    >
                                        <span>{{ message.sender_type_label }}</span>
                                        <span>{{ formatTime(message.created_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="!messages.length" class="py-10 text-center text-sm text-slate-400">
                            No messages in this conversation.
                        </div>
                    </div>

                    <div class="border-t border-slate-200 p-4">
                        <form class="flex items-end gap-2" @submit.prevent="sendReply">
                            <textarea
                                v-model="replyText"
                                rows="2"
                                class="input flex-1 resize-none"
                                placeholder="Write a message to the guest..."
                                @keydown.ctrl.enter.prevent="sendReply"
                            ></textarea>
                            <button type="submit" class="btn btn-primary" :disabled="sending || !replyText.trim()">
                                <span v-if="sending" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                <Send class="h-4 w-4" />
                                Send
                            </button>
                        </form>
                        <p v-if="selectedDetail.conversation.status === 'ai'" class="mt-2 text-xs text-slate-400">
                            If you send a message, the conversation will be taken over by an admin automatically.
                        </p>
                    </div>
                </div>

                <div v-else class="card flex h-full flex-col items-center justify-center gap-3 py-20 text-center">
                    <MessagesSquare class="h-12 w-12 text-slate-300" stroke-width="1.5" />
                    <p class="text-sm font-semibold text-slate-600">Select a conversation</p>
                    <p class="max-w-sm text-xs text-slate-400">Click a conversation on the left to open it.</p>
                </div>
            </div>
        </div>

        <div v-else class="card flex flex-col items-center justify-center gap-3 py-20 text-center">
            <MessagesSquare class="h-12 w-12 text-slate-300" stroke-width="1.5" />
            <p class="text-sm font-semibold text-slate-600">No conversations yet.</p>
            <p class="max-w-sm text-xs text-slate-400">
                When a guest sends a message to your WhatsApp number, conversations will appear here.
            </p>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { MessagesSquare, Search, Send, Unlock, UserCheck } from '@lucide/vue';
import Pagination from '../components/Pagination.vue';
import { whatsappService } from '../services/whatsapp';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const conversations = ref([]);
const pagination = ref(null);
const loading = ref(true);
const search = ref('');
const statusFilter = ref('');
const matchedFilter = ref('');
const selected = ref(null);
const selectedDetail = ref(null);
const messages = ref([]);
const replyText = ref('');
const sending = ref(false);
let searchTimer = null;

function statusBadge(status) {
    return {
        ai: 'badge-info',
        manual: 'badge-warning',
        blocked: 'badge-danger',
    }[status] || 'badge-neutral';
}

async function select(conversation) {
    selected.value = conversation;
    try {
        const res = await whatsappService.getConversation(conversation.id);
        selectedDetail.value = res.data;
        messages.value = res.data.messages || [];
        replyText.value = '';
    } catch {
        toast.error('Failed to fetch conversations.');
    }
}

async function sendReply() {
    if (!selectedDetail.value || !replyText.value.trim()) return;
    sending.value = true;
    try {
        const res = await whatsappService.replyConversation(selectedDetail.value.conversation.id, replyText.value.trim());
        toast.success(res.message || 'Message sent.');
        await select(selected.value);
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to send the message.');
    } finally {
        sending.value = false;
    }
}

async function takeOver() {
    await action(() => whatsappService.takeOverConversation(selectedDetail.value.conversation.id), 'Conversation taken over by an admin.');
}

async function release() {
    await action(() => whatsappService.releaseConversation(selectedDetail.value.conversation.id), 'Conversation returned to AI.');
}

async function block() {
    await action(() => whatsappService.blockConversation(selectedDetail.value.conversation.id), 'Number blocked.');
}

async function unblock() {
    await action(() => whatsappService.unblockConversation(selectedDetail.value.conversation.id), 'Number allowed again.');
}

async function action(call, successMessage) {
    try {
        await call();
        toast.success(successMessage);
        await select(selected.value);
        await load();
    } catch {
        toast.error('Failed to change the conversation status.');
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
        if (matchedFilter.value !== '') params.matched = matchedFilter.value;
        const data = await whatsappService.getConversations(params);
        conversations.value = data.data;
        pagination.value = { current_page: data.current_page, last_page: data.last_page };
    } catch {
        toast.error('Failed to fetch conversations.');
    } finally {
        loading.value = false;
    }
}

function formatPhone(phone) {
    return phone ? `+${phone}` : '-';
}

function formatDate(date) {
    if (!date) return '-';
    const d = new Date(date);
    return `${d.toLocaleDateString('sw-TZ')} ${d.toLocaleTimeString('sw-TZ', { hour: '2-digit', minute: '2-digit' })}`;
}

function formatTime(date) {
    if (!date) return '';
    return new Date(date).toLocaleTimeString('sw-TZ', { hour: '2-digit', minute: '2-digit' });
}

onMounted(load);
</script>
