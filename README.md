# Mfumo wa E-Kadi na Udhibiti wa Kuingia Ukumbini

Mfumo wa kisasa wa **E-Card na Udhibiti wa Wageni wa Kuingia Ukumbini** unaojengwa na:

- **Laravel 12** — Backend / REST API
- **MySQL** — Database
- **Vue.js 3** — Frontend (SPA)
- **Tailwind CSS** — UI
- **Laravel Sanctum** — Authentication
- **Vite** — Frontend build tool
- **QR Code Scanner** — kupitia camera ya browser (@zxing/library)
- **WhatsApp Business / WhatsApp Cloud API** — kutuma E-Kadi kwa wageni

> **Mfumo wote unaoonekana kwa mtumiaji kwa lugha ya kingereza pekee.**
> Backend + MySQL ndiyo **source of truth** — frontend haifanyi maamuzi yoyote ya uthibitisho.

---

## Mahitaji

- PHP 8.2+
- Composer
- MySQL 5.7+ / MariaDB 10.4+
- Node.js 20+
- Kamera (kwa QR scanner) — browser lazima iwe HTTPS kwa matumizi ya production

### Kamera haifanyi kazi kwenye simu?

QR scanner inahitaji **HTTPS**. Browser huuficha `navigator.mediaDevices` kabisa
sehemu ya mtumiaji wa `http://`, hivyo kamera hawezi kufunguliwa hata kidogo.

| Tatizo | Sababu | Suluhisho |
| --- | --- | --- |
| "Inaonyesha HTTPS" | Fungua kwa `http://192.168.x.x:8000` | Tumia `https://` (au `localhost` kwenye kompyuta) |
| "Camera access was denied" | Mzito alikataa | Washa ruhusa kwenye mipango ya kivinjari, kisha reload |
| "No camera was found" | Hakuna kamera iliyounganishwa | Unganisha kamera na uhakikishe ruhusa ya mfumo wa uendeshaji |
| "already in use" | Programu nyingine inap Kamera | Funga programu nyingine inayotumia kamera |

`Permissions-Policy` lazima ionekane `camera=(self)` (imewekwa kwenye
`app/Http/Middleware/SecurityHeaders.php`). Ikiwa header hiyo ilikuwa
`camera=()`, kamera ilizofungwa kabisa hata kwenye HTTPS.

### Tunnel ya HTTPS kwa simu (ngrok)

```powershell
.\scripts\ngrok-ecard.ps1              # anzisha, au onyesha URL ikiwa tayari inaendesha
.\scripts\ngrok-ecard.ps1 -Action status
.\scripts\ngrok-ecard.ps1 -Action restart
.\scripts\ngrok-ecard.ps1 -Action stop
.\scripts\ngrok-ecard.ps1 -Watch       # ijianzishe upya ikikatika
```

Fungua URL ya `https://...ngrok-free.dev` kwenye simu. Free plan inaruhusu
**session moja tu** ya ngrok — kuanzisha ya pili kunaua ya kwanza, ndiyo maana
script hii haianzishi mara mbili. Launcher nayo inatumia hii:
`.\scripts\open-ecard.ps1 -Tunnel`. Log iko `%TEMP%\ngrok-ecard.log`.

---

## Ufungaji (Setup)

```bash
# 1. Clone / copy project na kufunga dependencies
composer install
npm install

# 2. Tunga .env
cp .env.example .env
php artisan key:generate

# 3. Sanidi database kwenye .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ecard_system
DB_USERNAME=root
DB_PASSWORD=

# 4. Unda database na migraate
mysql -u root -e "CREATE DATABASE ecard_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed

# 5. Endesha server
php artisan serve          # http://localhost:8000

# 6. (Dev) Vite
npm run dev                # au kwa production: npm run build
```

### Watumiaji wa Mfano (baada ya seeding)

| Jina | Barua Pepe | Nenosiri | Jukumu |
|------|-----------|----------|--------|
| Msimamizi Mkuu | `charleschanai4@gmail.com` | `password` | `admin` |
| Mhudumu wa Mlangoni | `staff@ecard.local` | `password` | `staff` |

---

## Aina za Kadi

