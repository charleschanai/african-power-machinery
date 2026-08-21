<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <router-link to="/guests" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-emerald-600">
            <ArrowLeft class="h-4 w-4" />
            Back to Guests
        </router-link>

        <div v-if="loading" class="flex justify-center py-20">
            <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <template v-else-if="guest">
            <div class="card overflow-hidden">
                <div class="h-20 bg-gradient-to-r from-emerald-600 via-emerald-500 to-teal-600"></div>
                <div class="px-6 pb-6">
                    <div class="-mt-9 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div class="flex items-end gap-4">
                            <span
                                class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-2xl font-bold text-emerald-700 shadow-lg ring-1 ring-slate-200"
                            >
                                {{ guest.full_name?.charAt(0)?.toUpperCase() }}
                            </span>
                            <div class="pb-1">
                                <h1 class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                                    {{ guest.full_name }}
                                </h1>
                                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500">
                                    <span class="inline-flex items-center gap-1.5">
                                        <Phone class="h-3.5 w-3.5" />
                                        {{ guest.phone }}
                                    </span>
                                    <span v-if="guest.email" class="inline-flex items-center gap-1.5">
                                        <Mail class="h-3.5 w-3.5" />
                                        {{ guest.email }}
                                    </span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <CreditCard class="h-3.5 w-3.5" />
                                        Cards {{ guest.cards.length }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-if="guest.notes" class="mt-4 flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200/60">
                        <Info class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                        {{ guest.notes }}
                    </p>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="card-title">Guest Cards</h2>
                        <p class="mt-0.5 text-xs text-slate-400">All E-Cards for this guest</p>
                    </div>
                    <button type="button" class="btn btn-primary px-3.5! py-2! text-xs" @click="showCreate = true">
                        <Plus class="h-4 w-4" />
                        Create Card
                    </button>
                </div>

                <div v-if="guest.cards.length" class="divide-y divide-slate-100">
                    <router-link
                        v-for="card in guest.cards"
                        :key="card.id"
                        :to="`/cards/${card.id}`"
                        class="flex items-center justify-between gap-3 px-5 py-4 transition-colors duration-150 hover:bg-slate-50/70 sm:px-6"
                    >
                        <div class="flex min-w-0 items-center gap-4">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                                :class="card.status === 'active' ? 'bg-emerald-50 text-emerald-600' : card.status === 'cancelled' ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500'"
                            >
                                <CreditCard class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-bold text-slate-900">{{ card.card_number }}</p>
                                <div class="mt-0.5 flex items-center gap-2">
                                    <span class="text-xs text-slate-500">{{ cardTypeLabel(card.card_type) }}</span>
                                    <span class="text-slate-300">·</span>
                                    <span class="text-xs text-slate-500">{{ card.used_people }}/{{ card.allowed_people }} entered</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <button v-if="card.guest?.phone" type="button" class="btn btn-secondary px-2! py-1! text-xs" @click.stop="sendGuestSms(card)" :disabled="sendingSmsCardId === card.id">
                                <MessageSquare class="h-3 w-3" />
                            </button>
                            <span class="badge" :class="statusBadge(card.status, card.expires_at)">{{ cardStatusLabel(card.status, card.expires_at) }}</span>
                            <ChevronRight class="h-4 w-4 text-slate-300" />
                        </div>
                    </router-link>
                </div>
                <div v-else class="flex flex-col items-center justify-center gap-3 py-14 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                        <CreditCard class="h-7 w-7" stroke-width="1.5" />
                    </span>
                    <div>
                        <p class="font-semibold text-slate-700">No cards</p>
                        <p class="mt-0.5 text-sm text-slate-400">This guest has no cards yet.</p>
                    </div>
                </div>
            </div>

            <div v-if="guest?.invitation_sends?.length" class="card">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="card-title">SMS History</h2>
                </div>
                <div class="divide-y divide-slate-100">
                    <div v-for="send in guest.invitation_sends" :key="send.id" class="px-5 py-3">
                        <div class="flex items-center justify-between">
                            <span class="badge" :class="send.channel === 'sms' ? 'badge-info' : 'badge-success'">{{ send.channel }}</span>
                            <span class="badge" :class="statusBadge(send.status)">{{ send.status }}</span>
                        </div>
                        <p v-if="send.error" class="mt-1 text-xs text-rose-600">{{ send.error }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ formatTime(send.sent_at || send.created_at) }}</p>
                    </div>
                </div>
            </div>
        </template>

        <Teleport to="body">
            <div
                v-if="showCreate"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
                @click.self="showCreate = false"
            >
                <div class="w-full max-w-md animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10">
                                <CreditCard class="h-5 w-5" />
                            </span>
                            <div>
                                <h3 class="text-lg font-bold tracking-tight text-slate-900">Create Card</h3>
                                <p class="text-xs text-slate-400">For: {{ guest.full_name }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="showCreate = false"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="mt-6 space-y-3">
                        <button
                            type="button"
                            class="flex w-full items-center gap-4 rounded-xl border-2 p-4 text-left transition-all duration-150"
                            :class="createForm.card_type === 'single' ? 'border-emerald-500 bg-emerald-50/60 ring-2 ring-emerald-500/20' : 'border-slate-200 hover:border-slate-300'"
                            @click="createForm.card_type = 'single'"
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg" :class="createForm.card_type === 'single' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-400'">
                                <User class="h-4 w-4" />
                            </span>
                            <div class="flex-1">
                                <p class="font-semibold text-slate-900">Single</p>
                                <p class="text-xs text-slate-500">Allows 1 person</p>
                            </div>
                            <CheckCircle2 v-if="createForm.card_type === 'single'" class="h-5 w-5 text-emerald-600" />
                        </button>
                        <button
                            type="button"
                            class="flex w-full items-center gap-4 rounded-xl border-2 p-4 text-left transition-all duration-150"
                            :class="createForm.card_type === 'double' ? 'border-emerald-500 bg-emerald-50/60 ring-2 ring-emerald-500/20' : 'border-slate-200 hover:border-slate-300'"
                            @click="createForm.card_type = 'double'"
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg" :class="createForm.card_type === 'double' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-400'">
                                <Users class="h-4 w-4" />
                            </span>
                            <div class="flex-1">
                                <p class="font-semibold text-slate-900">Double</p>
                                <p class="text-xs text-slate-500">Allows 2 people</p>
                            </div>
                            <CheckCircle2 v-if="createForm.card_type === 'double'" class="h-5 w-5 text-emerald-600" />
                        </button>
                    </div>

                    <div class="mt-4">
                        <label for="card-template" class="label">Template (optional)</label>
                        <select id="card-template" v-model="createForm.card_template_id" class="input">
                            <option value="">No template</option>
                            <option v-for="template in templates" :key="template.id" :value="template.id">
                                {{ template.name }}
                            </option>
                        </select>
                        <p class="mt-1.5 text-xs text-slate-400">The card will use this template's design.</p>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="event-name" class="label">Event Name</label>
                            <input id="event-name" v-model="createForm.event_name" type="text" class="input" placeholder="e.g. Alice and Bob's Wedding" />
                        </div>
                        <div>
                            <label for="venue" class="label">Venue</label>
                            <input id="venue" v-model="createForm.venue" type="text" class="input" placeholder="e.g. Garden Plaza Hall" />
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="event-date" class="label">Date</label>
                            <input id="event-date" v-model="createForm.event_date" type="date" class="input" />
                        </div>
                        <div>
                            <label for="event-time" class="label">Time</label>
                            <input id="event-time" v-model="createForm.event_time" type="time" class="input" />
                        </div>
                        <div>
                            <label for="table-number" class="label">Table</label>
                            <input id="table-number" v-model="createForm.table_number" type="text" class="input" placeholder="e.g. A12" />
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="seat-number" class="label">Seat</label>
                            <input id="seat-number" v-model="createForm.seat_number" type="text" class="input" placeholder="e.g. 5" />
                        </div>
                        <div>
                            <label for="expires_at" class="label">Expires (optional)</label>
                            <input id="expires_at" v-model="createForm.expires_at" type="datetime-local" class="input" />
                        </div>
                    </div>

                    <div class="mt-4">
                        <label for="special-message" class="label">Special Message</label>
                        <textarea id="special-message" v-model="createForm.special_message" rows="2" class="input" placeholder="Message displayed on the guest's card"></textarea>
                    </div>

                    <p
                        v-if="createError"
                        class="mt-4 flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ createError }}</span>
                    </p>

                    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn btn-secondary" @click="showCreate = false">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-primary" :disabled="creating" @click="createCard">
                            <span v-if="creating" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            <template v-else>
                                <Plus class="h-4 w-4" />
                            </template>
                            Create Card
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertCircle, ArrowLeft, CheckCircle2, ChevronRight, CreditCard, Info, Mail, MessageSquare, Phone, Plus, User, Users, X } from '@lucide/vue';
import { cardsService } from '../services/cards';
import { guestsService } from '../services/guests';
import { invitationService } from '../services/invitation';
import { templatesService } from '../services/templates';
import { useToastStore } from '../stores/toast';
import { cardStatusLabel, cardTypeLabel, statusBadge } from '../utils/labels';

