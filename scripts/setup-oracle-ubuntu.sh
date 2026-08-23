#!/usr/bin/env bash
set -euo pipefail

DOMAIN="${ECARD_DOMAIN:-ecard.duckdns.org}"
LE_EMAIL="${ECARD_EMAIL:-you@example.com}"
REPO_URL="${ECARD_REPO_URL:-https://github.com/USERNAME/ecard-system.git}"

DB_NAME="ecard_system"
DB_USER="ecard_user"
DB_PASS="$(openssl rand -hex 16)"

APP_DIR="/var/www/ecard"

if [[ "$DOMAIN" == *"example"* || "$REPO_URL" == *"USERNAME"* || "$LE_EMAIL" == *"example.com"* ]]; then
    echo "Kwanza hariri mstari wa juu: DOMAIN, LE_EMAIL na REPO_URL ndani ya script hii."
    exit 1
fi

if [[ $EUID -ne 0 ]]; then
    echo "Endesha kama root: sudo bash $0"
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> [1/9] Kusakinisha packages..."
apt-get update -y
apt-get install -y \
    nginx git unzip curl openssl \
    php8.3-fpm php8.3-cli php8.3-mbstring php8.3-xml php8.3-gd \
    php8.3-curl php8.3-zip php8.3-mysql php8.3-intl \
    mysql-server \
    supervisor cron \
    certbot python3-certbot-nginx \
    iptables-persistent netfilter-persistent

if ! command -v node >/dev/null 2>&1; then
    echo "==> Kusakinisha Node.js 20..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "==> Kusakinisha Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> [2/9] Kufungua port 80/443 kwenye firewall ya VM..."
iptables -I INPUT 1 -p tcp --dport 80 -m state --state NEW -j ACCEPT
iptables -I INPUT 1 -p tcp --dport 443 -m state --state NEW -j ACCEPT
netfilter-persistent save
echo "   KUMBUKA: Fungua pia port 80 na 443 kwenye Security List ya VCN kwenye Oracle Console!"

echo "==> [3/9] Kuandaa database MySQL..."
systemctl enable --now mysql
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
echo "   Database password (ihifadhi): ${DB_PASS}"

echo "==> [4/9] Kupakua mradi kutoka ${REPO_URL}..."
if [ -d "${APP_DIR}/.git" ]; then
    git -C "$APP_DIR" fetch origin
    git -C "$APP_DIR" reset --hard origin/master
else
    git clone "$REPO_URL" "$APP_DIR"
fi

cd "$APP_DIR"

echo "==> [5/9] Kusanidi .env..."
ENVF="${APP_DIR}/.env"
if [ ! -f "$ENVF" ]; then
    cp .env.example "$ENVF"
fi
sed -i "s|^APP_NAME=.*|APP_NAME=E-Card System|" "$ENVF"
sed -i "s|^APP_ENV=.*|APP_ENV=production|" "$ENVF"
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" "$ENVF"
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" "$ENVF"
sed -i "s|^LOG_STACK=.*|LOG_STACK=daily|" "$ENVF"
sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" "$ENVF"
sed -i "s|^# DB_HOST=.*|DB_HOST=127.0.0.1|" "$ENVF"
sed -i "s|^# DB_PORT=.*|DB_PORT=3306|" "$ENVF"
sed -i "s|^# DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" "$ENVF"
sed -i "s|^# DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" "$ENVF"
sed -i "s|^# DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" "$ENVF"
sed -i "s|^WHATSAPP_QUEUE_ASYNC=.*|WHATSAPP_QUEUE_ASYNC=true|" "$ENVF"
grep -q "^MAIL_FROM_ADDRESS=\"your@gmail.com\"" "$ENVF" && echo "   TAHADHARI: Jaza MAIL_* na WHATSAPP_* na AI_API_KEY baadaye kwenye ${ENVF}" || true

if ! grep -q "^APP_KEY=base64" "$ENVF"; then
    php artisan key:generate --force
fi

echo "==> [6/9] Dependencies na build..."
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> [7/9] Permissions..."
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwx "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "==> [8/9] Nginx + SSL..."
cat > /etc/nginx/sites-available/ecard <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;
    index index.php;

    client_max_body_size 25M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/ecard /etc/nginx/sites-enabled/ecard
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

SERVER_IP=$(curl -s https://api.ipify.org || true)
DNS_IP=$(getent hosts "$DOMAIN" | awk '{print $1}' | head -n1 || true)
echo "   IP ya server: ${SERVER_IP:-haijugulikwa}"
echo "   IP ya DNS (${DOMAIN}): ${DNS_IP:-HAIPATIKANI}"

if [ -z "${DNS_IP}" ] || [ "$DNS_IP" != "$SERVER_IP" ]; then
    echo ""
    echo "   DNS ya ${DOMAIN} bado haiashiri IP hii ya server."
    echo "   Sasisha rekodi A kwenye DuckDNS kisha endesha tena hatua hii:"
    echo "     certbot --nginx -d ${DOMAIN} -m ${LE_EMAIL} --agree-tos --redirect --non-interactive"
    echo ""
else
    certbot --nginx -d "$DOMAIN" -m "$LE_EMAIL" --agree-tos --redirect --non-interactive
    systemctl status certbot.timer >/dev/null 2>&1 || true
fi

echo "==> [9/9] Queue worker (supervisor) na scheduler (cron)..."
cat > /etc/supervisor/conf.d/ecard-worker.conf <<SUPERVISOR
[program:ecard-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/queue-worker.log
stopwaitsecs=3600
SUPERVISOR
supervisorctl reread
supervisorctl update
supervisorctl start "ecard-worker:*" || true

cat > /etc/cron.d/ecard-scheduler <<CRON
* * * * * www-data cd ${APP_DIR} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/ecard-scheduler

echo ""
echo "=== IMEKAMILIKA ==="
echo "Site:            https://${DOMAIN} (health: https://${DOMAIN}/up)"
echo ".env yako:       ${ENVF}  (jaza WHATSAPP_*, AI_API_KEY, MAIL_*)"
echo "Database pass:   ${DB_PASS}"
echo "Baada ya kubadilisha .env endesha: cd ${APP_DIR} && php artisan config:clear && php artisan config:cache"
echo "Update ijayo:    sudo -u www-data git -C ${APP_DIR} pull && cd ${APP_DIR} && composer install --no-dev && npm ci && npm run build && php artisan migrate --force && php artisan config:cache"