| Aina | Watu wanaoruhusiwa | Matumizi ya juu | Flow |
|------|--------------------|-----------------|------|
| **Single** | 1 | 1 | `0/1` → ruhusu · `1/1` → kataa |
| **Double** | 2 | 2 | `0/2`, `1/2` → ruhusu · `2/2` → kataa |

`allowed_people` inatengenezwa **automatically** kulingana na aina — hakuna njia ya kuweka namba isiyo sahihi.

---

## Muundo wa Database (ERD)

```
users ──┬── processed_by ──┐
        │                  ▼
        │             entry_logs
guests ─┼── guest_id ──────┘    ▲
        │                       │
        ├── has_many ──> cards ─┘
        └── <──── card_id ──┘
```

**users** — id, name, email (unique), password, role (`admin`/`staff`), remember_token
**guests** — id, full_name, phone (unique), email, notes
**cards** — id, guest_id FK, card_number (unique `EC-000001`), qr_token (unique), card_type (`single`/`double`), allowed_people, used_people, status (`active`/`used`/`cancelled`), issued_at, expires_at
**entry_logs** — id, card_id FK, guest_id FK, processed_by FK, entry_number, result (`allowed`/`denied`/`already_used`/`cancelled`/`expired`/`invalid`), scanned_at, gate_name, device_name, ip_address, user_agent, notes

**Kila jaribio la kuingia linalohifadhiwa** — hata lililokataliwa (audit trail).

---

## Flow ya Uthibitisho wa Kadi

```
Tafuta Jina / Namba  -----------┐
                                ▼
                         CardVerificationService
   Scan QR Code  -----------┐   │
                            │   ▼
                            └─ findCard()
                               ▼
                            verifyCard()   ← read-only, haibadilishi hali
                               ▼
                            confirmEntry() ← ⭐ DB transaction + lockForUpdate()
                               ▼
                       Ruhusu / Kataa + hifadhi entry_log
```

### Ulinzi dhidi ya concurrent scans

`confirmEntry()` inafanya:

```php
DB::transaction(function () {
    $card = Card::query()->whereKey($id)->lockForUpdate()->first();
    // angalia hali, matumizi, kapasiti
    // ongeza used_people, weka status='used' kama imefikiwa
    // hifadhi entry_log
});
```

Watu wawili wakiskan kadi moja kwa wakati mmoja — **mmoja tu ataweza** kuingia. `lockForUpdate()` inazuia shindano.

---

## API Routes (`/api/v1`)

| Method | Endpoint | Ruhusa | Maelezo |
|--------|----------|--------|---------|
| POST | `/login` | Umma | Ingia, pata token |
| POST | `/password/forgot` | Umma | Tuma kiungo cha kubadilisha nenosiri |
| POST | `/password/reset` | Umma | Badilisha nenosiri (token + barua pepe) |
| POST | `/logout` | Auth | Ondoka, batilisha token |
| GET | `/me` | Auth | Taarifa za mtumiaji |
| GET | `/guests/search?q=` | Auth | Tafuta mgeni au namba ya kadi |
| GET | `/guests/export` | Auth | Pakua wageni kama CSV (kwa vichujio vya sasa) |
| GET | `/guests` | Auth | Orodha ya wageni (paginated) |
| POST | `/guests` | **Admin** | Ongeza mgeni |
| GET | `/guests/{id}` | Auth | Taarifa za mgeni |
| PUT | `/guests/{id}` | **Admin** | Hariri mgeni |
| DELETE | `/guests/{id}` | **Admin** | Futa mgeni |
| GET | `/cards/export` | Auth | Pakua kadi kama CSV (kwa vichujio vya sasa) |
| GET | `/cards` | Auth | Orodha ya kadi (+ vichujio `q`, `status`, `card_type`) |
| POST | `/cards` | **Admin** | Tengeneza kadi |
| GET | `/cards/{id}` | Auth | Taarifa za kadi + historia |
| POST | `/cards/verify` | Auth | Thibitisha (read-only, hakuna mabadiliko) |
| POST | `/cards/confirm-entry` | Auth | Thibitisha kuingia (transactional) |
| POST | `/cards/{id}/cancel` | **Admin** | Ghairi kadi |
| DELETE | `/cards/{id}` | **Admin** | Futa kadi |
| GET | `/entry-logs/export` | Auth | Pakua kumbukumbu kama CSV (kwa vichujio vya sasa) |
| GET | `/entry-logs` | Auth | Kumbukumbu za kuingia (paginated) |
| GET | `/dashboard/statistics` | **Admin** | Takwimu za dashibodi |
| POST | `/whatsapp/send-card/{card}` | **Admin** | Tuma E-Kadi kwa WhatsApp |
| GET | `/public/card/{qrToken}` | Umma | Data ya E-Kadi (kiungo cha mgeni) |

