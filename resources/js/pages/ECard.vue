<template>
    <div class="relative min-h-screen overflow-hidden bg-onyx-950 px-4 py-8">
        <!-- Warm gold light behind the card, so the gold on the card has
             something to sit in rather than floating on flat black. -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -left-28 -top-24 h-80 w-80 rounded-full bg-gold-500/12 blur-3xl"></div>
            <div class="absolute -bottom-32 -right-20 h-[26rem] w-[26rem] rounded-full bg-gold-700/12 blur-3xl"></div>
        </div>

        <div class="relative mx-auto max-w-sm">
            <div v-if="loading" class="flex flex-col items-center justify-center py-24 text-gold-200">
                <span class="mb-4 h-10 w-10 animate-spin rounded-full border-4 border-gold-400/25 border-t-gold-300"></span>
                <p class="text-sm text-gold-200/70">Loading your E-Card...</p>
            </div>

            <div v-else-if="error" class="animate-scale-in rounded-3xl bg-onyx-900 p-10 text-center ring-1 ring-gold-600/30">
                <span class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-rose-500/15 text-rose-400 ring-1 ring-inset ring-rose-500/30">
                    <XCircle class="h-9 w-9" />
                </span>
                <h1 class="font-serif text-2xl font-semibold text-gold-200">Card Unavailable</h1>
                <p class="mt-2 text-sm text-gold-200/60">{{ error }}</p>
            </div>

            <div v-else-if="data" class="animate-slide-up space-y-4">
                <!-- ============================ THE CARD ============================ -->
                <div
                    class="relative rounded-[30px] bg-gradient-to-b from-onyx-800 via-onyx-900 to-onyx-950 p-[10px] shadow-[0_28px_70px_-20px_rgba(0,0,0,0.9)]"
                >
                    <div class="rounded-[22px] border border-gold-500/35 bg-onyx-900/60 px-6 pb-7 pt-6">
                        <!-- a second, thinner rule just inside the first, the way a
                             printed card carries two gold borders -->
                        <div class="pointer-events-none absolute inset-[10px] rounded-[22px] border border-gold-500/15"></div>

                        <div class="relative flex items-start justify-between">
                            <div>
                                <p class="text-[9px] font-semibold uppercase tracking-[0.35em] text-gold-500">
                                    E-Card
                                </p>
                                <p class="mt-1 font-serif text-sm italic text-gold-300/80">
                                    {{ data.card.card_type === 'single' ? 'Single' : 'Double' }}
                                </p>
                            </div>
                            <span
                                class="rounded-full border border-gold-400/50 bg-gold-400/10 px-3.5 py-1.5 text-[10px] font-bold uppercase tracking-[0.15em] text-gold-300"
                            >
                                {{ data.card.card_type_label }}
                            </span>
                        </div>

                        <Ornament class="mt-5" />

                        <div class="mt-5 text-center">
                            <h1
                                class="font-serif text-[30px] font-semibold leading-tight text-transparent"
                                style="background-image: linear-gradient(100deg, #f9ecc4 0%, #e9c86a 28%, #fff6dc 50%, #e9c86a 72%, #f3dc97 100%); -webkit-background-clip: text; background-clip: text;"
                            >
                                {{ data.guest.full_name }}
                            </h1>

                            <!-- The guest's phone and email are deliberately not
                                 shown here. The card is a keepsake that gets
                                 photographed and passed around at the wedding, and
                                 a number printed on it is the one detail nobody
                                 should have to read off someone else's screen. The
                                 name is what the card exists to say. Contact
                                 details, if the admin wants them shown at all,
                                 belong further down the page, not on the face of
                                 the card. -->
                            <p class="mt-4 font-serif text-base italic tracking-wide text-gold-300">
                                Officially invited
                            </p>
                        </div>

                        <Ornament class="mt-5" />

                        <p class="mt-5 text-center font-mono text-xl font-bold tracking-[0.22em] text-gold-400">
                            {{ data.card.card_number }}
                        </p>

                        <div class="mt-6 grid grid-cols-2 gap-3">
                            <div class="rounded-2xl border border-gold-500/20 bg-gold-400/[0.06] p-3.5 text-center">
                                <p class="text-[9px] uppercase tracking-[0.18em] text-gold-500">Number of People</p>
                                <p class="mt-1 font-serif text-2xl font-semibold text-gold-200">{{ data.card.allowed_people }}</p>
                            </div>
                            <div class="rounded-2xl border border-gold-500/20 bg-gold-400/[0.06] p-3.5 text-center">
                                <p class="text-[9px] uppercase tracking-[0.18em] text-gold-500">Status</p>
                                <p class="mt-1 font-serif text-2xl font-semibold text-gold-200">{{ data.card.status_label }}</p>
                            </div>
                        </div>

                        <p class="mt-5 text-center font-serif text-sm italic leading-relaxed text-gold-200/70">
                            Please present this card at the gate when entering.
                        </p>

                        <!-- ---------------------- THE QR CODE ----------------------
                             Centred, on plain white with a gold rule around it. The
                             white is not decoration: a QR code needs a light quiet
                             zone behind its dark modules or a phone camera at the
                             gate will not read it. -->
                        <div class="mt-6 flex flex-col items-center">
                            <div
                                class="rounded-2xl bg-white p-3.5 shadow-[0_0_0_1px_rgba(233,200,106,0.5),0_16px_36px_-14px_rgba(0,0,0,0.85)]"
                            >
                                <QRCodeCanvas :value="cardUrl" :size="136" />
                            </div>
                            <p class="mt-3 text-[9px] font-semibold uppercase tracking-[0.3em] text-gold-500/80">
                                Scan at the gate
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ============================ VERIFIED ============================ -->
                <div
                    class="flex items-center justify-center gap-2 rounded-2xl border border-gold-500/25 bg-gold-400/[0.07] px-4 py-3 text-center text-sm font-bold text-gold-300"
                >
                    <ShieldCheck class="h-5 w-5 text-gold-400" />
                    Verified: TRUE
                </div>

                <!-- ======================== INVITATION ART ======================== -->
                <div v-if="template" class="overflow-hidden rounded-3xl bg-onyx-900 ring-1 ring-gold-600/25">
                    <div class="flex items-center justify-between gap-3 border-b border-gold-600/20 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate font-serif text-base font-semibold text-gold-200">{{ template.name }}</p>
                            <p class="text-xs text-gold-200/45">Invitation design</p>
                        </div>
                        <div class="flex shrink-0 rounded-lg border border-gold-600/25 bg-gold-400/[0.06] p-0.5">
                            <button
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                                :class="designTab === 'front' ? 'bg-gold-400/20 text-gold-200' : 'text-gold-200/55 hover:text-gold-200'"
                                @click="designTab = 'front'"
                            >
                                Front
                            </button>
                            <button
                                v-if="template.back_design"
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                                :class="designTab === 'back' ? 'bg-gold-400/20 text-gold-200' : 'text-gold-200/55 hover:text-gold-200'"
                                @click="designTab = 'back'"
                            >
                                Back
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <img
                            :src="designTab === 'front' ? template.front_design : template.back_design"
                            :alt="template.name"
                            class="w-full object-contain"
                        />
                    </div>
                </div>

                <!-- ========================= GUEST DETAILS ========================= -->
                <div class="space-y-4 rounded-3xl bg-onyx-900/70 p-5 ring-1 ring-gold-600/20">
                    <div class="flex items-center justify-between">
                        <p class="text-[9px] font-semibold uppercase tracking-[0.25em] text-gold-500">
                            Guest Details
                        </p>
                        <span
                            class="rounded-full border border-gold-500/30 bg-gold-400/10 px-2.5 py-0.5 text-[10px] font-bold text-gold-300"
                        >
                            &#10003; Verified
                        </span>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center gap-3">
                            <User class="h-4 w-4 shrink-0 text-gold-500" />
                            <div>
                                <p class="text-[9px] uppercase tracking-[0.18em] text-gold-500/80">Name</p>
                                <p class="text-sm font-bold text-gold-100">{{ data.guest.full_name }}</p>
                            </div>
                        </div>
                        <div v-if="data.guest.phone" class="flex items-center gap-3">
                            <Phone class="h-4 w-4 shrink-0 text-gold-500" />
                            <div>
                                <p class="text-[9px] uppercase tracking-[0.18em] text-gold-500/80">Phone</p>
                                <p class="text-sm font-bold text-gold-100">{{ data.guest.phone }}</p>
                            </div>
                        </div>
                        <div v-if="data.guest.email" class="flex items-center gap-3">
                            <Mail class="h-4 w-4 shrink-0 text-gold-500" />
                            <div>
                                <p class="text-[9px] uppercase tracking-[0.18em] text-gold-500/80">Email</p>
                                <p class="text-sm font-bold text-gold-100">{{ data.guest.email }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================= EVENT DETAILS ========================= -->
                <div
                    v-if="hasEvent"
                    class="rounded-3xl bg-onyx-900/70 p-5 ring-1 ring-gold-600/20"
                >
                    <p class="text-[9px] font-semibold uppercase tracking-[0.25em] text-gold-500">
                        Event Details
                    </p>
                    <p v-if="data.card.event_name" class="mt-2 font-serif text-xl font-semibold text-gold-200">
                        {{ data.card.event_name }}
                    </p>
                    <div class="mt-2 space-y-1 text-sm text-gold-100/75">
                        <p v-if="data.card.event_date || data.card.event_time" class="inline-flex items-center gap-1.5">
                            <Calendar class="h-3.5 w-3.5 text-gold-500" />
                            {{ formatEventWhen }}
                        </p>
                        <p v-if="data.card.venue" class="inline-flex items-center gap-1.5">
                            <MapPin class="h-3.5 w-3.5 text-gold-500" />
                            {{ data.card.venue }}
                        </p>
                        <div v-if="data.card.table_number || data.card.seat_number" class="flex flex-wrap gap-2 pt-1">
                            <span
                                v-if="data.card.table_number"
                                class="rounded-full border border-gold-500/25 bg-gold-400/10 px-2.5 py-0.5 text-xs font-semibold text-gold-200"
                            >
                                Table {{ data.card.table_number }}
                            </span>
                            <span
                                v-if="data.card.seat_number"
                                class="rounded-full border border-gold-500/25 bg-gold-400/10 px-2.5 py-0.5 text-xs font-semibold text-gold-200"
                            >
                                Seat {{ data.card.seat_number }}
                            </span>
                        </div>
                        <p
                            v-if="data.card.special_message"
                            class="mt-2 rounded-xl border-l-2 border-gold-500/60 bg-gold-400/[0.07] px-3 py-2.5 font-serif text-sm italic leading-relaxed text-gold-200/85"
                        >
                            {{ data.card.special_message }}
                        </p>
                    </div>
                </div>

                <!-- ============================== SHARE ============================== -->
                <div class="flex gap-2">
                    <a
                        v-if="waShareUrl"
                        :href="waShareUrl"
                        target="_blank"
                        rel="noopener"
                        class="flex flex-1 items-center justify-center gap-2 rounded-2xl border border-gold-500/30 bg-gold-400/[0.08] px-4 py-3 text-sm font-bold text-gold-200 transition hover:bg-gold-400/15"
                    >
                        <Share2 class="h-4 w-4 text-gold-400" /> Share WhatsApp
                    </a>
                    <a
                        v-if="smsShareUrl"
                        :href="smsShareUrl"
                        class="flex flex-1 items-center justify-center gap-2 rounded-2xl border border-gold-500/30 bg-gold-400/[0.08] px-4 py-3 text-sm font-bold text-gold-200 transition hover:bg-gold-400/15"
                    >
                        <Smartphone class="h-4 w-4 text-gold-400" /> Share SMS
                    </a>
                </div>

                <!-- ============================= STATUS ============================= -->
                <div
                    class="flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-center text-sm font-medium"
                    :class="statusClass"
                >
                    <span
                        class="relative flex h-2 w-2"
                        v-if="data.card.status === 'active'"
                    >
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-gold-400 opacity-60"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-gold-300"></span>
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
import Ornament from '../components/Ornament.vue';
import QRCodeCanvas from '../components/QRCodeCanvas.vue';
import api from '../services/api';

const route = useRoute();
const data = ref(null);
const loading = ref(true);
const error = ref('');
const designTab = ref('front');

// The design hangs off the card, not off the top level of the reply. Reading it
// from data.template made the invitation artwork silently disappear, because
// there is no such key and v-if quietly hid the whole block.
const template = computed(() => data.value?.card?.template ?? null);

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

/**
 * The server sends event_time as a full time, so the guest would read
 * "12:00:00". A guest is not a clock, so show the half past, not the seconds.
 */
function formatTime(value) {
    if (!value) return '';
    const [h, m] = String(value).split(':');
    const hour = Number(h);
    if (Number.isNaN(hour)) return value;
    const suffix = hour >= 12 ? 'PM' : 'AM';
    const twelve = hour % 12 === 0 ? 12 : hour % 12;
    return `${twelve}:${m ?? '00'} ${suffix}`;
}

const formatEventWhen = computed(() => {
    const parts = [];
    if (data.value?.card?.event_date) {
        parts.push(new Date(data.value.card.event_date + 'T00:00:00').toLocaleDateString('en-US', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }));
    }
    if (data.value?.card?.event_time) parts.push(formatTime(data.value.card.event_time));
    return parts.join(' · ');
});

