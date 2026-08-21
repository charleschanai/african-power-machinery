<template>
    <AuthShell>
        <h2 class="text-lg font-bold tracking-tight text-slate-900">Forgot password?</h2>
        <p class="mb-6 mt-1 text-sm text-slate-500">
            Enter your email and we'll send you a link to reset your password.
        </p>

        <form v-if="!sent" @submit.prevent="handleForgot" novalidate>
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
                        <Send class="h-5 w-5" />
                        Send Link
                    </template>
                </button>
            </div>
        </form>

        <div v-else class="space-y-4">
            <div class="flex items-start gap-3 rounded-xl bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-600/10">
                <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                <span>{{ successMessage }}</span>
            </div>

            <p
                v-if="resetUrl"
                class="rounded-xl bg-sky-50 px-3 py-2.5 text-xs leading-relaxed text-sky-800 ring-1 ring-inset ring-sky-600/10"
            >
                Password reset link:
                <a :href="resetUrl" class="font-semibold text-sky-700 underline">open here</a>
            </p>

            <router-link to="/login" class="btn btn-secondary w-full">
                <ArrowLeft class="h-4 w-4" />
                Back to Sign in
            </router-link>
        </div>
    </AuthShell>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { AlertCircle, ArrowLeft, CheckCircle2, Mail, Send } from '@lucide/vue';
import AuthShell from '../components/AuthShell.vue';
import { passwordService } from '../services/password';

const form = reactive({ email: '' });
const loading = ref(false);
const sent = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const resetUrl = ref('');

async function handleForgot() {
    errorMessage.value = '';
    loading.value = true;

    try {
        const response = await passwordService.forgot(form.email);
        successMessage.value = response.message;
        resetUrl.value = response.data?.reset_url || '';
        sent.value = true;
    } catch (error) {
        const errors = error.response?.data?.errors;
        errorMessage.value = errors ? Object.values(errors).flat()[0] : error.response?.data?.message || 'Failed. Try again.';
    } finally {
        loading.value = false;
    }
}
</script>
