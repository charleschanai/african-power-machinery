import api from './api';

export const templatesService = {
    async index(params = {}) {
        const { data } = await api.get('/card-templates', { params });
        return data.data;
    },

    async show(id) {
        const { data } = await api.get(`/card-templates/${id}`);
        return data.data;
    },

    async store(payload) {
        const formData = new FormData();

        Object.entries(payload).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                formData.append(key, value);
            }
        });

        const { data } = await api.post('/card-templates', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return data;
    },

    async update(id, payload) {
        const formData = new FormData();

        Object.entries(payload).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                formData.append(key, value);
            }
        });

        formData.append('_method', 'PUT');

        const { data } = await api.post(`/card-templates/${id}`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return data;
    },

    async toggleStatus(id) {
        const { data } = await api.post(`/card-templates/${id}/toggle-status`);
        return data;
    },

    async destroy(id) {
        const { data } = await api.delete(`/card-templates/${id}`);
        return data;
    },
};
