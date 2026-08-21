import api from './api';

export const invitationService = {
    async send(cardId, channel) {
        const { data } = await api.post(`/cards/${cardId}/send-invitation`, { channel });
        return data;
    },

    async sendMany(cardIds, channel) {
        const { data } = await api.post('/cards/send-invitations', { channel, card_ids: cardIds });
        return data;
    },
};