/*
 * The address the QR code on this card encodes.
 *
 * This must never be built from window.location. That value is simply whatever
 * address the current browser happens to be on: 127.0.0.1 on a laptop, or the
 * private 10.x address of this machine on the local network. A guest scanning
 * the card at the gate is holding a phone that can reach neither, so the QR
 * would resolve to nothing at all. The server is asked for the address instead,
 * because it is the only side that knows the public one.
 *
 * The address bar is kept only as a fallback for an older server that does not
 * send the field, so a half-updated backend still shows a card rather than an
 * empty square.
 */
const cardUrl = computed(() => data.value?.card?.card_url || window.location.href);

const guestPhone = computed(() => {
    if (!data.value?.guest?.phone) return '';
    return data.value.guest.phone.replace(/\D/g, '');
});

const waShareUrl = computed(() => {
    if (!guestPhone.value) return '';
    // Same reason as the QR code: whoever receives this forwarded message is
    // holding a phone that has never heard of 127.0.0.1, so the link that goes
    // into the message has to be the public one.
    const msg = `Hello ${data.value.guest.full_name}, here is my invitation card:\n${cardUrl.value}`;
    return `https://wa.me/${guestPhone.value}?text=${encodeURIComponent(msg)}`;
});

const smsShareUrl = computed(() => {
    if (!guestPhone.value) return '';
    const msg = `Hello ${data.value.guest.full_name}, here is my invitation card:\n${cardUrl.value}`;
    return `sms:${guestPhone.value}?body=${encodeURIComponent(msg)}`;
});

const statusClass = computed(() => {
    if (!data.value) return 'border border-gold-600/25 bg-gold-400/5 text-gold-200/50';
    return data.value.card.status === 'active'
        ? 'border border-gold-500/30 bg-gold-400/[0.08] text-gold-300'
        : 'border border-rose-500/25 bg-rose-500/10 text-rose-300';
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
