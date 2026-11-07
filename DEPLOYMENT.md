# Mwongozo wa Ufungaji kwa Production (Deployment)

Mwongozo huu unaeleza hatua za kuweka mfumo wa **E-Card + WhatsApp AI** kwenye server ya production.

---

## 0. Vitu Sifa Kuelewa

Vitu vitatu hapa chini vinaangusha mfumo kwa njia ya **tulivu** — hakuna error, mfumo tu hukua kazi:

| Kipengele | Kinachofanyika bila kurekebiwa |
|-----------|-------------------------------|
| **Queue worker bila `--queue`** | Job zote za WhatsApp/SMS zinapewa kwenye `whatsapp` na `sms` queues, lakini worker inayosikiliza `default` pekee. Mavilio yatatumwa **kamwe**, na dashibodi inaonyesha "pending" bila sababu. |
| **`NEXTSMS_*` hazitoshwe** | Kila SMS inashindwa kwa ujumbe "not configured" — bila exception, bila log. Maombi ya simu, vikumbusho vya kila wiki, ujumbe wa michango — vyote vinaishia bila kutuma. |
| **Scheduler ikiwa imesimama** | Vikumbusho vya kila wiki vinaisha bila kutuma. Hakuna error. `monitor:heartbeat` hulisha tatizo hili. |

---

## 1. Mahitaji ya Server

- PHP **8.2+** (pamoja na extensions: `pdo_mysql`, `mbstring`, `gd`, `openssl`, `curl`, `zip`)
- MySQL **5.7+** / MariaDB **10.4+**
- Node.js **20+** (kwa kujenga frontend; hahitajiki kwenye server baada ya build)
- Composer
- Nginx / Apache + **SSL (HTTPS)** — **lazima** kwa WhatsApp webhook
- Queue worker inayoendesha `php artisan queue:work --queue=default,whatsapp,sms`

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

### 6. Queue worker

> **`--queue=default,whatsapp,sms` si ya hiari.** Job za maombi na za
> kielezi zinapewa kwenye queues zenye majina (`InvitationCampaignService`
> inatumia `onQueue('whatsapp')` / `onQueue('sms')`). Worker bila
> `--queue` husikilizi `default` pekee, hivyo hizo job zinabaki kwenye
> database milele na dashibodi inaonyesha "pending" bila sababu.

```bash
# Supervisor config: /etc/supervisor/conf.d/ecard-worker.conf
[program:ecard-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ecard/artisan queue:work --queue=default,whatsapp,sms --sleep=3 --tries=3 --backoff=30 --timeout=120 --max-time=3600
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/ecard/storage/logs/queue-worker.log
```

Kuthibitisha kuwa worker inasikiliza vyote:

```bash
php artisan tinker --execute="print_r(\Illuminate\Support\Facades\DB::table('jobs')->select('queue', \Illuminate\Support\Facades\DB::raw('count(*) as total'))->groupBy('queue')->pluck('total', 'queue')->toArray());"
```

---

## 6b. Monitoring na Logging

### Log channels

Kila hoja ya tatizo lina faili yake mwenyewe, ili kuzungusha log
isizuiye kitu unachohitaji:

| Faili | Inaeleza nini |
|-------|---------------|
| `storage/logs/error.log` | Hitilafu na exceptions (30 siku) |
| `storage/logs/sql.log` | Slow queries na N+1 (14 siku) |
| `storage/logs/performance.log` | Request zilizo chini ya sekunde (7 siku) |
| `storage/logs/security.log` | Login, 2FA, audit (60 siku) |
| `storage/logs/external.log` | WhatsApp, SMS, email, AI (14 siku) |
| `storage/logs/monitoring.log` | Health checks na alerts (30 siku) |

### Health checks

```bash
php artisan monitor:health          #onyesha majibu
php artisan monitor:health --json   #kwa automation
```

Vinasagiliwa na scheduler kila dakika 5. Checks zinazotathminiwa:
database (latency, ukubwa, slow-query ratio), cache, queue backlog na
`failed_jobs`, disk space, ukubwa wa logs, error rate, scheduler heartbeat,
na kama WhatsApp/SMS/email zimefunguliwa.

Zinapelekwa kwenye **`/api/v1/health`** (503 = onyesha trafiki pengine) na
`/up` (ambayo sasa inatesti database na cache, si kuwa PHP tu imebaki).

### Alerts

Weka namba yako ili upate ujumbe wa moja kwa moja wakati kuna tatizo:

```env
MONITORING_ALERTS=true
MONITORING_ALERT_CHANNEL=whatsapp   # au sms
MONITORING_ALERT_TO=+2557XXXXXXXX
MONITORING_ALERT_THROTTLE=30        #dakika; kuzuia mawazo
```

Alert moja kwa key kwa dakika 30 — kushindwa kwa saa 10 huzungesha
taarifa moja tu, si mia.

### Kupunguza data

```bash
php artisan data:prune --dry-run    #ona kile kitachofutwa
php artisan data:prune              #futa
```

