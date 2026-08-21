# Mwongozo wa Ufungaji kwa Production (Deployment)

Mwongozo huu unaeleza hatua za kuweka mfumo wa **E-Card + WhatsApp AI** kwenye server ya production.

---

## 1. Mahitaji ya Server

- PHP **8.2+** (pamoja na extensions: `pdo_mysql`, `mbstring`, `gd`, `openssl`, `curl`, `zip`)
- MySQL **5.7+** / MariaDB **10.4+**
- Node.js **20+** (kwa kujenga frontend; hahitajiki kwenye server baada ya build)
- Composer
- Nginx / Apache + **SSL (HTTPS)** — **lazima** kwa WhatsApp webhook
- (Kama `WHATSAPP_QUEUE_ASYNC=true`) queue worker inayoendesha `php artisan queue:work`

---

## 2. Hatua za Ufungaji

```bash
# 1. Copy project na kufunga dependencies
composer install --no-dev --optimize-autoloader
npm install
npm run build

# 2. Sanidi .env
cp .env.example .env
php artisan key:generate
```

### Vigezo muhimu vya `.env` kwa production

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ecard.example.com        # lazima https! hutumika kwa webhook_url

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ecard_system
DB_USERNAME=ecard_user
DB_PASSWORD=strong-password

# SESSION na CACHE kwenye database (kwa scalability)
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# --- WhatsApp Cloud API ---
WHATSAPP_API_TOKEN=EAAB...               # Permanent Access Token
WHATSAPP_APP_SECRET=abc123...            # App Secret (huthibitisha webhook signature)
WHATSAPP_PHONE_NUMBER_ID=123456789
WHATSAPP_BUSINESS_ACCOUNT_ID=987654321
WHATSAPP_WEBHOOK_VERIFY_TOKEN=chagua-verify-token-ya-kibinafsi
WHATSAPP_GRAPH_API_VERSION=v21.0
WHATSAPP_QUEUE_ASYNC=true                # Weka true ikiwa una queue worker

# --- AI Assistant (OpenAI-compatible) ---
AI_API_KEY=sk-...
AI_BASE_URL=https://api.openai.com/v1
AI_MODEL=gpt-4o-mini
AI_TIMEOUT=30
AI_TEMPERATURE=0.3
```

### 3. Database

```bash
mysql -u root -p -e "CREATE DATABASE ecard_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --force
```

### 4. Cache (production)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Baada ya cache, **kila mabadiliko ya `.env` yanahitaji** `php artisan config:clear` kisha `config:cache`.

### 5. Permissions (Linux)

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 6. Queue worker (ikiwa `WHATSAPP_QUEUE_ASYNC=true`)

```bash
# Supervisor config: /etc/supervisor/conf.d/ecard-worker.conf
[program:ecard-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ecard/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/ecard/storage/logs/queue-worker.log
```

---

## 2b. Windows Server / XAMPP (Task Scheduler)

Kwa server inayotumia Windows + XAMPP, tumia **scripts/** zilizomo kwenye project:

| Script | Kazi |
|--------|------|
| `scripts\queue-worker.ps1` | Inaendesha `queue:work` milele (inajirudisha ikiwa imekufa) |
| `scripts\backup-db.ps1` | Backup ya MySQL kila siku (retention siku 14) |

**Kuandaa Task Scheduler (endesha PowerShell kama Administrator):**

```powershell
# 1. Queue worker - inaanza wakati wa logon
schtasks /Create /TN "ECard QueueWorker" /SC ONLOGON /RL HIGHEST /F `
    /TR "powershell -ExecutionPolicy Bypass -WindowStyle Hidden -File \"C:\path\to\ecard-system\scripts\queue-worker.ps1\""

# 2. Laravel scheduler - kila dakika 1 (weekly reminders + scheduled SMS)
schtasks /Create /TN "ECard Scheduler" /SC MINUTE /MO 1 /F `
    /TR "C:\xampp\php\php.exe C:\path\to\ecard-system\artisan schedule:run"

# 3. Database backup - kila siku saa 1:00 asubuhi
schtasks /Create /TN "ECard DB Backup" /SC DAILY /ST 01:00 /F `
    /TR "powershell -ExecutionPolicy Bypass -WindowStyle Hidden -File \"C:\path\to\ecard-system\scripts\backup-db.ps1\""
```

> Badilisha `C:\path\to\ecard-system` na path halisi ya project.
>
> **Kumbuka:** Bila hayo mazuri (1–3), invitations za WhatsApp/SMS hazitumwi,
> weekly reminders hazitarudiwa, na hakuna backup ya database.

---

## 3. Kusanidi WhatsApp Webhook (Graph API)

1. Nenda kwenye [Meta App Dashboard](https://developers.facebook.com/apps/) → chagua app yako.
2. `WhatsApp` → `Configuration`.
3. Weka **Callback URL** = `https://ecard.example.com/whatsapp/webhook`
   - Kiungo hiki kinaonyeshwa pia kwenye dashboard → **Mipangilio ya WhatsApp**.
4. Weka **Verify Token** = kile ulichoweka kwenye `WHATSAPP_WEBHOOK_VERIFY_TOKEN` (au kwenye mipangilio ya admin).
5. **Webhook fields**: chagua `messages`.
6. Baada ya kuhifadhi, Meta itatuma **verification challenge** — mfumo unajibu kiotomatiki kama token inalingana.

**Security:**
- Kwa sababu ya `WHATSAPP_APP_SECRET`, kila POST inakaguliwa kwa `X-Hub-Signature-256` (HMAC SHA-256). Ujumbe usio na signature sahihi unakataliwa (403).
- Ikiwa `WHATSAPP_APP_SECRET` haijasanzwa kwenye `.env`, uthibitisho wa signature haufanyiki — usiiachie hivyo kwenye production.

---

## 4. Checklist ya Uthibitisho (Post-Deployment)

- [ ] `https://ecard.example.com/up` inarudisha **200** (health check)
- [ ] Dashboard ya admin inafunguka (login inafanya kazi)
- [ ] **Mipangilio ya WhatsApp** inaonyesha `Hali ya API: Imesanidiwa` na `Webhook URL` sahihi
- [ ] Verify token inaweza kupitia challenge: weka webhook kwenye Meta na uthibitishe inafanikiwa
- [ ] Tuma ujumbe kwenye namba ya WhatsApp → AI inajibu (na menyu ya vitufe kwa salamu)
- [ ] FAQ mpya inaweza kujibiwa na AI
- [ ] **Mazungumzo** yanaonyesha mazungumzo mapya; reply/take-over/block vinafanya kazi
- [ ] QR scanning ya kadi inafanya kazi (browser lazima HTTPS)
- [ ] `php artisan test` inapita kabla ya kuruhusu traffic (kwa mazingira ya test)

---

## 5. Mambo ya Kuzingatia (Tips)

- **HTTPS ni lazima**: WhatsApp haikubali callback URL ya HTTP. Tumia Let's Encrypt (Certbot) kwa SSL.
- **APP_URL inabidi iwe HTTPS** vinginevyo `webhook_url` na viungo vya E-Card vitatengenezwa na `http://`.
- Ikiwa server iko nyuma ya load balancer / reverse proxy, hakikisha nginx inasambaza headers sahihi na usetiwe APP_URL sahihi.
- Backup database kila siku: `mysqldump -u root -p ecard_system > backup.sql`
- Fuata maelekezo ya Meta kuhusu **message templates** — ujumbe wa kwanza kwa mgeni mpya lazima uwe template iliyoidhinishwa; baadaye unaweza kujibu kwa uhuru (24-hour session).
