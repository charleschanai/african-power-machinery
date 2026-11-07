<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Contribution Reminders</h1>
                <p class="page-subtitle">Send contribution reminders to any phone numbers.</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="card p-5">
                <h2 class="card-title">Message</h2>
                <p class="mt-0.5 mb-3 text-xs text-slate-400">Edit the reminder message before sending.</p>
                <textarea
                    v-model="message"
                    rows="14"
                    class="input font-mono! text-xs!"
                ></textarea>
                <p class="mt-2 text-xs text-slate-400">
                    {{ message.length }} characters ≈ {{ smsParts }} SMS per number.
                </p>
            </div>

            <div class="card p-5">
                <h2 class="card-title">Recipient Numbers</h2>
                <p class="mt-0.5 mb-3 text-xs text-slate-400">Enter numbers — one per line or separated by commas.</p>
                <textarea
                    v-model="numbersInput"
                    rows="8"
                    class="input font-mono! text-xs!"
                    placeholder="0712345678&#10;0754123456, 0685123456"
                ></textarea>

                <div class="mt-3 flex items-center justify-between">
                    <p class="text-sm text-slate-500">
                        Valid numbers: <span class="font-semibold">{{ validNumbers.length }}</span>
                        <span v-if="invalidCount > 0" class="text-red-500">(invalid: {{ invalidCount }})</span>
                    </p>
                    <p class="text-sm text-slate-500">
                        Total SMS: <span class="font-semibold">{{ totalSms }}</span>
                    </p>
                </div>

                <button
                    type="button"
                    class="btn btn-primary mt-4 w-full"
                    :disabled="sending || !validNumbers.length || !message.trim()"
                    @click="showSendConfirm = true"
                >
                    <Send class="h-4 w-4" />
                    Send Reminder ({{ validNumbers.length }} numbers)
                </button>

                <div v-if="lastResult" class="mt-4 rounded-lg border p-3" :class="lastResult.sent > 0 ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'">
                    <p class="text-sm font-semibold" :class="lastResult.sent > 0 ? 'text-emerald-700' : 'text-red-700'">
                        {{ lastResult.message }}
                    </p>
                    <ul v-if="lastResult.errors?.length" class="mt-2 space-y-1 text-xs text-red-600">
                        <li v-for="(err, i) in lastResult.errors" :key="i">
                            {{ err.phone }}: {{ err.error }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :open="showSendConfirm"
            title="Send Contribution Reminders?"
            :message="`This message will be sent to ${validNumbers.length} numbers. Each number will use ${smsParts} SMS (total: ${totalSms} SMS). Continue?`"
            confirm-label="Send Now"
            :loading="sending"
            @confirm="doSend"
            @close="showSendConfirm = false"
        />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Send } from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import api from '../services/api';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const defaultTemplate = `Habari! Nakukumbusha kumaliza au kuwasilisha ahadi yako ya mchango wa harusi ya ndugu yetu SULPIS RENAT MASAWE itakayofanyika tarehe 03/10/2026.

Wasilisha mchango wako kupitia:
Airtel - 0786499931
Mixx by Yas - 0713528030
NMB Bank - 20310000321
Jina la mpokeaji: Sulpis Renat Masawe

Kikao cha pili kitafanyika tarehe 30/08/2026, Msimbazi Ukombozi, saa tisa jioni.

Ahsante kwa ushirikiano wako!
Promoted by Dowil eCards Services`;

const message = ref(defaultTemplate);
const numbersInput = ref('');
const sending = ref(false);
const showSendConfirm = ref(false);
const lastResult = ref(null);

const numbersList = computed(() =>
    numbersInput.value
        .split(/[\n,;]+/)
        .map((n) => n.trim())
        .filter(Boolean),
);

const validNumbers = computed(() =>
    numbersList.value.filter((n) => /^0[67]\d{8}$/.test(n.replace(/\s/g, ''))),
);

const invalidCount = computed(() => numbersList.value.length - validNumbers.value.length);

const smsParts = computed(() => (message.value.length <= 160 ? 1 : Math.ceil(message.value.length / 153)));

const totalSms = computed(() => validNumbers.value.length * smsParts.value);

async function doSend() {
    sending.value = true;
    try {
        const { data } = await api.post('/michango/send', {
            message: message.value,
            numbers: validNumbers.value,
        });
        lastResult.value = data;
        toast[data.success ? 'success' : 'warning'](data.message);
        showSendConfirm.value = false;
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to send SMS.');
    } finally {
        sending.value = false;
    }
}
</script>
