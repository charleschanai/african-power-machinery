import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/Login.vue'),
        meta: { guest: true },
    },
    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: () => import('../pages/ForgotPassword.vue'),
        meta: { guest: true },
    },
    {
        path: '/reset-password',
        name: 'reset-password',
        component: () => import('../pages/ResetPassword.vue'),
        meta: { guest: true },
    },
    {
        path: '/e-card/:token',
        name: 'e-card',
        component: () => import('../pages/ECard.vue'),
        meta: { public: true },
    },
    {
        path: '/',
        component: () => import('../layouts/EntryLayout.vue'),
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'entry',
                component: () => import('../pages/Entry.vue'),
            },
        ],
    },
    {
        path: '/',
        component: () => import('../layouts/AdminLayout.vue'),
        meta: { requiresAuth: true, roles: ['admin'], requires2fa: true },
        children: [
            { path: '/dashboard', name: 'dashboard', component: () => import('../pages/Dashboard.vue') },
            { path: '/guests', name: 'guests', component: () => import('../pages/Guests.vue') },
            { path: '/guests/:id', name: 'guest-show', component: () => import('../pages/GuestShow.vue') },
            { path: '/cards', name: 'cards', component: () => import('../pages/Cards.vue') },
            { path: '/cards/:id', name: 'card-show', component: () => import('../pages/CardShow.vue') },
            { path: '/invitations', name: 'invitations', component: () => import('../pages/Invitations.vue') },
            { path: '/weekly-reminders', name: 'weekly-reminders', component: () => import('../pages/WeeklyReminders.vue') },
            { path: '/card-templates', name: 'card-templates', component: () => import('../pages/CardTemplates.vue') },
            { path: '/entry-logs', name: 'entry-logs', component: () => import('../pages/EntryLogs.vue') },
            { path: '/whatsapp/settings', name: 'whatsapp-settings', component: () => import('../pages/WhatsAppSettings.vue') },
            { path: '/whatsapp/faqs', name: 'whatsapp-faqs', component: () => import('../pages/WhatsAppFaqs.vue') },
            { path: '/whatsapp/conversations', name: 'whatsapp-conversations', component: () => import('../pages/WhatsAppConversations.vue') },
            { path: '/security', name: 'security', component: () => import('../pages/SecuritySettings.vue'), meta: { requires2fa: false } },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

// Prefetch lazy route chunks in the background so that sidebar navigation
// feels instant. This matters most in dev mode, where Vite compiles each
// page on demand (a cold transform takes seconds).
let prefetched = false;

export function prefetchRoutes() {
    if (prefetched) return;
    prefetched = true;

    // Skip the heavy QR-scanner pages; they are only opened on demand.
    const pages = import.meta.glob('../pages/*.vue', { eager: false });

    Object.entries(pages).forEach(([path, loader]) => {
        if (path.endsWith('/Entry.vue') || path.endsWith('/ECard.vue')) {
            return;
        }

        loader().catch(() => {});
    });
}

router.beforeEach((to) => {
    const auth = useAuthStore();

    if (to.meta.public) {
        return true;
    }

    if (to.meta.guest) {
        if (auth.isLoggedIn) {
            return { name: auth.isAdmin ? 'dashboard' : 'entry' };
        }
        return true;
    }

    if (to.meta.requiresAuth) {
        if (!auth.isLoggedIn) {
            return { name: 'login' };
        }

        if (to.meta.roles && !to.meta.roles.includes(auth.user?.role)) {
            return { name: 'entry' };
        }

        if (to.meta.requires2fa && auth.isAdmin && auth.user?.two_factor_enabled === false) {
            return { name: 'security' };
        }
    }

    return true;
});

export default router;
