<template>
    <div class="min-h-screen bg-slate-100">
        <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur-md">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-3">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 shadow-lg shadow-emerald-600/25"
                    >
                        <ScanLine class="h-5 w-5 text-white" stroke-width="2.2" />
                    </div>
                    <div>
                        <p class="text-sm font-bold tracking-tight text-slate-900">Entry Check</p>
                        <p class="text-xs text-slate-500">{{ auth.user?.name }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <router-link
                        v-if="auth.isAdmin"
                        to="/dashboard"
                        class="btn btn-secondary px-3! py-2! text-xs"
                    >
                        <LayoutDashboard class="h-4 w-4" />
                        Dashboard
                    </router-link>
                    <button
                        type="button"
                        class="btn btn-secondary px-3! py-2! text-xs"
                        @click="handleLogout"
                    >
                        <LogOut class="h-4 w-4" />
                        Logout
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6 pb-24">
            <router-view />
        </main>

        <ToastContainer />
    </div>
</template>

<script setup>
import { useRouter } from 'vue-router';
import { LayoutDashboard, LogOut, ScanLine } from '@lucide/vue';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import ToastContainer from '../components/ToastContainer.vue';

const auth = useAuthStore();
const toast = useToastStore();
const router = useRouter();

async function handleLogout() {
    await auth.logout();
    toast.success('You have been logged out.');
    router.push({ name: 'login' });
}
</script>
