import api from './api';
import { downloadCsv } from './download';

export const entryLogsService = {
    async index(params = {}) {
        const { data } = await api.get('/entry-logs', { params });
        return data.data;
    },

    async export(params = {}) {
        await downloadCsv('/entry-logs/export', params);
    },

    async remove(id) {
        const { data } = await api.delete(`/entry-logs/${id}`);
        return data;
    },
};
