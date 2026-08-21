import api from './api';

export const dashboardService = {
    async statistics() {
        const { data } = await api.get('/dashboard/statistics');
        return data.data;
    },
};
