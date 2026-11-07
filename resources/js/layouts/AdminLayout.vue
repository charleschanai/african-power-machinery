<template>
    <div class="min-h-screen bg-slate-100 lg:flex">
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col overflow-hidden bg-slate-950 transition-transform duration-300 ease-in-out lg:static lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen }"
        >
            <div class="flex-1 overflow-y-auto">
                <div class="flex items-center gap-3 border-b border-white/10 px-5 py-5">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 shadow-lg shadow-emerald-900/40 ring-1 ring-white/20"
                    >
                        <CreditCard class="h-6 w-6 text-white" stroke-width="2" />
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-base font-bold tracking-tight text-white">eCard System</p>
                        <p class="truncate text-xs text-slate-400">Guest Entry Management</p>
                    </div>
                </div>

                <div class="px-3 pt-5">
                    <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-500">
                        Menu
                    </p>
                    <nav class="space-y-1">
                        <router-link
                            v-for="item in menu"
                            :key="item.to"
                            :to="item.to"
                            class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-400 transition-all duration-150 hover:bg-white/5 hover:text-white"
                            active-class="bg-emerald-500/10! text-emerald-400!"
                            exact-active-class=""
                        >
                            <component
                                :is="item.icon"
                                class="h-[18px] w-[18px] shrink-0 transition-colors duration-150"
                                :stroke-width="2"
                            />
                            <span>{{ item.label }}</span>
                            <span
                                v-if="item.badge"
                                class="ml-auto rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-bold text-emerald-400"
                            >
                                {{ item.badge }}
                            </span>
                        </router-link>
                    </nav>
                </div>

                <div class="mt-6 px-3 pt-5">
                    <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-500">
                        WhatsApp
                    </p>
                    <nav class="space-y-1">
                        <router-link
                            v-for="item in whatsappMenu"
                            :key="item.to"
                            :to="item.to"
                            class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-400 transition-all duration-150 hover:bg-white/5 hover:text-white"
                            active-class="bg-emerald-500/10! text-emerald-400!"
                            exact-active-class=""
                        >
                            <component
                                :is="item.icon"
                                class="h-[18px] w-[18px] shrink-0 transition-colors duration-150"
                                :stroke-width="2"
                            />
                            <span>{{ item.label }}</span>
                        </router-link>
                    </nav>
                </div>

                <div class="mt-6 px-3 pt-5">
                    <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-500">
                        More
                    </p>
                    <nav class="space-y-1">
                        <router-link
                            to="/"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-400 transition-all duration-150 hover:bg-white/5 hover:text-white"
                        >
                            <ScanLine class="h-[18px] w-[18px] shrink-0" stroke-width="2" />
                            <span>Entry Check</span>
                        </router-link>
                    </nav>
                </div>
            </div>

            <div class="shrink-0 border-t border-white/10 p-4">
                <div class="mb-3 flex items-center gap-3 rounded-xl bg-white/5 p-3 ring-1 ring-inset ring-white/10">
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 text-sm font-bold text-white"
                    >
                        {{ auth.user?.name?.charAt(0)?.toUpperCase() || 'U' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ auth.user?.name }}</p>
                        <p class="text-xs text-slate-400">{{ roleLabel }}</p>
                    </div>
                </div>
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-white/5 px-3 py-2 text-sm font-semibold text-slate-300 ring-1 ring-inset ring-white/10 transition hover:bg-white/10 hover:text-white"
                    @click="handleLogout"
                >
                    <LogOut class="h-4 w-4" stroke-width="2" />
                    Logout
                </button>
            </div>
        </aside>

        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-slate-950/60 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        ></div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/80 backdrop-blur-md">
                <div class="flex h-16 items-center justify-between gap-3 px-4 lg:px-8">
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 lg:hidden"
                            @click="sidebarOpen = !sidebarOpen"
                        >
                            <Menu class="h-5 w-5" />
                        </button>
                        <div class="min-w-0">
                            <h1 class="truncate text-sm font-bold tracking-tight text-slate-900">{{ currentTitle }}</h1>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div
                            class="flex items-center gap-2.5 rounded-full bg-slate-50 px-3.5 py-1.5 ring-1 ring-inset ring-slate-200 sm:px-4"
                        >
                            <Clock class="h-4 w-4 shrink-0 text-emerald-600" />
                            <div class="text-right">
                                <p class="whitespace-nowrap text-xs font-bold tracking-tight text-slate-900">{{ currentDate }}</p>
                                <p class="whitespace-nowrap text-[11px] font-semibold text-slate-500 tabular-nums">{{ currentTime }}</p>
                            </div>
                        </div>
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 text-sm font-bold text-white lg:hidden"
                        >
                            {{ auth.user?.name?.charAt(0)?.toUpperCase() || 'U' }}
                        </div>
                    </div>
                </div>
            </header>

            <div
                v-if="auth.isAdmin && auth.user?.two_factor_enabled === false"
                class="flex items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2.5 lg:px-8"
            >
                <p class="flex items-center gap-2 text-xs font-semibold text-amber-800">
                    <ShieldAlert class="h-4 w-4 shrink-0" />
                    Two-factor authentication is required to access the system.
                </p>
                <router-link
                    to="/security"
                    class="shrink-0 rounded-md bg-amber-500 px-2.5 py-1 text-xs font-bold text-white transition hover:bg-amber-600"
                >
                    Set up now
                </router-link>
            </div>

            <main class="flex-1 p-4 lg:p-8">
                <router-view />
            </main>
        </div>

        <ToastContainer />
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    Clock,
    CreditCard,
    LayoutDashboard,
    Users,
    LayoutTemplate,
    ClipboardList,
    LogOut,
    Menu,
    ScanLine,
    MessageCircle,
    MessagesSquare,
    HelpCircle,
    Send,
    ShieldCheck,
    ShieldAlert,
    HandCoins,
    UsersRound,
} from '@lucide/vue';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import ToastContainer from '../components/ToastContainer.vue';

