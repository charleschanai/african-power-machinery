<template>
    <AuthShell>
        <template v-if="!twoFactorStep">
            <h2 class="text-lg font-bold tracking-tight text-slate-900">Sign in to the system</h2>
            <p class="mb-6 mt-1 text-sm text-slate-500">Enter your sign-in details.</p>

            <form @submit.prevent="handleLogin" novalidate>
                <div class="space-y-4">
                    <div>
                        <label for="email" class="label">
                            <span class="inline-flex items-center gap-1.5">
                                <Mail class="h-4 w-4 text-slate-400" />
                                Email
                            </span>
                        </label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="input"
                            placeholder="you@example.com"
                            autocomplete="email"
                            required
                        />
                    </div>

                    <div>
                        <label for="password" class="label">
                            <span class="inline-flex items-center gap-1.5">
                                <Lock class="h-4 w-4 text-slate-400" />
                                Password
                            </span>
                        </label>
                        <div class="relative">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="input pr-11!"
                                placeholder="••••••••"
                                autocomplete="current-password"
                                required
                            />
                            <button
                                type="button"
                                class="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-md p-1 text-slate-400 transition hover:text-slate-600"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff v-if="showPassword" class="h-4 w-4" />
                                <Eye v-else class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <p
                        v-if="errorMessage"
                        class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ errorMessage }}</span>
                    </p>

                    <button
                        type="submit"
                        class="btn btn-primary w-full py-3! text-base"
                        :disabled="loading"
                    >
                        <span
                            v-if="loading"
                            class="h-5 w-5 animate-spin rounded-full border-2 border-white/40 border-t-white"
                        ></span>
                        <template v-else>
                            <LogIn class="h-5 w-5" />
                            {{ 'Sign in' }}
                        </template>
                    </button>
                </div>
            </form>

            <p class="mt-5 text-center text-sm text-slate-500">
                Forgot password?
                <router-link
                    to="/forgot-password"
                    class="font-semibold text-emerald-600 transition hover:text-emerald-700"
                >
                    Click here
                </router-link>
            </p>
        </template>

        <template v-else>
            <h2 class="text-lg font-bold tracking-tight text-slate-900">Two-factor authentication</h2>
            <p class="mb-6 mt-1 text-sm text-slate-500">
                Enter the 6-digit code we emailed you, or the one from your authenticator app.
            </p>

            <form @submit.prevent="handleTwoFactor" novalidate>
                <div class="space-y-4">
                    <div>
                        <label for="two-factor-code" class="label">
                            <span class="inline-flex items-center gap-1.5">
                                <KeyRound class="h-4 w-4 text-slate-400" />
                                Verification code
                            </span>
                        </label>
                        <input
                            id="two-factor-code"
                            ref="codeInput"
                            v-model="twoFactorCode"
                            type="text"
                            class="input text-center text-xl! tracking-[0.5em]! font-mono"
                            maxlength="6"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            autofocus
                            required
                        />
                    </div>

                    <p
                        v-if="errorMessage"
                        class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                    >
                        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ errorMessage }}</span>
                    </p>

                    <button
                        type="submit"
                        class="btn btn-primary w-full py-3! text-base"
                        :disabled="loading || twoFactorCode.length !== 6"
                    >
                        <span
                            v-if="loading"
                            class="h-5 w-5 animate-spin rounded-full border-2 border-white/40 border-t-white"
                        ></span>
                        <template v-else>
                            <KeyRound class="h-5 w-5" />
                            Verify
                        </template>
                    </button>

                    <button
                        type="button"
                        class="w-full py-2 text-center text-sm text-slate-500 transition hover:text-slate-700"
                        :disabled="loading"
                        @click="goBackToCredentials"
                    >
                        Back to sign in
                    </button>
                </div>
            </form>
        </template>
    </AuthShell>
</template>

<script setup>
import { nextTick, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { AlertCircle, Eye, EyeOff, KeyRound, Lock, LogIn, Mail } from '@lucide/vue';
import AuthShell from '../components/AuthShell.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

const form = reactive({ email: '', password: '' });
const loading = ref(false);
const errorMessage = ref('');
const showPassword = ref(false);

const twoFactorStep = ref(false);
const twoFactorCode = ref('');
const codeInput = ref(null);

async function handleLogin() {
    errorMessage.value = '';
    loading.value = true;

    try {
        const { twoFactorRequired } = await auth.login(form.email, form.password);

        if (twoFactorRequired) {
            twoFactorStep.value = true;
            twoFactorCode.value = '';
            errorMessage.value = '';
            await nextTick();
            codeInput.value?.focus();
            return;
        }

        router.push(auth.isAdmin ? { name: 'dashboard' } : { name: 'entry' });
    } catch (error) {
        errorMessage.value =
            error.response?.data?.message ||
            error.response?.data?.errors?.email?.[0] ||
            'Failed to sign in. Try again.';
    } finally {
        loading.value = false;
    }
}

async function handleTwoFactor() {
    if (twoFactorCode.value.length !== 6) return;

    errorMessage.value = '';
    loading.value = true;

    try {
        await auth.verifyTwoFactor(twoFactorCode.value);
        router.push(auth.isAdmin ? { name: 'dashboard' } : { name: 'entry' });
    } catch (error) {
        errorMessage.value =
            error.response?.data?.message ||
            error.response?.data?.errors?.code?.[0] ||
            'The verification code is incorrect.';
        twoFactorCode.value = '';
        await nextTick();
        codeInput.value?.focus();
    } finally {
        loading.value = false;
    }
}

function goBackToCredentials() {
    twoFactorStep.value = false;
    twoFactorCode.value = '';
    errorMessage.value = '';
    auth.twoFactorToken = null;
}
</script>
