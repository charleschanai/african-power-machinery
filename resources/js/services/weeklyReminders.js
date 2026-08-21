import api from './api';

export const weeklyRemindersService = {
    async history(params) {
        const { data } = await api.get('/weekly-reminders', { params });
        return data;
    },

    async weeks() {
        const { data } = await api.get('/weekly-reminders/weeks');
        return data;
    },

    async weekSummary(year, weekNumber) {
        const { data } = await api.get(`/weekly-reminders/${year}/${weekNumber}`);
        return data;
    },

    async sendNow() {
        const { data } = await api.post('/weekly-reminders/send-now');
        return data;
    },
};