const auth = useAuthStore();
const toast = useToastStore();
const router = useRouter();
const route = useRoute();

const sidebarOpen = ref(false);

const menu = [
    { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { to: '/guests', label: 'Guests', icon: Users },
    { to: '/cards', label: 'Cards', icon: CreditCard },
    { to: '/invitations', label: 'Invitations', icon: Send },
    { to: '/card-management/double', label: 'Double Card Management', icon: CreditCard },
    { to: '/card-management/single', label: 'Single Card Management', icon: CreditCard },
    { to: '/michango', label: 'Contributions', icon: HandCoins },
    { to: '/card-templates', label: 'Card Templates', icon: LayoutTemplate },
    { to: '/entry-logs', label: 'Entry Logs', icon: ClipboardList },
    { to: '/users', label: 'Users', icon: UsersRound },
    { to: '/security', label: 'Security', icon: ShieldCheck },
];

const whatsappMenu = [
    { to: '/whatsapp/settings', label: 'WhatsApp Settings', icon: MessageCircle },
    { to: '/whatsapp/conversations', label: 'Conversations', icon: MessagesSquare },
    { to: '/whatsapp/faqs', label: 'FAQs', icon: HelpCircle },
];

const titles = {
    dashboard: 'Dashboard',
    guests: 'Guests',
    'guest-show': 'Guest Details',
    cards: 'Cards',
    'card-show': 'Card Details',
    invitations: 'Invitations',
    'weekly-reminders': 'Weekly Reminders',
    michango: 'Contribution Reminders',
    'card-templates': 'Card Templates',
    'entry-logs': 'Entry Logs',
    users: 'Users',
    monitoring: 'System Monitoring',
    'whatsapp-settings': 'WhatsApp Settings',
    'whatsapp-faqs': 'FAQs',
    'whatsapp-conversations': 'WhatsApp Conversations',
    security: 'Security',
};

const currentTitle = computed(() => titles[route.name] || 'E-Card System');

const now = ref(new Date());
let clockTimer = null;

const currentDate = computed(() =>
    now.value.toLocaleDateString('en-US', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }),
);

const currentTime = computed(() =>
    now.value.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }),
);

onMounted(() => {
    clockTimer = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    clearInterval(clockTimer);
});

const roleLabel = computed(() => (auth.isAdmin ? 'Admin' : 'Attendant'));

watch(
    () => route.fullPath,
    () => {
        sidebarOpen.value = false;
    },
);

async function handleLogout() {
    await auth.logout();
    toast.success('You have been logged out.');
    router.push({ name: 'login' });
}
</script>