Inafuta metrics, audit rows, na mazungumzo ya WhatsApp. **Entry logs
hayafutwi kwa default** — ndiyo kumbukumbu kuu ya kushika mlango, na
kupoteza rekodi ni mbaya kuliko kukosewa nafasi. Tahadhari ya disk
inajitolea kabla tatizo halisi.

### Kufuatilia

Weka kwenye `.env`:

```env
TRUSTED_PROXIES=*        #au CIDR ya load balancer yako
TRUSTED_HOSTS=your-domain.com
```

Bila hii, TLS ikishindwa na nginx/Cloudflare, `$request->isSecure()` ni
`false`: HSTS inapotoka, redirect inazunguka, na webhook ya WhatsApp
 inapata `302` ambayo inafuta body — signature check inapata hash ya
kitufe na mawasiliano yanakufa bila error.

---

## 2b. Windows Server / XAMPP (Task Scheduler)

Kwa server inayotumia Windows + XAMPP, tumia **scripts/** zilizomo kwenye project:

| Script | Kazi |
|--------|------|
| `scripts\queue-worker.ps1` | Inaendesha `queue:work` milele kwenye `default,whatsapp,sms` |
| `scripts\backup-db.ps1` | Backup ya MySQL kila siku (retention siku 14) |

**Kuandaa Task Scheduler (endesha PowerShell kama Administrator):**

```powershell
# 1. Queue worker - inaanza wakati wa logon
schtasks /Create /TN "ECard QueueWorker" /SC ONLOGON /RL HIGHEST /F `
    /TR "powershell -ExecutionPolicy Bypass -WindowStyle Hidden -File \"C:\path\to\ecard-system\scripts\queue-worker.ps1\""

# 2. Laravel scheduler - kila dakika 1 (weekly reminders + scheduled SMS + monitoring)
#    LAZIMA iwe scheduler ya MOJA tu. Nyingi zinatumika mara kwa utaratibu
#    kwa sababu schedule:run inaanza kila kila mwaka.
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
- [ ] `https://ecard.example.com/api/v1/health` inarudisha **200** na `data.status: "ok"`
- [ ] `php artisan monitor:health` — hakuna check iliyo `DOWN`
- [ ] Dashboard ya admin inafunguka (login inafanya kazi)
- [ ] **Mipangilio ya WhatsApp** inaonyesha `Hali ya API: Imesanidiwa` na `Webhook URL` sahihi
- [ ] `WHATSAPP_APP_SECRET` **imewekwa** (bila yake webhook inarudisha 403/503 kwa kila ujumbe)
- [ ] Verify token inaweza kupitia challenge: weka webhook kwenye Meta na uthibitishe inafanikiwa
- [ ] Tuma ujumbe kwenye namba ya WhatsApp → AI inajibu (na menyu ya vitufe kwa salamu)
- [ ] FAQ mpya inaweza kujibiwa na AI
- [ ] **Mazungumzo** yanaonyesha mazungumzo mapya; reply/take-over/block vinafanya kazi
- [ ] QR scanning ya kadi inafanya kazi (browser lazima HTTPS — kinaangalia `camera=(self)`)
- [ ] **QR scanner imejaribiwa kwenye simu halisi** (si desktop pekee): fungua `Entry Check` kwenye
      Android na iPhone, bonyeza `Scan QR Code` → `Start Camera`, rudisha ruhusa ya kamera, kisha
      scan kadi moja. Simu za programu huuficha `navigator.mediaDevices` kwenye `http://`, hivyo
      URL ya LAN (`http://192.168.x.x:8000`) **haiwezi** kufungua kamera. Hakikisha unafungua
      kupitia `https://` au DNS halisi.
- [ ] Ukishawahi kukataa kamera, kuna ujumbe unaoeleza jinsi ya kuwaruhusu upya (`How do I fix this?`)
- [ ] **Queue worker inasikiliza zote tatu**: tumia job moja ya majaribio, kisha angalia `Monitoring → Queues` zikipungua
- [ ] `NEXTSMS_*` zimewekwa; `php artisan ecard:test-channels` inarudisha SMS OK
- [ ] `MONITORING_ALERT_TO` imewekwa na umejaribu alert (`php artisan monitor:health`)
- [ ] `php artisan test` inapita kabla ya kuruhusu traffic (kwa mazingira ya test)
- [ ] **Queue restart baada ya deploy**: `php artisan queue:restart`

---

## 5. Mambo ya Kuzingatia (Tips)

- **HTTPS ni lazima**: WhatsApp haikubali callback URL ya HTTP. Tumia Let's Encrypt (Certbot) kwa SSL.
- **APP_URL inabidi iwe HTTPS** vinginevyo `webhook_url` na viungo vya E-Card vitatengenezwa na `http://`.
- Ikiwa server iko nyuma ya load balancer / reverse proxy, hakikisha nginx inasambaza headers sahihi na usetiwe APP_URL sahihi.
- Backup database kila siku: `mysqldump -u root -p ecard_system > backup.sql`
- Fuata maelekezo ya Meta kuhusu **message templates** — ujumbe wa kwanza kwa mgeni mpya lazima uwe template iliyoidhinishwa; baadaye unaweza kujibu kwa uhuru (24-hour session).
