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
│   ├── QRScanner.vue          # @zxing/library, camera permission, cooldown 3s
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

## Tests

```bash
php artisan test
```

Inashughulikia:

- **Single**: `0/1` → allowed, `1/1` → denied, cancelled → denied, invalid → denied
- **Double**: `0/2` → allowed, `1/2` → allowed, `2/2` → denied, cancelled → denied
- **Search**: kwa jina, kwa namba, majina yanayofanana, mgeni asiyeonekana
- **QR**: sahihi, si sahihi, imeghairiwa, imetumika
- **Concurrency**: usifanye zaidi ya limit + **mtihani halisi wa MySQL row lock** (`FOR UPDATE`)
- **Auth & Roles**: admin/staff permissions, token revocation
- **Usimamizi**: CRUD ya wageni na kadi, validation ya Kiswahili

---

## Production Checklist

- [ ] HTTPS (inahitajika kwa camera access kwenye browser)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `php artisan config:cache route:cache view:cache`
- [ ] `npm run build`
- [ ] Database backup (cron)
- [ ] Queue worker: `php artisan queue:work`
- [ ] Logging na error monitoring
- [ ] `WHATSAPP_*` env vars zimejaa

---

## Usalama

- Laravel Sanctum (token API)
- Authorization: middleware `role:admin` + **Policies** (`GuestPolicy`, `CardPolicy`)
- Rate limiting (`throttle`) kwenye login, verify na confirm-entry
- Validation (Form Requests) — messages za Kiswahili
- QR tokens ni random (32 chars) — hakuna data nyeti ndani ya QR
- Transactions + `lockForUpdate()` — usalama wa concurrency
- Source of truth: **backend tu** — frontend haiwezi kudanganya hali ya kadi
