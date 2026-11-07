import api from './api';

/**
 * System monitoring endpoints.
 *
 * All admin-only. The public /health probe deliberately has no wrapper here:
 * it is used by load balancers, not by the UI.
 */
export default {
    /** Everything the monitoring page needs, in one request. */
    index() {
        return api.get('/monitoring');
    },

    /** Health checks only — cheap enough to poll. */
    health() {
        return api.get('/health');
    },

    /**
     * Recent performance events.
     * @param {{hours?: number, metric?: string, level?: string, per_page?: number}} params
     */
    metrics(params = {}) {
        return api.get('/monitoring/metrics', { params });
    },

    metricNames() {
        return api.get('/monitoring/metric-names');
    },

    /** Run the checks now and raise any resulting alerts. */
    runChecks() {
        return api.post('/monitoring/check');
    },

    /** Delete metrics older than N days. */
    prune(days = 30) {
        return api.post('/monitoring/prune', { days });
    },
};