### Format ya Response

```json
{
    "success": true,
    "message": "Ruhusa ya kuingia imetolewa.",
    "error_code": null,
    "data": { }
}
```

Error zina `error_code` (English) na `message` (Kiswahili):
`CARD_NOT_FOUND`, `CARD_ALREADY_USED`, `CARD_CANCELLED`, `CARD_EXPIRED`, `VALIDATION_ERROR`, `FORBIDDEN`, `NOT_FOUND`.

---

## WhatsApp

`app/Services/WhatsAppService.php` ina:

- `sendCard(Card $card)` — hutuma ujumbe wa Kiswahili kupitia **WhatsApp Cloud API**
- `buildMessage()` — ujumbe wa Kiswahili (Aina, Namba, Watu wanaoruhusiwa, Kiungo cha E-Kadi)
- `waLink()` — `https://wa.me/...` deep link (fallback wakati API haijasanidiwa)

Sanidi kwenye `.env`:

```env
WHATSAPP_API_TOKEN=EAAG...
WHATSAPP_PHONE_NUMBER_ID=123456
WHATSAPP_BUSINESS_ACCOUNT_ID=987654
```

Ujumbe wa mfano:

> Habari Juma,
> Umealikwa kwenye sherehe.
> Aina ya kadi: Single
> Namba ya kadi: EC-000123
> Tafadhali fungua E-Kadi yako kupitia kiungo hiki na uiwasilishe mlangoni: [kiungo]
> Asante.

---

## Muundo wa Frontend (Vue 3)

```
resources/js/
├── components/
│   ├── QRScanner.vue          # @zxing/library, camera permission, HTTPS-only, cooldown 3s
│   ├── QRCodeCanvas.vue       # qrcode (qrcode npm)
│   ├── EntryResult.vue        # matokeo ya kijani/nyekundu
│   ├── ConfirmDialog.vue      # dialog ya uthibitisho
│   ├── ToastContainer.vue     # notifications
│   └── StatCard.vue
├── layouts/
│   ├── EntryLayout.vue        # mobile-first, mlangoni
│   └── AdminLayout.vue        # sidebar ya admin
├── pages/
│   ├── Login.vue
│   ├── Entry.vue              # Tafuta Mgeni + Scan QR + Thibitisha
│   ├── Dashboard.vue
│   ├── Guests.vue / GuestShow.vue
│   ├── Cards.vue / CardShow.vue
│   ├── EntryLogs.vue
│   └── ECard.vue              # E-Kadi ya umma (kwa simu ya mgeni)
├── services/                  # api.js (axios), auth, cards, guests, dashboard, entry-logs, whatsapp
├── stores/                    # Pinia: auth, toast
└── router/index.js            # role-based guards
```

---

## Query Optimization

### Indexes

Migration `2026_09_25_100000_add_performance_indexes.php` inaongeza indexes
kwa kila query iliyopo. Kila index huambiana kwenye comment yaake ni
kwa njia gani inatumika:

| Query | Index |
|-------|-------|
| `GuestController::index()` — search + sort | `guests_full_name_id_idx` |
| `CardVerificationService::confirmEntry()` | `cards_guest_status_idx` |
| `EntryLogController::index()` — filter + sort | `entry_logs_result_scanned_idx` |
| `InvitationCampaignService::syncSmsDeliveryStatus()` | `inv_sends_channel_status_idx` |
| `sms:process-scheduled` (kila dakika) | `inv_sends_status_sched_idx` |
| `WeeklyReminderService::alreadySentThisWeek()` | `wrl_year_week_status_idx` |
| `WhatsAppAdminController::conversations()` | `wa_conv_status_last_idx` |

Migration inaweza **kurudi nyuma** (`down()`), inaangalia kama index
ipo kwanini, na inafanya kazi kwenye MySQL na SQLite.

### Scopes

Badala ya kujirudia `where` kila controller, models zina scopes:

```php
Card::invitable()        // kadi zinazopokea mwaliko: mgeni + simu halisi + siyo cancelled
Card::stillValid()       // bado hazijaisha muda
Card::status($status)
Guest::contactable()     // wanaweza kupokea WhatsApp/SMS
Guest::search($term)     // jina, simu, barua pepe
EntryLog::allowed() / ::denied() / ::since() / ::searchGuestName()
```

`Card::invitable()` na `Guest::contactable()` zilishirikisha hizo
kanuni. Kabla ya mabadiliko, campaign preview ilikuwa inaonyesha hesabu
moja na campaign halisi ikitumia nyingine.

### Eager loading

`Card` na `EntryLog` zina `$with` zinazowafanya wapate maumbukho yao
moja kwa moja. Kwenye `EntryLog` hii inaondoa 10 queries na kufanya 4.

### Kupunguza data

```bash
php artisan data:prune --dry-run   #ona kile kitachofutwa
php artisan data:prune             #futa
```

Inafuta kwa vipande (`LIMIT 2000` kwa query) ili isielewe. Maana ya
vipande: `DELETE` bila kizuizi kwenye InnoDB table kubwa inashikilia
row locks muda mrefu kutosha kusimamisha kadi ya mlangoni — jambo
lisilo la kubadilika siku ya sherehe.

Entry logs hazifutwi kwa default (`MONITORING_ENTRY_LOGS_DAYS=0`).
Zarekodiwa ndiyo kumbukumbu kuu ya kushika mlango.

---

## Monitoring

### Health checks

```bash
php artisan monitor:health
```

| Check | Anachopima |
|-------|-----------|
| `database` | Latency, ukubwa, slow-query ratio |
| `queue` | Backlog kwenye kila queue + `failed_jobs` |
| `disk` | Nafasi ya bure |
| `performance` | Error rate, slow requests, slow queries |
| `schedule` | Je, scheduler inafanya kazi |
| `providers` | Je, WhatsApp/SMS zimefunguliwa |

Kila check imizongotiwa: check moja ikishindwa haizuii zingine
kupima. Zinapatikana kupitia:

- **`GET /api/v1/health`** — 200 = serving, 503 = onyesha trafiki pengine
- **`/up`** — sasa inatesti database na disk, si PHP tu
- **`GET /api/v1/monitoring`** — kwa admin, checks na recent events
- **Monitoring page** — `/monitoring` kwenye sidebar

> **Kwa nini check sasa ni sita tu?** Dashibodi yenye hesabu nyingi hazisomwi
> hivyo check moja inapaswa kuwepo pale inapoweka kitu kinachokoseka kweli.
> `cache` na `logging` ziliondolewa: cache ikifa inazorodi tu (mlango
> bado unafunguliwa), na ukubwa wa log ni sehemu ya check ya `disk`.

### Slow queries

`QueryMonitor` inashikilia kila query na kuandika zile zinazokua zaidi
ya `MONITORING_SLOW_QUERY_MS` (default 250ms) kwenye `sql.log`, pamoja na
SQL yake. Pia: request inayofanya zaidi ya `MONITORING_QUERY_BUDGET`
queries (default 60) inarekodiwa kama uwezekano wa N+1.

Bindings hazitengenezwi kwenye log isipokuwa `LOG_SQL_LEVEL=debug`,
kwa sababu zina data ya wageni.

### Logs

Kila hoja ina faili yake mwenyewe: `error.log`, `sql.log`,
`performance.log`, `security.log`, `external.log`, `monitoring.log`.
Kila moja ina retention yake (`LOG_*_DAYS`).

### Alerts

Weka `MONITORING_ALERT_TO` ili upate ujumbe kwenye WhatsApp au SMS
wakati kuna tatilo. Alert moja kwa key kwa `MONITORING_ALERT_THROTTLE`
dakika (default 30) — kushindwa kwa saa 10 huzungesha taarifa moja,
si mia.

### Kupima utendaji

`Server-Timing` header inaongezwa kwenye kila response, hivyo unaona
muda halisi katika browser devtools bila round-trip.

Kuzima monitoring kwa muda (mfano unaprofilia request polepole):

```env
MONITORING_ENABLED=false
```

---

## Tests

```bash
php artisan test
```

