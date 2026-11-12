import api from './api';

/**
 * Saved invitations: one record per guest+card holding the phone number,
 * event details, invitation message and reminder message.
 *
 * The legacy /invitation-campaigns endpoints are still exported at the
 * bottom so the existing bulk-campaign callers keep working untouched.
 */
export const invitationsService = {
    /* ---------- Saved invitations (new) ---------- */

    async list(params) {
        const { data } = await api.get('/invitations', { params });
        return data;
    },

    async summary(params) {
        const { data } = await api.get('/invitations/summary', { params });
        return data.data;
    },

    async formOptions(params) {
        const { data } = await api.get('/invitations/form-options', { params });
        return data.data;
    },

    async report(params) {
        const { data } = await api.get('/invitations/report', { params });
        return data.data;
    },

    async downloadReport(params, kind = 'all') {
        const { data } = await api.get('/invitations/report/download', {
            params: { ...params, kind },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `invitation-${kind}-report.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    },

    async show(id) {
        const { data } = await api.get(`/invitations/${id}`);
        return data.data;
    },

    async create(payload) {
        const { data } = await api.post('/invitations', payload);
        return data;
    },

    async update(id, payload) {
        const { data } = await api.put(`/invitations/${id}`, payload);
        return data;
    },

    async remove(id) {
        const { data } = await api.delete(`/invitations/${id}`);
        return data;
    },

    /** Sends the saved invitation message via the existing NextSMS integration. */
    async sendSms(id) {
        const { data } = await api.post(`/invitations/${id}/send-sms`);
        return data;
    },

    /** Sends the reminder. Pass an edited message to override the saved one. */
    async sendReminder(id, message) {
        const { data } = await api.post(`/invitations/${id}/send-reminder`, { message });
        return data;
    },

    async bulkSendSms(ids) {
        const { data } = await api.post('/invitations/bulk-send-sms', { ids });
        return data;
    },

    /** Records a manual WhatsApp share (the share itself is browser-side). */
    async markWhatsAppShared(id) {
        const { data } = await api.post(`/invitations/${id}/whatsapp-shared`);
        return data;
    },

    /* ---------- Legacy bulk campaign (unchanged) ---------- */

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

    async clearHistory() {
        const { data } = await api.delete('/invitation-campaigns/sends');
        return data;
    },
};
