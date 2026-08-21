<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900">WhatsApp Settings</h1>
            <p class="text-sm text-slate-500">Configure the WhatsApp connection, AI and event details.</p>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="card flex items-center gap-3 p-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                        <MessageCircle class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">API Status</p>
                        <p class="truncate text-sm font-bold" :class="status.settings?.configured ? 'text-emerald-600' : 'text-rose-600'">
                            {{ status.settings?.configured ? 'Configured' : 'Not configured' }}
                        </p>
                    </div>
                </div>
                <div class="card flex items-center gap-3 p-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                        <Bot class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">AI Assistant</p>
                        <p class="truncate text-sm font-bold" :class="status.settings?.ai_enabled ? 'text-emerald-600' : 'text-slate-500'">
                            {{ status.settings?.ai_enabled ? 'Enabled' : 'Disabled' }}
                        </p>
                    </div>
                </div>
                <div class="card flex items-center gap-3 p-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                        <MessagesSquare class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">Conversations</p>
                        <p class="text-sm font-bold text-slate-900">{{ status.stats?.conversations || 0 }}</p>
                    </div>
                </div>
                <div class="card flex items-center gap-3 p-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-50 text-teal-600">
                        <ListChecks class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">Active FAQs</p>
                        <p class="text-sm font-bold text-slate-900">{{ status.stats?.active_faqs || 0 }}</p>
                    </div>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="flex border-b border-slate-200">
                    <button
                        type="button"
                        class="flex items-center gap-2 px-5 py-3 text-sm font-semibold transition"
                        :class="tab === 'settings' ? 'border-b-2 border-emerald-500 text-emerald-600' : 'text-slate-500 hover:text-slate-800'"
                        @click="tab = 'settings'"
                    >
                        <Settings2 class="h-4 w-4" />
                        Settings
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-2 px-5 py-3 text-sm font-semibold transition"
                        :class="tab === 'event' ? 'border-b-2 border-emerald-500 text-emerald-600' : 'text-slate-500 hover:text-slate-800'"
                        @click="tab = 'event'"
                    >
                        <CalendarHeart class="h-4 w-4" />
                        Event Details
                    </button>
                </div>

                <form v-if="tab === 'settings'" class="space-y-6 p-5" @submit.prevent="saveSettings">
                    <div>
                        <h2 class="card-title">WhatsApp Connection</h2>
                        <p class="mt-1 text-xs text-slate-500">These numbers come from the WhatsApp Cloud API.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="wa-phone" class="label">WhatsApp Business Number</label>
                                <input id="wa-phone" v-model="settingsForm.whatsapp_business_phone" type="text" class="input" placeholder="+255 712 345 678" />
                            </div>
                            <div>
                                <label for="wa-phone-id" class="label">Phone Number ID</label>
                                <input id="wa-phone-id" v-model="settingsForm.phone_number_id" type="text" class="input" placeholder="123456789" />
                            </div>
                            <div>
                                <label for="wa-ba-id" class="label">Business Account ID</label>
                                <input id="wa-ba-id" v-model="settingsForm.business_account_id" type="text" class="input" placeholder="987654321" />
                            </div>
                            <div>
                                <label for="wa-token" class="label">Webhook Verify Token</label>
                                <input id="wa-token" v-model="settingsForm.webhook_verify_token" type="text" class="input" placeholder="verification token" />
                            </div>
                        </div>
                        <div v-if="settings.webhook_url" class="mt-3 rounded-xl bg-slate-50 px-3 py-2.5 text-xs ring-1 ring-inset ring-slate-200">
                            <p class="font-semibold text-slate-600">Webhook URL (set it in the WhatsApp App):</p>
                            <code class="mt-1 block break-all text-emerald-700">{{ settings.webhook_url }}</code>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-5">
                        <h2 class="card-title">AI Assistant</h2>
                        <p class="mt-1 text-xs text-slate-500">The AI automatically answers guests' questions in Swahili.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="wa-organizer" class="label">Organizer Name</label>
                                <input id="wa-organizer" v-model="settingsForm.organizer_name" type="text" class="input" placeholder="e.g. Amina" />
                            </div>
                            <div>
                                <label for="wa-organizer-phone" class="label">Organizer Number</label>
                                <input id="wa-organizer-phone" v-model="settingsForm.organizer_phone" type="text" class="input" placeholder="+255 711 000 000" />
                            </div>
                            <div>
                                <label for="wa-language" class="label">Default Language</label>
                                <select id="wa-language" v-model="settingsForm.default_language" class="input">
                                    <option value="sw">Swahili</option>
                                    <option value="en">English</option>
                                </select>
                            </div>
                        </div>

                        <label class="mt-4 flex cursor-pointer items-center justify-between rounded-xl border border-slate-200 p-4 transition hover:border-emerald-400">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">Enable AI Assistant</p>
                                <p class="text-xs text-slate-500">Turn it off so guests are not answered automatically.</p>
                            </div>
                            <input v-model="settingsForm.ai_enabled" type="checkbox" class="h-5 w-5 rounded border-slate-300 text-emerald-600" />
                        </label>

                        <div class="mt-4 space-y-4">
                            <div>
                                <label for="wa-ai-disabled" class="label">AI Disabled Message</label>
                                <textarea id="wa-ai-disabled" v-model="settingsForm.ai_disabled_message" rows="2" class="input" placeholder="The AI assistant is currently unavailable..."></textarea>
                            </div>
                            <div>
                                <label for="wa-unknown" class="label">Unknown Guest Message</label>
                                <textarea id="wa-unknown" v-model="settingsForm.unknown_guest_message" rows="2" class="input"></textarea>
                            </div>
                            <div>
                                <label for="wa-no-info" class="label">No Information Available Message</label>
                                <textarea id="wa-no-info" v-model="settingsForm.no_info_message" rows="2" class="input"></textarea>
                            </div>
                        </div>
                    </div>

                    <p v-if="settingsError" class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10">
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ settingsError }}</span>
                    </p>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary" :disabled="savingSettings">
                            <span v-if="savingSettings" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            Save Settings
                        </button>
                    </div>
                </form>

                <form v-else class="space-y-6 p-5" @submit.prevent="saveEvent">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="ev-name" class="label">Event Name</label>
                            <input id="ev-name" v-model="eventForm.event_name" type="text" class="input" placeholder="e.g. Wedding Ceremony" />
                        </div>
                        <div>
                            <label for="ev-date" class="label">Date</label>
                            <input id="ev-date" v-model="eventForm.event_date" type="date" class="input" />
                        </div>
                        <div>
                            <label for="ev-time" class="label">Time</label>
                            <input id="ev-time" v-model="eventForm.event_time" type="text" class="input" placeholder="10:00 PM" />
                        </div>
                        <div>
                            <label for="ev-venue" class="label">Venue</label>
                            <input id="ev-venue" v-model="eventForm.venue" type="text" class="input" placeholder="e.g. Golden Hall, Dar es Salaam" />
                        </div>
                        <div>
                            <label for="ev-address" class="label">Venue Address</label>
                            <input id="ev-address" v-model="eventForm.venue_address" type="text" class="input" />
                        </div>
                        <div>
                            <label for="ev-maps" class="label">Google Maps URL</label>
                            <input id="ev-maps" v-model="eventForm.google_maps_url" type="url" class="input" placeholder="https://maps.google.com/..." />
                        </div>
                        <div>
                            <label for="ev-dress" class="label">Dress Code</label>
                            <input id="ev-dress" v-model="eventForm.dress_code" type="text" class="input" placeholder="e.g. Formal Wear" />
                        </div>
                        <div>
                            <label for="ev-contact-name" class="label">Contact Person Name</label>
                            <input id="ev-contact-name" v-model="eventForm.contact_person" type="text" class="input" />
                        </div>
                        <div>
                            <label for="ev-contact-number" class="label">Contact Number</label>
                            <input id="ev-contact-number" v-model="eventForm.contact_number" type="text" class="input" placeholder="+255 7XX XXX XXX" />
                        </div>
                        <div>
                            <label for="ev-rsvp" class="label">RSVP Link</label>
                            <input id="ev-rsvp" v-model="eventForm.rsvp_link" type="url" class="input" placeholder="https://..." />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="ev-parking" class="label">Parking Details</label>
                            <textarea id="ev-parking" v-model="eventForm.parking_information" rows="3" class="input"></textarea>
                        </div>
                        <div>
                            <label for="ev-accommodation" class="label">Accommodation Details</label>
                            <textarea id="ev-accommodation" v-model="eventForm.accommodation_information" rows="3" class="input"></textarea>
                        </div>
                        <div>
                            <label for="ev-program" class="label">Event Program</label>
                            <textarea id="ev-program" v-model="eventForm.event_program" rows="4" class="input"></textarea>
                        </div>
                        <div>
                            <label for="ev-gift" class="label">Gift Details</label>
                            <textarea id="ev-gift" v-model="eventForm.gift_information" rows="4" class="input"></textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="ev-instructions" class="label">Important Instructions</label>
                            <textarea id="ev-instructions" v-model="eventForm.important_instructions" rows="3" class="input"></textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="ev-other" class="label">Other Details</label>
                            <textarea id="ev-other" v-model="eventForm.other_information" rows="3" class="input"></textarea>
                        </div>
                    </div>

                    <p v-if="eventError" class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10">
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ eventError }}</span>
                    </p>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary" :disabled="savingEvent">
                            <span v-if="savingEvent" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            Save Event Details
                        </button>
                    </div>
                </form>
            </div>
        </template>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { AlertCircle, Bot, CalendarHeart, ListChecks, MessageCircle, MessagesSquare, Settings2 } from '@lucide/vue';
