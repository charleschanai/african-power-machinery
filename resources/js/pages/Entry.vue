<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="text-center">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">
                Entry Check
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Search for a guest or scan the E-Card QR Code.
            </p>
            <div class="mx-auto mt-4 flex max-w-xs items-center gap-2">
                <label for="gate-name" class="shrink-0 text-sm font-semibold text-slate-600">Gate:</label>
                <input
                    id="gate-name"
                    v-model="gateName"
                    type="text"
                    class="input px-3! py-1.5! text-sm"
                    placeholder="Gate name"
                    @change="saveGate"
                />
            </div>
        </div>

        <div v-if="!cardInfo" class="space-y-6">
            <div class="card p-5 sm:p-6">
                <label for="search" class="label">Search for a guest or card number</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        id="search"
                        v-model="searchTerm"
                        type="text"
                        class="input py-3! pl-10! pr-11! text-base"
                        placeholder="Name or card number..."
                        @input="onSearch"
                    />
                    <span
                        v-if="searching"
                        class="absolute right-3.5 top-1/2 h-5 w-5 -translate-y-1/2 animate-spin rounded-full border-2 border-emerald-500/30 border-t-emerald-600"
                    ></span>
                    <span
                        v-else
                        class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400"
                    >
                        <Search class="h-4 w-4" />
                    </span>
                </div>

                <p
                    v-if="noResults"
                    class="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-800 ring-1 ring-inset ring-amber-600/10"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    No results. Check the spelling or try the card number.
                </p>

                <div v-if="results.length" class="mt-4 space-y-2">
                    <p class="text-sm font-semibold text-slate-600">
                        Results ({{ results.length }})
                    </p>
                    <button
                        v-for="card in results"
                        :key="card.id"
                        type="button"
                        class="group flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left transition-all duration-150 hover:border-emerald-400 hover:bg-emerald-50/50 hover:shadow-sm"
                        :disabled="verifying"
                        @click="selectCard(card)"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                                :class="usageColor(card).includes('red') ? 'bg-rose-50 text-rose-500' : 'bg-emerald-50 text-emerald-600'"
                            >
                                <CreditCard class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-900">{{ card.guest_name }}</p>
                                <p class="mt-0.5 truncate text-xs text-slate-500">
                                    {{ card.card_number }} · {{ card.card_type_label }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <div class="text-right">
                                <p class="text-sm font-bold" :class="usageColor(card)">
                                    {{ card.used_people }}/{{ card.allowed_people }}
                                </p>
                                <p class="text-xs" :class="statusColor(card.status)">
                                    {{ card.status_label }}
                                </p>
                            </div>
                            <ChevronRight class="h-4 w-4 text-slate-300 transition group-hover:text-emerald-500" />
                        </div>
                    </button>
                </div>
            </div>

            <div class="divider-text">
                or
            </div>

            <div v-if="!showScanner" class="card p-5">
                <button
                    type="button"
                    class="btn btn-primary w-full py-4! text-base"
                    @click="showScanner = true"
                >
                    <ScanLine class="h-5 w-5" />
                    Scan QR Code
                </button>
                <p class="mt-3 text-center text-xs text-slate-400">
                    Use the device camera to scan the E-Card QR Code.
                </p>
            </div>

            <div v-else class="card p-5">
                <QRScanner
                    @decoded="handleDecoded"
                    @error="onScannerError"
                />
            </div>
        </div>

        <div v-else class="card p-5 sm:p-6 animate-fade-in">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold tracking-tight text-slate-900">Guest Details</h2>
                <span class="badge" :class="cardInfo.data?.card?.status === 'active' ? 'badge-success' : cardInfo.data?.card?.status === 'cancelled' ? 'badge-danger' : cardInfo.data?.card?.status === 'expired' ? 'badge-warning' : 'badge-neutral'">
                    {{ cardInfo.data?.card?.status_label }}
                </span>
            </div>

            <div v-if="cardInfo.success" class="mt-4 flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
                <CheckCircle2 class="h-4 w-4 shrink-0" />
                CARD FOUND
            </div>

            <div class="mt-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-400">Guest E-Card</h3>
                    <span v-if="cardTemplate" class="text-xs font-semibold text-slate-500">{{ cardTemplate.name }}</span>
                </div>

                <div v-if="cardTemplate" class="mt-3">
                    <div
                        class="group relative overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200"
                        @click="showCardLightbox = true"
                    >
                        <img
                            :src="designTab === 'front' ? cardTemplate.front_design : cardTemplate.back_design"
                            :alt="cardTemplate.name"
                            class="max-h-[420px] w-full cursor-zoom-in object-contain transition duration-200 group-hover:opacity-95"
                        />
                        <span
                            class="absolute bottom-2 right-2 flex items-center gap-1.5 rounded-full bg-slate-900/80 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-white opacity-0 shadow-lg backdrop-blur transition group-hover:opacity-100"
                        >
                            <ZoomIn class="h-3.5 w-3.5" />
                            Zoom in
                        </span>
                    </div>

                    <div class="mt-3 flex items-center justify-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg px-4 py-1.5 text-xs font-bold transition"
                            :class="designTab === 'front' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            @click="designTab = 'front'"
                        >
                            FRONT
                        </button>
                        <button
                            v-if="cardTemplate.back_design"
                            type="button"
                            class="rounded-lg px-4 py-1.5 text-xs font-bold transition"
                            :class="designTab === 'back' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            @click="designTab = 'back'"
                        >
                            BACK
                        </button>
                    </div>
                    <p class="mt-2 text-center text-xs text-slate-400">Click the card to zoom in on the design.</p>
                </div>

                <div
                    v-else
                    class="mt-3 flex flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-8 text-center"
                >
                    <CreditCard class="h-8 w-8 text-slate-300" stroke-width="1.5" />
                    <div>
                        <p class="text-sm font-semibold text-slate-600">{{ cardInfo.data?.guest?.full_name }}</p>
                        <p class="mt-1 text-xs text-slate-400">
                            This card has no template design. Verify using the details below.
                        </p>
                    </div>
                </div>
            </div>

            <dl class="mt-6 space-y-3.5 text-sm">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <User class="h-4 w-4 text-slate-400" />
                        Name
                    </dt>
                    <dd class="text-right font-semibold text-slate-900">
                        {{ cardInfo.data?.guest?.full_name }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <Phone class="h-4 w-4 text-slate-400" />
                        Phone Number
                    </dt>
                    <dd class="text-right font-medium text-slate-900">
                        {{ cardInfo.data?.guest?.phone || '-' }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <CreditCard class="h-4 w-4 text-slate-400" />
                        Card Number
                    </dt>
                    <dd class="font-mono font-bold text-slate-900">
                        {{ cardInfo.data?.card?.card_number }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <Layers class="h-4 w-4 text-slate-400" />
                        Card Type
                    </dt>
                    <dd class="font-semibold text-slate-900">
                        {{ cardInfo.data?.card?.card_type_label }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <Users class="h-4 w-4 text-slate-400" />
                        Number of People Allowed
                    </dt>
                    <dd class="font-semibold text-slate-900">
                        {{ cardInfo.data?.card?.allowed_people }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <UserCheck class="h-4 w-4 text-slate-400" />
                        Entered
                    </dt>
                    <dd class="font-semibold" :class="cardInfo.data?.card?.used_people >= cardInfo.data?.card?.allowed_people ? 'text-rose-600' : 'text-slate-900'">
                        {{ cardInfo.data?.card?.used_people }}/{{ cardInfo.data?.card?.allowed_people }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-3">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <ShieldCheck class="h-4 w-4 text-slate-400" />
                        Status
                    </dt>
                    <dd class="font-semibold" :class="cardInfo.data?.card?.status === 'active' ? 'text-emerald-600' : 'text-rose-600'">
                        {{ cardInfo.data?.card?.status === 'active' ? '✓ VALID' : cardInfo.data?.card?.status_label }}
                    </dd>
                </div>
                <div v-if="cardInfo.data?.card?.event_name || cardInfo.data?.card?.venue" class="flex items-start justify-between gap-4 pt-1">
                    <dt class="flex items-center gap-2 text-slate-500">
                        <CalendarRange class="h-4 w-4 text-slate-400" />
                        Event
                    </dt>
                    <dd class="text-right">
                        <p class="font-semibold text-slate-900">{{ cardInfo.data?.card?.event_name }}</p>
                        <p v-if="cardInfo.data?.card?.event_date || cardInfo.data?.card?.event_time" class="mt-0.5 text-xs text-slate-500">
                            {{ formatEventWhen(cardInfo.data?.card) }}
                        </p>
                        <p v-if="cardInfo.data?.card?.venue" class="mt-0.5 text-xs text-slate-500">
                            {{ cardInfo.data?.card?.venue }}
                        </p>
                    </dd>
                </div>
            </dl>

            <div
                v-if="!cardInfo.success"
                class="mt-5 flex items-start gap-2 rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 ring-1 ring-inset ring-rose-600/10"
            >
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                {{ cardInfo.message }}
            </div>

            <div v-if="cardInfo.success" class="mt-6">
                <div v-if="remainingPeople >= 2" class="mb-4 rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200/60">
                    <label class="label">Number of people entering</label>
                    <div class="flex items-center justify-center gap-4">
                        <button
                            type="button"
                            class="btn btn-secondary px-3! py-2!"
                            :disabled="confirming || numberOfGuests <= 1"
                            @click="numberOfGuests--"
                        >
                            <Minus class="h-4 w-4" />
                        </button>
                        <span class="min-w-12 text-center text-2xl font-bold text-slate-900">
                            {{ numberOfGuests }}
                        </span>
                        <button
                            type="button"
                            class="btn btn-secondary px-3! py-2!"
                            :disabled="confirming || numberOfGuests >= maxGuests"
                            @click="numberOfGuests++"
                        >
                            <Plus class="h-4 w-4" />
                        </button>
                    </div>
                    <p class="mt-2 text-center text-xs text-slate-400">
                        The card allows {{ remainingPeople }} more people to enter (maximum {{ maxGuests }}).
                    </p>
                </div>

                <button
                    type="button"
                    class="btn btn-primary w-full py-4! text-base"
                    :disabled="confirming"
                    @click="confirmCard"
                >
                    <span
                        v-if="confirming"
                        class="h-5 w-5 animate-spin rounded-full border-2 border-white/40 border-t-white"
                    ></span>
                    <template v-else>
                        <ShieldCheck class="h-5 w-5" />
                    </template>
                    CONFIRM ENTRY
                </button>
            </div>

            <button
                type="button"
                class="btn btn-secondary mt-3 w-full"
                :disabled="confirming"
                @click="resetAll"
            >
                <ArrowLeft class="h-4 w-4" />
                Go Back
            </button>
        </div>

        <EntryResult :result="finalResult" @reset="resetAll" />

        <Teleport to="body">
            <div
                v-if="showCardLightbox"
                class="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-4 bg-slate-950/90 p-4 backdrop-blur-sm"
                @click="showCardLightbox = false"
            >
                <button
                    type="button"
                    class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-inset ring-white/20 transition hover:bg-white/20"
                >
                    <X class="h-5 w-5" />
                </button>
                <img
                    :src="designTab === 'front' ? cardTemplate?.front_design : cardTemplate?.back_design"
                    :alt="cardTemplate?.name"
                    class="max-h-[80vh] w-auto max-w-full rounded-xl object-contain shadow-2xl"
                    @click.stop
                />
                <p class="rounded-full bg-white/10 px-4 py-1.5 text-xs font-bold uppercase tracking-widest text-white">
                    {{ cardTemplate?.name }} · {{ designTab === 'front' ? 'FRONT' : 'BACK' }}
                </p>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { AlertCircle, ArrowLeft, CalendarRange, CheckCircle2, ChevronRight, CreditCard, Info, Layers, Minus, Phone, Plus, ScanLine, Search, ShieldCheck, User, UserCheck, Users, X, ZoomIn } from '@lucide/vue';
import QRScanner from '../components/QRScanner.vue';
import EntryResult from '../components/EntryResult.vue';
import { cardsService } from '../services/cards';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const searchTerm = ref('');
const results = ref([]);
const searching = ref(false);
const verifying = ref(false);
const confirming = ref(false);
const showScanner = ref(false);
const noResults = ref(false);
const gateName = ref(localStorage.getItem('gate_name') || 'Main Gate');

function saveGate() {
    localStorage.setItem('gate_name', gateName.value.trim() || 'Main Gate');
}

const cardInfo = ref(null);
const finalResult = ref(null);
const numberOfGuests = ref(1);
const verificationMethod = ref('card_number');
const designTab = ref('front');
const showCardLightbox = ref(false);

let searchTimer = null;

const cardTemplate = computed(() => cardInfo.value?.data?.card?.template || null);

const remainingPeople = computed(() => {
    const card = cardInfo.value?.data?.card;
    if (!card) return 0;
    return Math.max(0, (card.allowed_people || 0) - (card.used_people || 0));
});

const maxGuests = computed(() => Math.min(2, remainingPeople.value));

function formatEventWhen(card) {
    const parts = [];
    if (card?.event_date) {
        parts.push(new Date(card.event_date + 'T00:00:00').toLocaleDateString('en-US', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }));
    }
    if (card?.event_time) parts.push(card.event_time);
    return parts.join(' · ');
}

function usageColor(card) {
    if (card.used_people >= card.allowed_people) return 'text-rose-600';
    if (card.used_people > 0) return 'text-amber-600';
    return 'text-emerald-600';
}

function statusColor(status) {
    if (status === 'active') return 'text-emerald-600';
    if (status === 'cancelled') return 'text-rose-600';
    if (status === 'expired') return 'text-amber-600';
    return 'text-slate-500';
}

function onSearch() {
    noResults.value = false;
    clearTimeout(searchTimer);

    const term = searchTerm.value.trim();
    if (term.length < 2) {
        results.value = [];
        return;
    }

    searchTimer = setTimeout(async () => {
        searching.value = true;
        try {
            results.value = await searchCards(term);
            noResults.value = results.value.length === 0;
        } catch (error) {
            toast.error('Failed to search. Try again.');
        } finally {
            searching.value = false;
        }
    }, 400);
}

async function searchCards(term) {
    return cardsService.search(term);
}

async function selectCard(card) {
    verifying.value = true;
    verificationMethod.value = 'card_number';
    try {
        cardInfo.value = await cardsService.verify({ card_number: card.card_number });
        resetCount();
    } catch (error) {
        cardInfo.value = error.response?.data || {
            success: false,
            message: 'Failed to verify the card.',
        };
    } finally {
        verifying.value = false;
    }
}

async function handleDecoded(token) {
    showScanner.value = true;
    verificationMethod.value = 'qr';
    try {
        cardInfo.value = await cardsService.verify({ qr_token: token });
        resetCount();
    } catch (error) {
        cardInfo.value = error.response?.data || {
            success: false,
            message: 'Failed to verify the card.',
        };
    }
}

function resetCount() {
    numberOfGuests.value = 1;
    designTab.value = 'front';
    showCardLightbox.value = false;
}

function onScannerError() {
    toast.warning('Camera could not open. Please use the name or card number search.');
}

async function confirmCard() {
    if (!cardInfo.value?.data?.card) return;

    confirming.value = true;
    const card = cardInfo.value.data.card;

    try {
        const payload = { card_number: card.card_number };
        payload.gate_name = gateName.value.trim() || 'Main Gate';
        payload.verification_method = verificationMethod.value;
        payload.number_of_guests = Math.min(numberOfGuests.value, maxGuests.value) || 1;
        const response = await cardsService.confirmEntry(payload);
        finalResult.value = response;
        cardInfo.value = null;
        if (response.success && response.data?.entry) {
            const entryParts = [`Entry attempt: #${response.data.entry.entry_number}`];
            if (response.data.entry.gate_name) entryParts.push(response.data.entry.gate_name);
            toast.success(entryParts.join(' · '));
        }
    } catch (error) {
        const data = error.response?.data;
        if (data) {
            finalResult.value = data;
            cardInfo.value = null;
        } else {
            toast.error('An error occurred. Try again.');
        }
    } finally {
        confirming.value = false;
    }
}

function resetAll() {
    cardInfo.value = null;
    finalResult.value = null;
    results.value = [];
    searchTerm.value = '';
    showScanner.value = false;
    noResults.value = false;
    numberOfGuests.value = 1;
    designTab.value = 'front';
    showCardLightbox.value = false;
}
</script>
