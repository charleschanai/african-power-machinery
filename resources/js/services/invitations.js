import api from './api';

export const invitationsService = {
    async report() {
        const { data } = await api.get('/invitation-campaigns');
        return data;
    },

    async preview() {
        const { data } = await api.get('/invitation-campaigns/preview');
        return data;
    },

    async sends(params) {
        const { data } = await api.get('/invitation-campaigns/sends', { params });
        return data;
    },

    async sendAll() {
        const { data } = await api.post('/invitation-campaigns/send-all');
        return data;
    },

    async sendSmsOnly() {
        const { data } = await api.post('/invitation-campaigns/send-sms-only');
        return data;
    },

    async sendSmsScheduled(scheduledAt) {
        const { data } = await api.post('/invitation-campaigns/send-sms-scheduled', { scheduled_at: scheduledAt });
        return data;
    },

    async retryFailed(channel = null) {
        const { data } = await api.post('/invitation-campaigns/retry-failed', channel ? { channel } : {});
        return data;
    },
};
