#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# APP_KEY must be supplied by the environment.
#
# Generating one here meant every container restart produced a new key, which
# silently invalidated all sessions, all 2FA tokens cached in the database, and
# any encrypted column — with no error anywhere. Fail loudly instead.
# ---------------------------------------------------------------------------
if [ -z "$APP_KEY" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show'"
    echo "       and inject it as an environment variable (do not commit it)."
    exit 1
fi

php artisan package:discover --ansi >/dev/null 2>&1 || true
php artisan storage:link || true

php artisan migrate --force
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

php artisan serve --host=0.0.0.0 --port="${PORT:-8080}" &

# ---------------------------------------------------------------------------
# Queue worker.
#
# The --queue list is essential. Jobs are dispatched onto named queues:
#   InvitationCampaignService -> onQueue('whatsapp') / onQueue('sms')
#   ProcessScheduledSms      -> onQueue('sms')
# A worker started without --queue listens on the 'default' queue only, so
# every invitation and every scheduled SMS sits in the database forever while
# the dashboard reports it as "pending". Never remove this list.
# ---------------------------------------------------------------------------
QUEUE_LIST="${QUEUE_LIST:-default,whatsapp,sms}"

php artisan queue:work \
    --queue="$QUEUE_LIST" \
    --sleep=3 \
    --tries=3 \
    --backoff=30 \
    --timeout=120 \
    --max-time=3600 \
    --max-jobs=1000 \
    -v &

# ---------------------------------------------------------------------------
# Scheduler.
#
# Runs the scheduler only when this container is the designated scheduler
# (SCHEDULER_ENABLED=true). Multiple replicas otherwise race: four schedulers
# was previously enough to message a guest four times.
# ---------------------------------------------------------------------------
if [ "${SCHEDULER_ENABLED:-true}" = "true" ]; then
    while true; do
        php artisan schedule:run >/dev/null 2>&1 || true
        sleep 60
    done &
fi

wait
