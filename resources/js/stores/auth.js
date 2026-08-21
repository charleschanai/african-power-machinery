import { defineStore } from 'pinia';
import { authService } from '../services/auth';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: (() => { try { return JSON.parse(localStorage.getItem('user') || 'null'); } catch { localStorage.removeItem('user'); return null; } })(),
        token: localStorage.getItem('token') || null,
        twoFactorToken: null,
    }),

    getters: {
        isLoggedIn: (state) => Boolean(state.token),
        isAdmin: (state) => state.user?.role === 'admin',
        isStaff: (state) => state.user?.role === 'staff',
    },

    actions: {
        async login(email, password) {
            const data = await authService.login(email, password);

            if (data.two_factor_required) {
                this.twoFactorToken = data.data.two_factor_token;
                return { twoFactorRequired: true };
            }

            this.setSession(data.data.token, data.data.user);
            return { twoFactorRequired: false };
        },

        async verifyTwoFactor(code) {
            const data = await authService.twoFactorVerify(this.twoFactorToken, code);
            this.setSession(data.data.token, data.data.user);
            this.twoFactorToken = null;
        },

        setSession(token, user) {
            this.token = token;
            this.user = user;
            localStorage.setItem('token', token);
            localStorage.setItem('user', JSON.stringify(user));
        },

        async refreshMe() {
            const data = await authService.me();
            if (data.data.user) {
                this.user = data.data.user;
                localStorage.setItem('user', JSON.stringify(this.user));
            }
            return this.user;
        },

        async logout() {
            try {
                await authService.logout();
            } catch {
                // ignore network errors on logout
            }
            this.token = null;
            this.user = null;
            this.twoFactorToken = null;
            localStorage.removeItem('token');
            localStorage.removeItem('user');
        },
    },
});
