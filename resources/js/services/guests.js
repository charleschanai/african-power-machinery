import api from './api';
import { downloadCsv } from './download';

export const guestsService = {
    async index(params = {}) {
        const { data } = await api.get('/guests', { params });
        return data.data;
    },

    async export(params = {}) {
        await downloadCsv('/guests/export', params);
    },

    async search(query) {
        const { data } = await api.get('/guests/search', { params: { q: query } });
        return data.data;
    },

    async store(payload) {
        const { data } = await api.post('/guests', payload);
        return data;
    },

    async show(guestId) {
        const { data } = await api.get(`/guests/${guestId}`);
        return data.data;
    },

    async update(guestId, payload) {
        const { data } = await api.put(`/guests/${guestId}`, payload);
        return data;
    },

    async destroy(guestId) {
        const { data } = await api.delete(`/guests/${guestId}`);
        return data;
    },
};