import { whatsappService } from '../services/whatsapp';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const loading = ref(true);
const tab = ref('settings');
const status = ref(null);
const settings = ref({});
const settingsForm = ref({});
const eventForm = ref({});
const savingSettings = ref(false);
const savingEvent = ref(false);
const settingsError = ref('');
const eventError = ref('');

const emptyEventForm = () => ({
    event_name: '',
    event_description: '',
    event_date: '',
    event_time: '',
    venue: '',
    venue_address: '',
    google_maps_url: '',
    dress_code: '',
    parking_information: '',
    accommodation_information: '',
    contact_number: '',
    contact_person: '',
    event_program: '',
    rsvp_link: '',
    rsvp_information: '',
    gift_information: '',
    important_instructions: '',
    other_information: '',
});

async function load() {
    loading.value = true;
    try {
        const res = await whatsappService.getStatus();
        status.value = res.data;
        settings.value = res.data.settings;
        settingsForm.value = {
            whatsapp_business_phone: res.data.settings.whatsapp_business_phone || '',
            phone_number_id: res.data.settings.phone_number_id || '',
            business_account_id: res.data.settings.business_account_id || '',
            webhook_verify_token: res.data.settings.webhook_verify_token || '',
            ai_enabled: Boolean(res.data.settings.ai_enabled),
            organizer_name: res.data.settings.organizer_name || '',
            organizer_phone: res.data.settings.organizer_phone || '',
            default_language: res.data.settings.default_language || 'sw',
            ai_disabled_message: res.data.settings.ai_disabled_message || '',
            unknown_guest_message: res.data.settings.unknown_guest_message || '',
            no_info_message: res.data.settings.no_info_message || '',
        };
        const ev = await whatsappService.getEventDetails();
        eventForm.value = { ...emptyEventForm(), ...ev.data, event_date: ev.data.event_date || '' };
    } catch {
        toast.error('Failed to fetch settings.');
    } finally {
        loading.value = false;
    }
}

async function saveSettings() {
    savingSettings.value = true;
    settingsError.value = '';
    try {
        await whatsappService.updateSettings(settingsForm.value);
        toast.success('Settings saved.');
        await load();
    } catch (e) {
        settingsError.value = e.response?.data?.message || 'Failed to save settings.';
    } finally {
        savingSettings.value = false;
    }
}

async function saveEvent() {
    savingEvent.value = true;
    eventError.value = '';
    try {
        await whatsappService.updateEventDetails(eventForm.value);
        toast.success('Event details saved.');
        await load();
    } catch (e) {
        eventError.value = e.response?.data?.message || 'Failed to save event details.';
    } finally {
        savingEvent.value = false;
    }
}

onMounted(load);
</script>
