<template>
    <div class="relative min-h-screen overflow-hidden bg-slate-950 px-4 py-10">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-emerald-500/15 blur-3xl"></div>
            <div class="absolute -bottom-40 -right-24 h-[28rem] w-[28rem] rounded-full bg-teal-500/15 blur-3xl"></div>
            <div
                class="absolute inset-0 opacity-[0.04]"
                style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 28px 28px;"
            ></div>
        </div>

        <div class="relative mx-auto max-w-sm">
            <div v-if="loading" class="flex flex-col items-center justify-center py-24 text-white">
                <span class="mb-4 h-10 w-10 animate-spin rounded-full border-4 border-emerald-400/30 border-t-emerald-400"></span>
                <p class="text-sm text-slate-300">Loading your E-Card...</p>
            </div>

            <div v-else-if="error" class="animate-scale-in rounded-3xl bg-white/5 p-10 text-center text-white ring-1 ring-white/10 backdrop-blur">
                <span class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-rose-500/15 text-rose-400 ring-1 ring-inset ring-rose-500/30">
                    <XCircle class="h-9 w-9" />
                </span>
                <h1 class="text-lg font-bold">Card Unavailable</h1>
                <p class="mt-2 text-sm text-slate-400">{{ error }}</p>
            </div>

            <div v-else-if="data" class="animate-slide-up space-y-4">
                <div
                    class="relative overflow-hidden rounded-[28px] bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-800 shadow-2xl shadow-emerald-950/50 ring-1 ring-white/10"
                >
                    <div class="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
                    <div class="pointer-events-none absolute -bottom-20 -left-12 h-56 w-56 rounded-full bg-teal-300/20 blur-3xl"></div>

                    <div class="relative px-6 pb-6 pt-7 text-white">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.25em] text-emerald-200">
                                    E-Card
                                </p>
                                <p class="mt-0.5 text-xs font-medium text-emerald-100">
                                    {{ data.card.card_type === 'single' ? 'Single' : 'Double' }}
                                </p>
                            </div>
                            <span class="rounded-full bg-white/15 px-3.5 py-1.5 text-xs font-bold tracking-wider backdrop-blur">
                                {{ data.card.card_type_label }}
                            </span>
                        </div>

                        <div class="mt-7 text-center">
                            <p class="text-2xl font-extrabold tracking-wide">{{ data.guest.full_name }}</p>
                            <p v-if="data.guest.phone" class="mt-1 text-xs text-emerald-200">
                                📞 {{ data.guest.phone }}
                            </p>
                            <p v-if="data.guest.email" class="mt-0.5 text-xs text-emerald-200">
                                ✉️ {{ data.guest.email }}
                            </p>
                            <p class="mt-1.5 text-sm text-emerald-100">
                                Officially invited
                            </p>
                        </div>

                        <p class="mt-5 text-center font-mono text-xl font-bold tracking-[0.15em]">
                            {{ data.card.card_number }}
                        </p>

                        <div class="mt-6 grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-white/10 p-3 text-center backdrop-blur">
                                <p class="text-[10px] uppercase tracking-widest text-emerald-200">Number of People</p>
                                <p class="mt-0.5 text-lg font-bold">{{ data.card.allowed_people }}</p>
                            </div>
                            <div class="rounded-2xl bg-white/10 p-3 text-center backdrop-blur">
                                <p class="text-[10px] uppercase tracking-widest text-emerald-200">Status</p>
                                <p class="mt-0.5 text-lg font-bold">{{ data.card.status_label }}</p>
                            </div>
                        </div>

                        <p class="mt-5 text-center text-xs leading-relaxed text-emerald-100">
                            Please present this card at the gate when entering.
                        </p>

                        <div class="mt-4 flex justify-end">
                            <div class="rounded-2xl bg-white p-2 shadow-lg">
                                <QRCodeCanvas :value="cardUrl" :size="90" />
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center justify-center gap-2 rounded-2xl bg-emerald-500/15 px-4 py-3 text-center text-sm font-bold text-emerald-300 ring-1 ring-inset ring-emerald-400/30"
                >
                    <ShieldCheck class="h-5 w-5 text-emerald-400" />
                    Verified: TRUE
                </div>

                <div v-if="data.template" class="overflow-hidden rounded-3xl bg-white/5 ring-1 ring-white/10 backdrop-blur">
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-white">{{ data.template.name }}</p>
                            <p class="text-xs text-slate-400">Invitation design</p>
                        </div>
                        <div class="flex shrink-0 rounded-lg bg-white/10 p-0.5">
                            <button
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                                :class="designTab === 'front' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-300 hover:text-white'"
                                @click="designTab = 'front'"
                            >
                                Front
                            </button>
                            <button
                                v-if="data.template.back_design"
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                                :class="designTab === 'back' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-300 hover:text-white'"
                                @click="designTab = 'back'"
                            >
                                Back
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <img
                            :src="designTab === 'front' ? data.template.front_design : data.template.back_design"
                            :alt="data.template.name"
                            class="w-full object-contain"
                        />
                        <div class="absolute bottom-3 right-3 rounded-xl bg-white/90 p-1.5 shadow-lg backdrop-blur">
                            <QRCodeCanvas :value="cardUrl" :size="60" />
                        </div>
                    </div>
                </div>

                <div class="space-y-4 rounded-3xl bg-white/5 p-5 text-white ring-1 ring-white/10 backdrop-blur">
                    <div class="flex items-center justify-between">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-emerald-300">
                            Guest Details
                        </p>
                        <span class="rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[10px] font-bold text-emerald-300 ring-1 ring-inset ring-emerald-400/30">
                            ✅ Verified
                        </span>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center gap-3">
                            <User class="h-4 w-4 shrink-0 text-emerald-300" />
                            <div>
                                <p class="text-[10px] uppercase tracking-widest text-emerald-300">Name</p>
                                <p class="text-sm font-bold">{{ data.guest.full_name }}</p>
                            </div>
                        </div>
                        <div v-if="data.guest.phone" class="flex items-center gap-3">
                            <Phone class="h-4 w-4 shrink-0 text-emerald-300" />
                            <div>
                                <p class="text-[10px] uppercase tracking-widest text-emerald-300">Phone</p>
                                <p class="text-sm font-bold">{{ data.guest.phone }}</p>
                            </div>
                        </div>
                        <div v-if="data.guest.email" class="flex items-center gap-3">
                            <Mail class="h-4 w-4 shrink-0 text-emerald-300" />
                            <div>
                                <p class="text-[10px] uppercase tracking-widest text-emerald-300">Email</p>
                                <p class="text-sm font-bold">{{ data.guest.email }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="hasEvent"
                    class="rounded-3xl bg-white/5 p-5 text-white ring-1 ring-white/10 backdrop-blur"
                >
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-emerald-300">
                        Event Details
                    </p>
                    <p v-if="data.card.event_name" class="mt-2 text-base font-bold">{{ data.card.event_name }}</p>
                    <div class="mt-2 space-y-1 text-sm text-slate-200">
                        <p v-if="data.card.event_date || data.card.event_time" class="inline-flex items-center gap-1.5">
                            <Calendar class="h-3.5 w-3.5 text-emerald-300" />
                            {{ formatEventWhen }}
                        </p>
                        <p v-if="data.card.venue" class="inline-flex items-center gap-1.5">
                            <MapPin class="h-3.5 w-3.5 text-emerald-300" />
                            {{ data.card.venue }}
                        </p>
                        <div v-if="data.card.table_number || data.card.seat_number" class="flex flex-wrap gap-2 pt-1">
                            <span v-if="data.card.table_number" class="rounded-full bg-emerald-400/15 px-2.5 py-0.5 text-xs font-semibold text-emerald-200 ring-1 ring-inset ring-emerald-400/30">
                                Table {{ data.card.table_number }}
                            </span>
                            <span v-if="data.card.seat_number" class="rounded-full bg-teal-400/15 px-2.5 py-0.5 text-xs font-semibold text-teal-200 ring-1 ring-inset ring-teal-400/30">
                                Seat {{ data.card.seat_number }}
                            </span>
                        </div>
                        <p v-if="data.card.special_message" class="mt-2 rounded-xl bg-amber-400/10 px-3 py-2 text-xs leading-relaxed text-amber-200 ring-1 ring-inset ring-amber-400/20">
                            {{ data.card.special_message }}
                        </p>
                    </div>
                </div>

                <div class="flex gap-2">
                    <a v-if="waShareUrl" :href="waShareUrl" target="_blank" class="btn btn-secondary flex-1">
                        <Share2 class="h-4 w-4" /> Share WhatsApp
                    </a>
                    <a v-if="smsShareUrl" :href="smsShareUrl" class="btn btn-secondary flex-1">
                        <Smartphone class="h-4 w-4" /> Share SMS
                    </a>
                </div>

                <div
                    class="flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-center text-sm font-medium"
                    :class="statusClass"
                >
                    <span
                        class="relative flex h-2 w-2"
                        v-if="data.card.status === 'active'"
                    >
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </span>
                    Card Status: {{ data.card.status_label }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Calendar, Mail, MapPin, Phone, ShieldCheck, Share2, Smartphone, User, XCircle } from '@lucide/vue';
import QRCodeCanvas from '../components/QRCodeCanvas.vue';
import api from '../services/api';

const route = useRoute();
const data = ref(null);
const loading = ref(true);
const error = ref('');
const designTab = ref('front');

const hasEvent = computed(() =>
    Boolean(
        data.value?.card?.event_name ||
        data.value?.card?.event_date ||
        data.value?.card?.event_time ||
        data.value?.card?.venue ||
        data.value?.card?.table_number ||
        data.value?.card?.seat_number ||
        data.value?.card?.special_message,
    ),
);

const formatEventWhen = computed(() => {
    const parts = [];
    if (data.value?.card?.event_date) {
        parts.push(new Date(data.value.card.event_date + 'T00:00:00').toLocaleDateString('en-US', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }));
    }
    if (data.value?.card?.event_time) parts.push(data.value.card.event_time);
    return parts.join(' · ');
});

const cardUrl = computed(() => window.location.href);

const guestPhone = computed(() => {
    if (!data.value?.guest?.phone) return '';
    return data.value.guest.phone.replace(/\D/g, '');
});

const waShareUrl = computed(() => {
    if (!guestPhone.value) return '';
    const msg = `Hello ${data.value.guest.full_name}, here is my invitation card:\n${window.location.href}`;
    return `https://wa.me/${guestPhone.value}?text=${encodeURIComponent(msg)}`;
});

const smsShareUrl = computed(() => {
    if (!guestPhone.value) return '';
    const msg = `Hello ${data.value.guest.full_name}, here is my invitation card:\n${window.location.href}`;
    return `sms:${guestPhone.value}?body=${encodeURIComponent(msg)}`;
});

const statusClass = computed(() => {
    if (!data.value) return 'bg-white/10 text-slate-300';
    return data.value.card.status === 'active'
        ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-inset ring-emerald-400/30'
        : 'bg-rose-500/15 text-rose-300 ring-1 ring-inset ring-rose-400/30';
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data: response } = await api.get(`/public/card/${route.params.token}`);
        data.value = response.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'This card is unavailable.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
