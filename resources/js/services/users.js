import api from './api';

export const usersService = {
    async index(params = {}) {
        const { data } = await api.get('/users', { params });
        return data.data;
    },

    async store(payload) {
        const { data } = await api.post('/users', payload);
        return data;
    },

    async update(userId, payload) {
        const { data } = await api.put(`/users/${userId}`, payload);
        return data;
    },

    // DELETE deactivates rather than deletes, so entry history survives.
    async deactivate(userId) {
        const { data } = await api.delete(`/users/${userId}`);
        return data;
    },

    async activate(userId) {
        const { data } = await api.post(`/users/${userId}/activate`);
        return data;
    },

    async resetPassword(userId, password, passwordConfirmation) {
        const { data } = await api.post(`/users/${userId}/reset-password`, {
            password,
            password_confirmation: passwordConfirmation,
        });
        return data;
    },
};
