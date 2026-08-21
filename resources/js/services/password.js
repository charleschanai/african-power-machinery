import api from './api';

export const passwordService = {
    async forgot(email) {
        const { data } = await api.post('/password/forgot', { email });
        return data;
    },

    async reset(payload) {
        const { data } = await api.post('/password/reset', payload);
        return data;
    },
};
