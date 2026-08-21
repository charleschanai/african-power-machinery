<template>
    <AuthShell>
        <h2 class="text-lg font-bold tracking-tight text-slate-900">Set New Password</h2>
        <p class="mb-6 mt-1 text-sm text-slate-500">
            {{ form.email ? `For: ${form.email}` : 'Enter a new password for your account.' }}
        </p>

        <form v-if="!done" @submit.prevent="handleReset" novalidate>
            <div class="space-y-4">
                <div>
                    <label for="password" class="label">
                        <span class="inline-flex items-center gap-1.5">
                            <Lock class="h-4 w-4 text-slate-400" />
                            New Password
                        </span>
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="input"
                        placeholder="At least 8 characters, including letters and numbers"
                        autocomplete="new-password"
                        required
                    />
                </div>

                <div>
                    <label for="password_confirmation" class="label">
                        <span class="inline-flex items-center gap-1.5">
                            <Lock class="h-4 w-4 text-slate-400" />
                            Confirm Password
                        </span>
                    </label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        class="input"
                        placeholder="Repeat password"
                        autocomplete="new-password"
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
                        <KeyRound class="h-5 w-5" />
                        Change Password
                    </template>
                </button>
            </div>
        </form>

        <div v-else class="space-y-4">
            <div class="flex items-start gap-3 rounded-xl bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-600/10">
                <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
                <span>{{ successMessage }}</span>
            </div>

            <router-link to="/login" class="btn btn-primary w-full">
                <LogIn class="h-4 w-4" />
                Sign in to the System
            </router-link>
        </div>
    </AuthShell>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { AlertCircle, CheckCircle2, KeyRound, Lock, LogIn } from '@lucide/vue';
import AuthShell from '../components/AuthShell.vue';
import { passwordService } from '../services/password';

const route = useRoute();

const form = reactive({
    email: route.query.email || '',
    token: route.query.token || '',
    password: '',
    password_confirmation: '',
});

const loading = ref(false);
const done = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

async function handleReset() {
    errorMessage.value = '';
    loading.value = true;

    try {
        const response = await passwordService.reset({ ...form });
        successMessage.value = response.message;
        done.value = true;
    } catch (error) {
        const errors = error.response?.data?.errors;
        errorMessage.value = errors ? Object.values(errors).flat()[0] : error.response?.data?.message || 'Failed. Try again.';
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    if (!form.token) {
        errorMessage.value = 'This link is invalid. Please request a new one via "Forgot password?".';
    }
});
</script>