const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const guest = ref(null);
const loading = ref(true);
const showCreate = ref(false);
const creating = ref(false);
const createError = ref('');
const templates = ref([]);
const sendingSmsCardId = ref(null);
const createForm = reactive({
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
});

async function load() {
    loading.value = true;
    try {
        const data = await guestsService.show(route.params.id);
        data.invitation_sends = (data.cards ?? [])
            .flatMap((card) => card.invitation_sends ?? [])
            .sort((a, b) => b.id - a.id);
        guest.value = data;
    } catch {
        toast.error('Guest not found.');
        router.push('/guests');
    } finally {
        loading.value = false;
    }
}

async function sendGuestSms(card) {
    sendingSmsCardId.value = card.id;
    try {
        const response = await invitationService.send(card.id, 'sms');
        if (response.success) {
            toast.success(`SMS sent to ${guest.value.full_name}.`);
        } else {
            toast.error(response.message || 'Failed to send the SMS.');
        }
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send the SMS.');
    } finally {
        sendingSmsCardId.value = null;
        await load();
    }
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

async function loadTemplates() {
    try {
        const data = await templatesService.index({ page: 1, status: 'active' });
        templates.value = data.data;
    } catch {
        templates.value = [];
    }
}

async function createCard() {
    creating.value = true;
    createError.value = '';
    try {
        const response = await cardsService.store({
            guest_id: guest.value.id,
            card_type: createForm.card_type,
            card_template_id: createForm.card_template_id || null,
            event_name: createForm.event_name || null,
            event_date: createForm.event_date || null,
            event_time: createForm.event_time || null,
            venue: createForm.venue || null,
            table_number: createForm.table_number || null,
            seat_number: createForm.seat_number || null,
            special_message: createForm.special_message || null,
            expires_at: createForm.expires_at || null,
        });
        toast.success('Card created.');
        showCreate.value = false;
        router.push(`/cards/${response.data.card.id}`);
    } catch (error) {
        const errors = error.response?.data?.errors;
        createError.value = errors ? Object.values(errors).flat()[0] : 'Failed to create the card.';
    } finally {
        creating.value = false;
    }
}

onMounted(() => {
    load();
    loadTemplates();
});
</script>
