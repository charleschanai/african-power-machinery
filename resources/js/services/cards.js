import api from './api';
import { downloadCsv } from './download';

export const cardsService = {
    async search(term) {
        const { data } = await api.get('/guests/search', { params: { q: term } });
        return data.data;
    },

    async export(params = {}) {
        await downloadCsv('/cards/export', params);
    },

    async exportLinks(params = {}) {
        const { data } = await api.get('/cards/export-links', { params });
        return data.data;
    },

    async importTemplate() {
        await downloadCsv('/cards/import-template');
    },

    async importCards(file) {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await api.post('/cards/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return data;
    },

    async index(params = {}) {
        const { data } = await api.get('/cards', { params });
        return data.data;
    },

    async store(payload) {
        const { data } = await api.post('/cards', payload);
        return data;
    },

    async update(cardId, payload) {
        const { data } = await api.put(`/cards/${cardId}`, payload);
        return data;
    },

    async show(cardId) {
        const { data } = await api.get(`/cards/${cardId}`);
        return data.data;
    },

    async verify(payload) {
        const { data } = await api.post('/cards/verify', payload);
        return data;
    },

    async confirmEntry(payload) {
        const { data } = await api.post('/cards/confirm-entry', payload);
        return data;
    },

    async cancel(cardId) {
        const { data } = await api.post(`/cards/${cardId}/cancel`);
        return data;
    },

    async destroy(cardId) {
        const { data } = await api.delete(`/cards/${cardId}`);
        return data;
    },

    async sendEmail(cardId) {
        const { data } = await api.post(`/cards/${cardId}/send-email`);
        return data;
    },

    async sendEmails(cardIds) {
        const { data } = await api.post('/cards/send-emails', { card_ids: cardIds });
        return data;
    },

    async sendSms(cardId, message) {
        const { data } = await api.post(`/cards/${cardId}/send-sms`, { message });
        return data;
    },

    async sendSmsBulk(cardIds, message) {
        const { data } = await api.post('/cards/send-sms-bulk', { card_ids: cardIds, message });
        return data;
    },
};
