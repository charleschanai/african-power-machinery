import api from './api';

export const whatsappService = {
    async sendCard(cardId) {
        const { data } = await api.post(`/whatsapp/send-card/${cardId}`);
        return data;
    },

    async getStatus() {
        const { data } = await api.get('/whatsapp/status');
        return data;
    },

    async getSettings() {
        const { data } = await api.get('/whatsapp/settings');
        return data;
    },

    async updateSettings(payload) {
        const { data } = await api.put('/whatsapp/settings', payload);
        return data;
    },

    async getEventDetails() {
        const { data } = await api.get('/whatsapp/event-details');
        return data;
    },

    async updateEventDetails(payload) {
        const { data } = await api.put('/whatsapp/event-details', payload);
        return data;
    },

    async getFaqs(params) {
        const { data } = await api.get('/whatsapp/faqs', { params });
        return data;
    },

    async storeFaq(payload) {
        const { data } = await api.post('/whatsapp/faqs', payload);
        return data;
    },

    async updateFaq(id, payload) {
        const { data } = await api.put(`/whatsapp/faqs/${id}`, payload);
        return data;
    },

    async toggleFaq(id) {
        const { data } = await api.post(`/whatsapp/faqs/${id}/toggle-status`);
        return data;
    },

    async destroyFaq(id) {
        const { data } = await api.delete(`/whatsapp/faqs/${id}`);
        return data;
    },

    async getConversations(params) {
        const { data } = await api.get('/whatsapp/conversations', { params });
        return data;
    },

    async getConversation(id) {
        const { data } = await api.get(`/whatsapp/conversations/${id}`);
        return data;
    },

    async replyConversation(id, message) {
        const { data } = await api.post(`/whatsapp/conversations/${id}/reply`, { message });
        return data;
    },

    async takeOverConversation(id) {
        const { data } = await api.post(`/whatsapp/conversations/${id}/take-over`);
        return data;
    },

    async releaseConversation(id) {
        const { data } = await api.post(`/whatsapp/conversations/${id}/release`);
        return data;
    },

    async blockConversation(id) {
        const { data } = await api.post(`/whatsapp/conversations/${id}/block`);
        return data;
    },

    async unblockConversation(id) {
        const { data } = await api.post(`/whatsapp/conversations/${id}/unblock`);
        return data;
    },
};
