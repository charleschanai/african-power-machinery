import api from './api';

export const authService = {
    async login(email, password) {
        const { data } = await api.post('/login', { email, password });
        return data;
    },

    async logout() {
        const { data } = await api.post('/logout');
        return data;
    },

    async me() {
        const { data } = await api.get('/me');
        return data;
    },

    async twoFactorVerify(twoFactorToken, code) {
        const { data } = await api.post('/auth/two-factor/verify', { two_factor_token: twoFactorToken, code });
        return data;
    },

    async twoFactorStatus() {
        const { data } = await api.get('/auth/two-factor');
        return data;
    },

    async twoFactorEnable(password) {
        const { data } = await api.post('/auth/two-factor/enable', { password });
        return data;
    },

    async twoFactorConfirm(code) {
        const { data } = await api.post('/auth/two-factor/confirm', { code });
        return data;
    },

    async twoFactorDisable(code) {
        const { data } = await api.post('/auth/two-factor/disable', { code });
        return data;
    },

    async twoFactorRegenerate(code) {
        const { data } = await api.post('/auth/two-factor/regenerate', { code });
        return data;
    },
};
