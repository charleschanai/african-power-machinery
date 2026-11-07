<template>
    <div class="mx-auto max-w-5xl space-y-6">
        <router-link to="/cards" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-emerald-600">
            <ArrowLeft class="h-4 w-4" />
            Back to Cards
        </router-link>

        <div v-if="loading" class="flex justify-center py-20">
            <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <template v-else-if="card">
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="card p-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                                <CreditCard class="h-5 w-5" />
                            </span>
                            <div>
                                <h1 class="text-lg font-bold tracking-tight text-slate-900">Card Details</h1>
                                <p class="text-xs text-slate-400">{{ card.card_number }}</p>
                            </div>
                        </div>
                        <span class="badge" :class="statusBadge(card.status, card.expires_at)">
                            {{ cardStatusLabel(card.status, card.expires_at) }}
                        </span>
                    </div>

                    <dl class="mt-6 space-y-4 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <User class="h-4 w-4 text-slate-400" />
                                Guest
                            </dt>
                            <dd class="font-semibold text-slate-900">{{ card.guest?.full_name }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Phone class="h-4 w-4 text-slate-400" />
                                Phone Number
                            </dt>
                            <dd class="text-slate-900">{{ card.guest?.phone }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Copy class="h-4 w-4 text-slate-400" />
                                Card Number
                            </dt>
                            <dd class="font-mono font-bold text-slate-900">{{ card.card_number }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Layers class="h-4 w-4 text-slate-400" />
                                Card Type
                            </dt>
                            <dd>
                                <span class="badge" :class="card.card_type === 'single' ? 'badge-success' : 'badge-info'">
                                    {{ cardTypeLabel(card.card_type) }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Users class="h-4 w-4 text-slate-400" />
                                Allowed People
                            </dt>
                            <dd class="font-semibold text-slate-900">{{ card.allowed_people }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <UserCheck class="h-4 w-4 text-slate-400" />
                                Entered
                            </dt>
                            <dd class="font-semibold text-slate-900">{{ card.used_people }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Calendar class="h-4 w-4 text-slate-400" />
                                Issued
                            </dt>
                            <dd class="text-slate-900">{{ formatDate(card.issued_at) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="flex items-center gap-2 text-slate-500">
                                <Clock class="h-4 w-4 text-slate-400" />
                                Expires
                            </dt>
                            <dd class="font-semibold" :class="expiryClass">
                                {{ card.expires_at ? formatDate(card.expires_at) : 'No expiry' }}
                            </dd>
                        </div>
                        <div v-if="hasEvent" class="!mt-5 border-t border-slate-100 pt-4">
                            <div class="flex items-center gap-2 text-slate-500">
                                <CalendarRange class="h-4 w-4 text-slate-400" />
                                Event Details
                            </div>
                            <div class="mt-3 space-y-2.5">
                                <p v-if="card.event_name" class="text-sm font-bold text-slate-900">{{ card.event_name }}</p>
                                <p v-if="card.event_date || card.event_time" class="text-xs text-slate-600">
                                    {{ formatEventWhen }}
                                </p>
                                <p v-if="card.venue" class="text-xs text-slate-600">Venue: {{ card.venue }}</p>
                                <div v-if="card.table_number || card.seat_number" class="flex flex-wrap gap-2">
                                    <span v-if="card.table_number" class="badge badge-info">Table {{ card.table_number }}</span>
                                    <span v-if="card.seat_number" class="badge badge-neutral">Seat {{ card.seat_number }}</span>
                                </div>
                                <p v-if="card.special_message" class="rounded-xl bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-800 ring-1 ring-inset ring-amber-600/10">
                                    {{ card.special_message }}
                                </p>
                            </div>
                        </div>
                    </dl>

                    <div class="mt-6 space-y-2 border-t border-slate-100 pt-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Share Manually</p>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="btn btn-primary flex-1"
                                :disabled="!card.guest?.phone"
                                @click="copyWaMessage"
                            >
                                <Copy class="h-4 w-4" />
                                Copy Message
                            </button>
                            <a
                                v-if="waLink"
                                :href="waLink"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-secondary flex-1"
                            >
                                <Smartphone class="h-4 w-4 text-emerald-600" />
                                Open WhatsApp
                            </a>
                        </div>

                        <button
                            type="button"
                            class="btn btn-secondary w-full"
                            :disabled="!card.guest?.phone"
                            @click="copyWaLink"
                        >
                            <Link2 class="h-4 w-4 text-emerald-600" />
                            Copy WhatsApp Link
                        </button>

                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400 pt-2">Auto Send</p>

                        <button
                            type="button"
                            class="btn btn-secondary w-full"
                            :disabled="sendingSms || !card.guest?.phone"
                            @click="sendSms"
                        >
                            <span v-if="sendingSms" class="h-4 w-4 animate-spin rounded-full border-2 border-emerald-500/30 border-t-emerald-600"></span>
                            <template v-else>
                                <MessageSquare class="h-4 w-4 text-emerald-600" />
                            </template>
                            Send SMS (NextSMS)
                        </button>

                        <button
                            type="button"
                            class="btn btn-secondary w-full"
                            :disabled="sendingEmail || !card.guest?.email"
                            @click="sendEmailInvite"
                        >
                            <span v-if="sendingEmail" class="h-4 w-4 animate-spin rounded-full border-2 border-blue-500/30 border-t-blue-600"></span>
                            <template v-else>
                                <Mail class="h-4 w-4 text-blue-600" />
                            </template>
                            Send Email
                        </button>

                        <button
                            v-if="card.status === 'active'"
                            type="button"
                            class="btn btn-warning w-full"
                            @click="showCancel = true"
                        >
                            <Ban class="h-4 w-4" />
                            Cancel Card
                        </button>
                    </div>

                    <p
                        v-if="sendNote"
                        class="mt-4 flex items-start gap-2 rounded-xl bg-sky-50 px-3 py-2.5 text-xs text-sky-700 ring-1 ring-inset ring-sky-600/10"
                    >
                        <Info class="mt-0.5 h-4 w-4 shrink-0" />
                        {{ sendNote }}
                    </p>

                    <p
                        v-if="smsNote"
                        class="mt-2 flex items-start gap-2 rounded-xl bg-sky-50 px-3 py-2.5 text-xs text-sky-700 ring-1 ring-inset ring-sky-600/10"
                    >
                        <Info class="mt-0.5 h-4 w-4 shrink-0" />
                        {{ smsNote }}
                    </p>
                </div>

                <div class="space-y-4">
                    <div v-if="card.cardTemplate" class="card overflow-hidden">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-900">{{ card.cardTemplate.name }}</p>
                                <p class="text-xs text-slate-400">Card design</p>
                            </div>
                            <div class="flex shrink-0 rounded-lg bg-slate-100 p-0.5">
                                <button
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                                    :class="designTab === 'front' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                                    @click="designTab = 'front'"
                                >
                                    Front
                                </button>
                                <button
                                    v-if="card.cardTemplate.back_design"
                                    type="button"
                                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                                    :class="designTab === 'back' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                                    @click="designTab = 'back'"
                                >
                                    Back
                                </button>
                            </div>
                        </div>
                        <div class="bg-slate-100 p-4">
                            <div class="relative inline-block w-full">
                                <img
                                    :src="designTab === 'front' ? card.cardTemplate.front_design : card.cardTemplate.back_design"
                                    :alt="card.cardTemplate.name"
                                    class="mx-auto max-h-96 w-full rounded-xl object-contain ring-1 ring-slate-200"
                                />
                                <div class="absolute bottom-2 right-2 rounded-lg bg-white p-1 shadow-lg ring-1 ring-slate-200">
                                    <QRCodeCanvas :value="cardUrl" :size="70" />
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-between">
                                <p class="text-xs text-slate-500">QR code on card — scan verifies guest</p>
                                <button type="button" class="btn btn-secondary px-3! py-1.5! text-xs" @click="downloadCompositeCard">
                                    <Download class="h-3.5 w-3.5" />
                                    Download Card + QR
                                </button>
                            </div>
                        </div>
                    </div>

                    <div
                        class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 via-emerald-700 to-teal-900 p-6 text-white shadow-xl shadow-emerald-900/20"
                    >
                        <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/10 blur-2xl"></div>
                        <div class="pointer-events-none absolute -bottom-16 -left-10 h-48 w-48 rounded-full bg-teal-400/20 blur-3xl"></div>

                        <div class="relative">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-emerald-300">
                                        Entry E-Card
                                    </p>
                                    <p class="mt-1 text-lg font-bold">{{ card.guest?.full_name }}</p>
                                </div>
                                <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-bold tracking-wider backdrop-blur">
                                    {{ cardTypeLabel(card.card_type) }}
                                </span>
                            </div>

                            <div class="mt-6 flex justify-center">
                                <div class="rounded-2xl bg-white p-3 shadow-lg">
                                    <QRCodeCanvas ref="qrCanvas" :value="cardUrl" :size="160" />
                                </div>
                            </div>

                            <div class="mt-6 flex items-end justify-between">
                                <div>
                                    <p class="text-[10px] uppercase tracking-widest text-emerald-300">Card Number</p>
                                    <p class="mt-0.5 font-mono text-lg font-bold tracking-widest">{{ card.card_number }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] uppercase tracking-widest text-emerald-300">Usage</p>
                                    <p class="mt-0.5 font-bold">{{ card.used_people }}/{{ card.allowed_people }}</p>
                                </div>
                            </div>

                            <div class="mt-6 rounded-2xl bg-white/10 p-3 text-center backdrop-blur">
                                <p class="text-xs leading-relaxed text-emerald-100">
                                    Please present this card at the gate when entering.
                                </p>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <button type="button" class="btn btn-secondary w-full px-3! py-2! text-xs" @click="downloadQr">
                                    <Download class="h-4 w-4 text-emerald-600" />
                                    Download QR
                                </button>
                                <button type="button" class="btn btn-secondary w-full px-3! py-2! text-xs" @click="printCard">
                                    <Printer class="h-4 w-4 text-emerald-600" />
                                    Print
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="card flex items-center justify-between p-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10">
                                <Link2 class="h-4 w-4" />
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900">E-Card Link</p>
                                <p class="text-xs text-slate-400">Guest opens it on their phone</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="btn btn-secondary px-3! py-2! text-xs"
                                @click="copyLink"
                            >
                                <Copy class="h-3.5 w-3.5" />
                                Copy
                            </button>
                            <a
                                :href="cardUrl"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-secondary px-3! py-2! text-xs"
                            >
                                Open
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
                    <div>
                        <h2 class="card-title">Usage History</h2>
                        <p class="mt-0.5 text-xs text-slate-400">Any entry attempt with this card</p>
                    </div>
                </div>

                <div v-if="card.entry_logs?.length">
                    <div
                        v-for="log in card.entry_logs"
                        :key="log.id"
                        class="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors duration-150 hover:bg-slate-50/70 sm:px-6"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                :class="log.result === 'allowed' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'"
                            >
                                <Check v-if="log.result === 'allowed'" class="h-4 w-4" />
                                <X v-else class="h-4 w-4" />
                            </span>
                            <div>
                                <span class="badge" :class="log.result === 'allowed' ? 'badge-success' : 'badge-danger'">
                                    {{ entryResultLabel(log.result) }}
                                </span>
                                <span class="ml-2 text-xs text-slate-400">Attempt #{{ log.entry_number }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-slate-500">{{ log.scanned_at }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">Attendant: {{ log.processor?.name || '-' }}</p>
                            <p v-if="log.verification_method || log.number_of_guests" class="mt-0.5 text-xs text-slate-400">
                                {{ verificationMethodLabel(log.verification_method?.value || log.verification_method) }}
                                <template v-if="log.number_of_guests && log.result === 'allowed'"> · People {{ log.number_of_guests }}</template>
                            </p>
                        </div>
                    </div>
                </div>
                <div v-else class="flex flex-col items-center justify-center gap-2 py-14 text-slate-400">
                    <History class="h-10 w-10" stroke-width="1.5" />
                    <p class="text-sm">No usage yet.</p>
                </div>
            </div>

            <ConfirmDialog
                :open="showCancel"
                title="Cancel Card"
                :message="`Are you sure you want to cancel card ${card.card_number}?`"
                confirm-label="Cancel Card"
                :loading="cancelling"
                @confirm="doCancel"
                @close="showCancel = false"
            />
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    ArrowLeft,
    Ban,
    Calendar,
    CalendarRange,
    Check,
    Clock,
    Copy,
    CreditCard,
    Download,
    History,
    Info,
    Layers,
    Link2,
    Mail,
    MessageSquare,
    Phone,
    Printer,
    Send,
    Smartphone,
    User,
    UserCheck,
    Users,
    X,
} from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import QRCodeCanvas from '../components/QRCodeCanvas.vue';
import { cardsService } from '../services/cards';
import { invitationService } from '../services/invitation';
import { whatsappService } from '../services/whatsapp';
import { useToastStore } from '../stores/toast';
import { cardStatusLabel, cardTypeLabel, entryResultLabel, statusBadge, verificationMethodLabel } from '../utils/labels';

const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const card = ref(null);
const loading = ref(true);
const sending = ref(false);
const sendingSms = ref(false);
const sendingEmail = ref(false);
const cancelling = ref(false);
const showCancel = ref(false);
const sendNote = ref('');
const smsNote = ref('');
const designTab = ref('front');
const qrCanvas = ref(null);

const hasEvent = computed(() =>
    Boolean(
        card.value?.event_name ||
        card.value?.event_date ||
        card.value?.event_time ||
        card.value?.venue ||
        card.value?.table_number ||
        card.value?.seat_number ||
        card.value?.special_message,
    ),
);

const formatEventWhen = computed(() => {
    const parts = [];
    if (card.value?.event_date) {
        parts.push(new Date(card.value.event_date + 'T00:00:00').toLocaleDateString('sw-TZ', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }));
    }
    if (card.value?.event_time) parts.push(card.value.event_time);
    return parts.join(' · ');
});

const expiryClass = computed(() => {
    if (!card.value?.expires_at) return 'text-slate-900';
    return new Date(card.value.expires_at) < new Date() ? 'text-rose-600' : 'text-slate-900';
});

/*
 * The address printed inside this card's QR code.
 *
 * This screen prints the card that is handed to the guest, so getting this
 * wrong cannot be undone later. window.location.origin was used here, which on
 * the machine that prints the card is 127.0.0.1, or 10.180.231.187 on the local
 * network. A guest scanning that with a phone at the gate gets nothing at all,
 * because their phone has never heard of either address. The server is asked
 * instead, since it is the only side that knows the public one.
 */
const cardUrl = computed(() => {
    if (!card.value) return '';
    return card.value.card_url || `${window.location.origin}/e-card/${card.value.qr_token}`;
});

const waLink = computed(() => {
    if (!card.value?.guest?.phone) return '';
    const phone = card.value.guest.phone.replace(/\D/g, '');
    const msg = buildWaMessage();
    return `https://wa.me/${phone}?text=${encodeURIComponent(msg)}`;
});

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('sw-TZ');
}

async function load() {
    loading.value = true;
    try {
        const result = await cardsService.show(route.params.id);
        card.value = result.card ?? result;
    } catch {
        toast.error('Card not found.');
        router.push('/cards');
    } finally {
        loading.value = false;
    }
}

async function sendWhatsApp() {
    sending.value = true;
    sendNote.value = '';
    try {
        const response = await whatsappService.sendCard(card.value.id);
        toast[response.success ? 'success' : 'warning'](response.message);
        if (!response.success) sendNote.value = 'WhatsApp API not configured. Use "Copy Message" or "Open WhatsApp" to share manually.';
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send the card.');
    } finally {
        sending.value = false;
    }
}

async function sendSms() {
    sendingSms.value = true;
    smsNote.value = '';
    try {
        const response = await invitationService.send(card.value.id, 'sms');
        toast[response.success ? 'success' : 'warning'](response.message);
        if (!response.success) smsNote.value = 'SMS failed. Check your SMS settings (Africa\'s Talking) and try again.';
    } catch (error) {
        smsNote.value = error.response?.data?.message || 'Failed to send SMS.';
        toast.error(smsNote.value);
    } finally {
        sendingSms.value = false;
    }
}

async function sendEmailInvite() {
    sendingEmail.value = true;
    try {
        const response = await cardsService.sendEmail(card.value.id);
        toast[response.success ? 'success' : 'warning'](response.message);
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send email.');
    } finally {
        sendingEmail.value = false;
    }
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(cardUrl.value);
        toast.success('Link copied.');
    } catch {
        toast.error('Failed to copy the link.');
    }
}

async function copyWaMessage() {
    try {
        const message = buildWaMessage();
        await navigator.clipboard.writeText(message);
        toast.success('WhatsApp message copied! Paste it into the chat.');
    } catch {
        toast.error('Failed to copy the message.');
    }
}

async function copyWaLink() {
    try {
        await navigator.clipboard.writeText(waLink.value);
        toast.success('WhatsApp link copied!');
    } catch {
        toast.error('Failed to copy the link.');
    }
}

function buildWaMessage() {
    const c = card.value;
    const guestName = c.guest?.full_name || 'Guest';
    let msg = `Hello ${guestName},\n\nYou have been invited to the event.`;

    if (c.event_name) msg += `\n\nEvent: ${c.event_name}`;
    if (c.event_date) msg += `\nDate: ${new Date(c.event_date + 'T00:00:00').toLocaleDateString('en-GB')}`;
    if (c.event_time) msg += `\nTime: ${c.event_time}`;
    if (c.venue) msg += `\nVenue: ${c.venue}`;
    if (c.table_number) msg += `\nTable: ${c.table_number}`;
    if (c.seat_number) msg += `\nSeat: ${c.seat_number}`;

    msg += `\n\nCard: ${c.card_number} (${c.card_type})`;
    msg += `\n\nOpen your E-Card:\n${cardUrl.value}`;

    if (c.special_message) msg += `\n\n${c.special_message}`;
    msg += `\n\nThank you.`;

    return msg;
}

async function downloadQr() {
    const canvas = qrCanvas.value?.canvasRef;
    if (!canvas) {
        toast.error('QR not ready yet.');
        return;
    }
    try {
        const link = document.createElement('a');
        link.download = `e-card-${card.value?.card_number || 'qr'}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        toast.success('QR is being downloaded.');
    } catch {
        toast.error('Failed to download the QR.');
    }
}

async function downloadCompositeCard() {
    const template = card.value?.cardTemplate;
    const imgUrl = designTab.value === 'front' ? template?.front_design : template?.back_design;
    if (!imgUrl) {
        toast.error('No card design found.');
        return;
    }
    try {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        await new Promise((resolve, reject) => {
            img.onload = resolve;
            img.onerror = () => reject(new Error('Failed to load card image.'));
            img.src = imgUrl;
        });

        const canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);

        const qrSize = Math.min(Math.floor(img.naturalWidth * 0.18), 250);
        const padding = Math.floor(qrSize * 0.08);
        const x = img.naturalWidth - qrSize - (padding * 2);
        const y = img.naturalHeight - qrSize - (padding * 2);

        ctx.fillStyle = '#f0f0f0';
        ctx.beginPath();
        ctx.roundRect(x - padding, y - padding, qrSize + padding * 2, qrSize + padding * 2, 8);
        ctx.fill();

        const qrAllCanvases = document.querySelectorAll('.bg-slate-100 canvas');
        let qrSource = null;
        qrAllCanvases.forEach(c => { if (c.width > 30) qrSource = c; });

        if (qrSource) {
            ctx.drawImage(qrSource, x, y, qrSize, qrSize);
        }

        const link = document.createElement('a');
        link.download = `card-${card.value?.card_number || 'card'}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        toast.success('Card with QR downloaded!');
    } catch (e) {
        toast.error(e.message || 'Failed to create composite card.');
    }
}

function printCard() {
    window.print();
}

async function doCancel() {
    cancelling.value = true;
    try {
        await cardsService.cancel(card.value.id);
        toast.success('Card cancelled.');
        showCancel.value = false;
        await load();
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to cancel the card.');
        showCancel.value = false;
    } finally {
        cancelling.value = false;
    }
}

onMounted(load);
</script>