> **Important:** hakuna uragizishaji wa tests mbili kwa wakati mmoja. Kila
> process inaendesha `migrate:fresh` kwenye database moja, na hiyo ina
> kuangusha schema chini ya miguu ya process nyingine. Matokeo: matest
> yanayoweza kuanguka kwa nasibu, hasa `ConcurrencyTest`.
>
> Pia: kama test imekatwa (Ctrl+C, machine ikizima), `ConcurrencyTest` inaweza
> kuiacha DB ya test katika hali mbaya. Jibu kwa:
>
> ```bash
> mysql -u root -e "DROP DATABASE IF EXISTS ecard_system_test;
>                  CREATE DATABASE ecard_system_test CHARACTER SET utf8mb4;"
> ```

Inashughulikia:

- **Single**: `0/1` → allowed, `1/1` → denied, cancelled → denied, invalid → denied
- **Double**: `0/2` → allowed, `1/2` → allowed, `2/2` → denied, cancelled → denied
- **Search**: kwa jina, kwa namba, majina yanayofanana, mgeni asiyeonekana
- **QR**: sahihi, si sahihi, imeghairiwa, imetumika
- **Concurrency**: usifanye zaidi ya limit + **mtihani halisi wa MySQL row lock** (`FOR UPDATE`)
- **Auth & Roles**: admin/staff permissions, token revocation
- **Usimamizi**: CRUD ya wageni na kadi, validation ya Kiswahili
- **Monitoring**: health checks, queue backlog, scheduler heartbeat, alerts
- **Query optimization**: indexes zipo, scopes, eager loading
- **Usalama**: password reset, status codes, webhook, camera policy

`tests/TestCase.php` inafanya `Http::preventStrayRequests()`, hivyo
test hazitumiwi pesa kwa AI au SMS ya kweli hata kama keys zipo kwenye
mazingira yako.

---

## Production Checklist

- [ ] HTTPS (inahitajika kwa camera access kwenye browser)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `php artisan config:cache route:cache view:cache`
- [ ] `npm run build`
- [ ] **Queue worker: `php artisan queue:work --queue=default,whatsapp,sms`**
- [ ] Database backup (cron) + **restore imejaribiwa**
- [ ] `WHATSAPP_*` env vars zimejaa, ikiwa ni pamoja na `WHATSAPP_APP_SECRET`
- [ ] `NEXTSMS_USERNAME` / `NEXTSMS_PASSWORD` zimejaa
- [ ] Scheduler inaendesha (`php artisan schedule:list` inaonyesha `monitor:heartbeat`)
- [ ] `MONITORING_ALERT_TO` imewekwa
- [ ] `TRUSTED_PROXIES` / `TRUSTED_HOSTS` zimejaa
- [ ] `php artisan monitor:health` — hakuna check iliyo `DOWN`
- [ ] `/up` na `/api/v1/health` zinarudisha 200

### Mambo yaliyojaa hapo awali

| Kipengele | Ukinyofu |
|-----------|---------|
| **QR Scanner** | Imekuwa ikifanya kazi: `camera=(self)` badala ya `camera=()` |
| **WhatsApp webhook** | Inafanya kazi: ina fail *closed* kama `WHATSAPP_APP_SECRET` ipo |
| **WhatsApp/SMS** | Inatumwa: worker inasikiliza `whatsapp` na `sms` |
| **SMS** | Inatumwa: `NEXTSMS_*` zipo kwenye `.env.example` |
| **429/403/404** | Zinarudisha hali zake halisi, si 500 |
| **Rate limits** | Zinasoma `config/security.php`, si `env()` — zinafanya kazi baada ya `config:cache` |
| **Scheduler** | `withoutOverlapping()` — mgeni mmoja hakutumiwi ujumbe mara 4 |
| **SMS scheduled** | Inafanya kwa claim+lock — si mara 2 kwa mgeni |

---

## Usalama

- Laravel Sanctum (token API)
- Authorization: middleware `role:admin` + **Policies** (`GuestPolicy`, `CardPolicy`)
- Rate limiting (`throttle`) kwenye login, verify na confirm-entry
- Validation (Form Requests) — messages za Kiswahili
- QR tokens ni random (32 chars) — hakuna data nyeti ndani ya QR
- Transactions + `lockForUpdate()` — usalama wa concurrency
- Source of truth: **backend tu** — frontend haiwezi kudanganya hali ya kadi
