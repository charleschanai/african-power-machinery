<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Security</h1>
            <p class="text-sm text-slate-500">Protect your account with two-factor authentication.</p>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <span class="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
        </div>

        <template v-else>
            <div
                v-if="required && !status.enabled"
                class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-600/20"
            >
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                <div>
                    <p class="text-sm font-bold text-amber-900">Two-factor authentication is required</p>
                    <p class="mt-0.5 text-xs text-amber-700">
                        As an administrator you must enable 2FA before you can access the rest of the system.
                        Your other pages are locked until you complete setup below.
                    </p>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <ShieldCheck class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="card-title">Two-Factor Authentication</h2>
                            <p class="text-xs text-slate-500">Add an extra layer of security to your account.</p>
                        </div>
                    </div>
                    <span
                        class="rounded-full px-3 py-1 text-xs font-bold"
                        :class="status.enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                    >
                        {{ status.enabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </div>

                <!-- Disabled: start setup -->
                <div v-if="!status.enabled && !setupData" class="p-5">
                    <p class="text-sm text-slate-600">
                        When enabled, you'll need to enter a 6-digit code every time you sign in. The code
                        is emailed to you automatically, and can also be generated with your authenticator app
                        (Google Authenticator, Authy, 1Password, etc.).
                    </p>

                    <form class="mt-4 flex max-w-md flex-col gap-3 sm:flex-row sm:items-center" @submit.prevent="startSetup">
                        <input
                            v-model="password"
                            type="password"
                            class="input flex-1"
                            placeholder="Confirm your current password"
                            autocomplete="current-password"
                            required
                        />
                        <button type="submit" class="btn btn-primary shrink-0" :disabled="busy || !password">
                            <span v-if="busy" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            <template v-else>
                                <KeyRound class="h-4 w-4" />
                                Enable 2FA
                            </template>
                        </button>
                    </form>
                    <p v-if="error" class="mt-2 text-sm text-rose-600">{{ error }}</p>
                </div>

                <!-- Pending setup: QR + recovery codes + confirm -->
                <div v-else-if="!status.enabled && setupData" class="grid gap-6 p-5 lg:grid-cols-2">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">1. Scan the QR code</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Open your authenticator app and scan the code, or enter the secret manually.
                            We've also emailed you a 6-digit code you can use instead.
                        </p>
                        <div class="mt-4 inline-block rounded-xl border border-slate-200 bg-white p-3">
                            <QRCodeCanvas :value="setupData.provisioning_uri" :size="200" />
                        </div>
                        <p class="mt-3 text-xs text-slate-500">Manual secret:</p>
                        <code class="mt-1 block break-all rounded-lg bg-slate-100 px-3 py-2 font-mono text-sm tracking-wider text-slate-800">
                            {{ setupData.secret }}
                        </code>
                    </div>

                    <div>
                        <h3 class="text-sm font-bold text-slate-900">2. Save your recovery codes</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            These are shown only once. Store them safely — they let you sign in if you lose your phone.
                        </p>
                        <ul class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                            <li
                                v-for="code in setupData.recovery_codes"
                                :key="code"
                                class="rounded-lg bg-slate-50 px-3 py-1.5 text-center font-mono text-sm font-semibold text-slate-700"
                            >
                                {{ code }}
                            </li>
                        </ul>
                    </div>

                    <div class="lg:col-span-2">
                        <h3 class="text-sm font-bold text-slate-900">3. Confirm the code</h3>
                        <p class="mt-1 text-xs text-slate-500">Enter the 6-digit code from your email or authenticator app.</p>
                        <form class="mt-3 flex max-w-md flex-col gap-3 sm:flex-row sm:items-center" @submit.prevent="confirmSetup">
                            <input
                                v-model="confirmCode"
                                type="text"
                                class="input flex-1 text-center font-mono text-lg tracking-[0.5em]!"
                                maxlength="6"
                                inputmode="numeric"
                                placeholder="______"
                                required
                            />
                            <button type="submit" class="btn btn-primary shrink-0" :disabled="busy || confirmCode.length !== 6">
                                <span v-if="busy" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                <template v-else>
                                    <BadgeCheck class="h-4 w-4" />
                                    Activate
                                </template>
                            </button>
                        </form>
                        <p v-if="error" class="mt-2 text-sm text-rose-600">{{ error }}</p>
                        <button
                            type="button"
                            class="mt-3 text-sm text-slate-500 underline-offset-2 hover:underline"
                            :disabled="busy"
                            @click="cancelSetup"
                        >
                            Cancel setup
                        </button>
                    </div>
                </div>

                <!-- Enabled: manage -->
                <div v-else class="p-5">
                    <div class="flex items-start gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-600/10">
                        <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0" />
                        <p>Two-factor authentication is active. You'll be asked for a code at every sign in.</p>
                    </div>

                    <div class="mt-5 grid max-w-lg gap-4 sm:grid-cols-2">
                        <button
                            type="button"
                            class="btn btn-secondary justify-center"
                            :disabled="busy"
                            @click="showRegenerateConfirm = true"
                        >
                            <RefreshCw class="h-4 w-4" />
                            Regenerate recovery codes
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger justify-center"
                            :disabled="busy"
                            @click="showDisableConfirm = true"
                        >
                            <ShieldOff class="h-4 w-4" />
                            Disable 2FA
                        </button>
                    </div>

                    <!-- Regenerate confirm -->
                    <div v-if="showRegenerateConfirm" class="mt-6 max-w-md rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <h3 class="text-sm font-bold text-amber-900">Regenerate recovery codes</h3>
                        <p class="mt-0.5 text-xs text-amber-700">Enter a current code or recovery code to confirm. Old codes stop working.</p>
                        <form class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center" @submit.prevent="regenerateCodes">
                            <input
                                v-model="regenerateCode"
                                type="text"
                                class="input flex-1 font-mono tracking-[0.3em]!"
                                maxlength="15"
                                placeholder="Code or recovery code"
                                required
                            />
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-secondary" :disabled="busy" @click="showRegenerateConfirm = false">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-primary" :disabled="busy || !regenerateCode">
                                    <span v-if="busy" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                    <template v-else>Regenerate</template>
                                </button>
                            </div>
                        </form>
                        <p v-if="error" class="mt-2 text-sm text-rose-700">{{ error }}</p>
                    </div>

                    <!-- New recovery codes (after regeneration) -->
                    <div v-if="newRecoveryCodes.length" class="mt-6 max-w-lg rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <h3 class="text-sm font-bold text-amber-900">New recovery codes</h3>
                        <p class="mt-0.5 text-xs text-amber-700">Save these now — the previous codes no longer work.</p>
                        <ul class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                            <li
                                v-for="code in newRecoveryCodes"
                                :key="code"
                                class="rounded-lg bg-white px-3 py-1.5 text-center font-mono text-sm font-semibold text-amber-800"
                            >
                                {{ code }}
                            </li>
                        </ul>
                    </div>

                    <!-- Disable confirm -->
                    <div v-if="showDisableConfirm" class="mt-6 max-w-md rounded-xl border border-rose-200 bg-rose-50 p-4">
                        <h3 class="text-sm font-bold text-rose-900">Disable two-factor authentication?</h3>
                        <p class="mt-0.5 text-xs text-rose-700">Enter a current code or recovery code to confirm.</p>
                        <form class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center" @submit.prevent="disableTwoFactor">
                            <input
                                v-model="disableCode"
                                type="text"
                                class="input flex-1 font-mono tracking-[0.3em]!"
                                maxlength="15"
                                placeholder="Code or recovery code"
                                required
                            />
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-secondary" :disabled="busy" @click="showDisableConfirm = false">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-danger" :disabled="busy || !disableCode">
                                    <span v-if="busy" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                    <template v-else>Confirm</template>
                                </button>
                            </div>
                        </form>
                        <p v-if="error" class="mt-2 text-sm text-rose-700">{{ error }}</p>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, BadgeCheck, KeyRound, RefreshCw, ShieldCheck, ShieldOff } from '@lucide/vue';
import QRCodeCanvas from '../components/QRCodeCanvas.vue';
import { authService } from '../services/auth';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';

const auth = useAuthStore();
const toast = useToastStore();

const loading = ref(true);
const busy = ref(false);
const status = ref({ enabled: false });
const setupData = ref(null);
const password = ref('');
const confirmCode = ref('');
const disableCode = ref('');
const regenerateCode = ref('');
const showDisableConfirm = ref(false);
const showRegenerateConfirm = ref(false);
const newRecoveryCodes = ref([]);
const error = ref('');

const required = computed(() => auth.isAdmin);

async function loadStatus() {
    loading.value = true;
    try {
        const data = await authService.twoFactorStatus();
        status.value = data.data;
        newRecoveryCodes.value = [];
        showDisableConfirm.value = false;
        showRegenerateConfirm.value = false;
        disableCode.value = '';
        regenerateCode.value = '';
        error.value = '';
    } catch {
        toast.error('Failed to load security status.');
    } finally {
        loading.value = false;
    }
}

async function startSetup() {
    error.value = '';
    busy.value = true;
    try {
        const data = await authService.twoFactorEnable(password.value);
        setupData.value = data.data;
        password.value = '';
    } catch (e) {
        error.value = e.response?.data?.errors?.password?.[0] || e.response?.data?.message || 'Could not enable 2FA.';
    } finally {
        busy.value = false;
    }
}

async function confirmSetup() {
    error.value = '';
    busy.value = true;
    try {
        await authService.twoFactorConfirm(confirmCode.value);
        toast.success('Two-factor authentication enabled.');
        setupData.value = null;
        confirmCode.value = '';
        await loadStatus();
        await auth.refreshMe();
    } catch (e) {
        error.value = e.response?.data?.errors?.code?.[0] || e.response?.data?.message || 'The code is incorrect.';
        confirmCode.value = '';
    } finally {
        busy.value = false;
    }
}

function cancelSetup() {
    setupData.value = null;
    confirmCode.value = '';
    password.value = '';
    error.value = '';
}

async function regenerateCodes() {
    error.value = '';
    busy.value = true;
    try {
        const data = await authService.twoFactorRegenerate(regenerateCode.value);
        newRecoveryCodes.value = data.data.recovery_codes;
        regenerateCode.value = '';
        showRegenerateConfirm.value = false;
        toast.success('New recovery codes generated.');
    } catch (e) {
        error.value = e.response?.data?.errors?.code?.[0] || e.response?.data?.message || 'Could not regenerate codes.';
    } finally {
        busy.value = false;
    }
}

async function disableTwoFactor() {
    error.value = '';
    busy.value = true;
    try {
        await authService.twoFactorDisable(disableCode.value);
        toast.success('Two-factor authentication disabled.');
        disableCode.value = '';
        showDisableConfirm.value = false;
        await loadStatus();
        await auth.refreshMe();
    } catch (e) {
        error.value = e.response?.data?.errors?.code?.[0] || e.response?.data?.message || 'Could not disable 2FA.';
    } finally {
        busy.value = false;
    }
}

onMounted(loadStatus);
</script>
