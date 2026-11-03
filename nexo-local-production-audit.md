# NEXO — Local vs Production Code Audit

تاریخ ممیزی: 2026-10-24

## دامنه و محدودیت

- ممیزی فقط‌خواندنی انجام شد؛ هیچ Deploy، Migration، Commit، Push، Restart یا درخواست تغییردهنده‌ای اجرا نشد.
- مسیر محلی موجود: `C:\Users\1\Desktop\ganj\ganj`.
- مسیر اعلام‌شده‌ی `C:\Users\HadNegaran\Desktop\ganj` موجود نبود.
- مقصد موردنظر فقط `2.188.205.35:2238` بود. اتصال SSH با کلید `nexo_office` موفق شد.
- هیچ اتصال به VPS قدیمی انجام نشد.
- تمام دستورات Production فقط خواندنی بودند؛ هیچ Deploy، Migration، Restart، Recreate یا DB write انجام نشد.

## 1. وضعیت Git محلی

| مورد | مقدار |
|---|---|
| Branch | `main` |
| HEAD | `3c25207708b3faca81be938f2cf9a2ecfe7b7afa` |
| `origin/main` | `3c25207708b3faca81be938f2cf9a2ecfe7b7afa` |
| Commitهای محلی Pushنشده | 0 |
| فایل‌های tracked تغییرکرده | 0 |
| فایل‌های tracked حذف‌شده | 0 |
| فایل‌های untracked | 9 مورد سطحی/گروهی |

Untrackedها:

- `docker-compose.login-fix-final.yml`
- `docker-compose.login-fix.yml`
- `docker-compose.management-test.yml`
- `docker-compose.theme-final.yml`
- `docker-compose.theme-test.yml`
- `mobile/.build-tools/`
- `mobile/NEXO-Mobile-Test-arm64.apk`
- `mobile/cmake-3.22.1-windows.zip`
- `mobile/lib/arm64-v8a/`

تقسیم‌بندی فایل‌های tracked در scope ممیزی:

- Backend/Laravel: 230 فایل
- Control Center: 59 فایل
- Flutter: 50 فایل
- Docker/Build: 6 فایل
- کل scope هش‌شده‌ی قابل‌مقایسه: 347 فایل

### آخرین Release Manifest

فایل canonical با نام Release Manifest پیدا نشد. جدیدترین release-like سند موجود:
`docs/releases/NEXO-Management-2026.10.15-rc1.md`.

آخرین Commitهای مرتبط:

- `3c25207` — gaming UI، map، room fixes و test build setup
- `37911e5` — scenario management، gaming theme و login fixes
- `df6c803` — Control Center API endpoint و super admin command
- `4ada32b` — build full NEXO release image artifacts

## 2. نسخه‌ی واقعی Production

| سرویس | Image | Image ID | Created At | Container ID | Mount | Compose metadata |
|---|---|---|---|---|---|---|
| `nexo-app` | `nexo-app:release-20261020T184103Z` | `sha256:1e2e723928763d7c48e14a5473137906a15ab3002d712dc2877d250e4b235c44` | `2026-10-20 19:08:33 UTC` | `a345ecdce294...` | `nexo_app_storage -> /var/www/html/storage` | project `nexo`; `/opt/nexo/releases/management-20261018T120500Z` |
| `nexo-control` | `nexo-control:login-fix-final-20261018T123000Z` | `sha256:61bdf3c12ae61efa5c5db2ad9ec785276a8cc5519cc117c72095846462146be5` | `2026-10-18 08:44:25 UTC` | `ee49677b1cfd...` | none | project `nexo`; `/opt/nexo/releases/login-fix-final-20261018T123000Z` |
| `nexo-nginx` | `nexo-nginx:df6c803` | `sha256:f38dad5031010e3659bda21263aa048d82553bb13d95db238182a66fd54f36cb` | `2026-10-18 07:24:33 UTC` | `0105d788122c...` | none | project `nexo`; `/opt/nexo/releases/df6c8039536943d54af1e81b6efada5ffed57fd5` |

`nexo-nginx` روی `0.0.0.0:18100->80` bind شده است. Compose فعال project `nexo` چند release/config file دارد؛ بنابراین Compose path سرویس‌ها یکسان نیست و باید هنگام انتشار آینده جداگانه pin شود.

Git SHA یا OCI build metadata معتبر برای `nexo-app` و `nexo-control` در labelهای خوانده‌شده وجود نداشت؛ Tag به‌تنهایی مبنای برابری کد محسوب نشده است.

## 3. مقایسه‌ی واقعی محتوا و Hash

Hashهای SHA-256 محلی ثبت شدند؛ سمت Production قابل استخراج نبود:

| فایل محلی | SHA-256 |
|---|---|
| `composer.json` | `A8BE0618443693F7D1A92200812E31D84D93776A9FBE281065EAB1DE18F6A8AE` |
| `composer.lock` | `9F788E43A5D338409712479E70F3A33AE5335F9BF6170FD2ECE64ABC2202CE16` |
| `routes/api.php` | `EAA2AD5F7CEC197AE7919279D7202E71EA3181137B20F3BE27EFE8B8D8319793` |
| `docker-compose.production.yml` | `A10624C5F2E4F4977D5C04E981DCD605620E14B347A927FB471F7B018508C6F8` |
| `docker/nginx-production.conf` | `D84D5F480FC24505544BABE3FE7088B8BDE17DB08FBBCD14CB6FDBDB052B1DCA` |
| `control-center/package.json` | `88D62A33366048571FABE8583DAE9526BF52D7E65F87584F9AF5A9378B018C6E` |
| `control-center/package-lock.json` | `8B3F668470AF3C1B831BED2483B053BC2FC955E237FCDA1A7E20EB6644CAA466` |
| `mobile/pubspec.yaml` | `4F5610ED0537164F145CB9C3657C3ED8AD81E1C98CD807E347F627B69B6441ED` |
| `mobile/pubspec.lock` | `28DD7049AAA1D3E936962340B81CF9BACA4B1F301B03B20711F29125BBC475CB` |
| `Dockerfile` | `53488AE908ABFB94A64915476ABAB8EB6781384D4660EB8F1ED0111D3B506C79` |

نتیجه‌ی طبقه‌بندی فعلی:

| دسته | تعداد اثبات‌شده |
|---|---:|
| Identical | **213** |
| Different | **16** |
| Local Only | **1** — `app/Console/Commands/CreateSuperAdmin.php` |
| Production Only | **11** |
| UNKNOWN | Control Center source؛ Image فقط artifact کامپایل‌شده دارد |

Scope شامل Backend source، routes، migrations، config، bootstrap غیر-cache و composer manifests است؛ `vendor`، `node_modules`، `.next` build output، storage و cache محتوایی از شمارش source حذف شده‌اند.

فایل‌های Different مهم:

- `app/Http/Controllers/Api/ContentBankController.php`
- `app/Http/Controllers/Api/TeamController.php`
- `app/Http/Requests/TeamRequest.php`
- `app/Models/TeamInvite.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Games/GameInput.php`
- `app/Services/Games/GameRoomService.php`
- `app/Services/Games/GameService.php`
- `app/Services/Games/ProductionSessionDependencies.php`
- `app/Services/Locations/CheckpointService.php`
- `app/Services/Organizations/GlobalRoleService.php`
- `app/Services/Scenarios/ScenarioService.php`
- `app/Services/Teams/TeamInput.php`
- `app/Services/Teams/TeamService.php`
- `routes/api.php`

Production Onlyهای مهم:

- `app/Http/Controllers/Api/GameBookingController.php`
- `app/Http/Requests/GameBookingRequest.php`
- `app/Services/Games/GameBookingService.php`
- `app/Models/GameBooking.php`
- `app/Models/BookingParticipant.php`
- `app/Models/PaymentOrder.php`
- `app/Models/PaymentRefund.php`
- `app/Models/PaymentTransaction.php`
- `database/migrations/2026_10_19_174509_create_game_booking_payment_tables.php`
- `database/migrations/2026_10_19_195815_add_internal_uuid_to_users_table.php`
- `database/migrations/2026_10_19_203036_add_recipient_decision_to_team_invites_table.php`

## 4. API و Routeها

Laravel محلی با `php artisan route:list --except-vendor --json` تعداد **144 Route** دارد. Routeهای شاخص شامل:

- Auth و identity: login/register/logout، `me`، devices، capabilities
- جداسازی Admin/Player و Organization management
- Games، Game Sessions، Team Lobby/Rooms، invite و membership
- Runtime، scenario authoring/versioning، QR، location، media و evidence
- Live challenge، scoring، hints، reports و health/readiness

مقایسه‌ی method + URI (بدون اتکا به generated route name): Local **144**، Production **157**، مشترک **143**.

Local Only:

- `POST api/v1/scenarios/{scenario_id}/archive`

Production Only:

- `GET api/v1/bookings/{booking_id}`
- `POST api/v1/games/{game_id}/bookings`
- `GET api/v1/me/bookings`
- `GET api/v1/me/payments`
- `GET api/v1/me/team-invitations`
- `POST api/v1/me/team-invitations/{invite_id}/accept`
- `POST api/v1/me/team-invitations/{invite_id}/decline`
- `POST api/v1/payment-orders/{order_id}/checkout`
- `POST api/v1/payment-orders/{order_id}/test-confirm`
- `GET api/v1/players/{player_id}`
- `POST api/v1/teams/{team_id}/player-invitations`
- `GET/PUT storage/{path}` (فایل‌های framework storage)
- `GET up` (health route framework)

دو مورد آخر framework/operational هستند؛ 11 مسیر اول قابلیت‌های واقعی Booking/Payment، Player search و incoming team invitations را نشان می‌دهند.

## 5. Migrationها

محلی **23 migration** دارد؛ جدیدترین‌ها شامل game rooms و city برای games هستند:

- `2026_10_16_145207_create_game_rooms_tables.php`
- `2026_10_16_145231_add_city_to_games_table.php`

در دیتابیس عملیاتی **26 migration ثبت و Ran** است. 23 مورد با Local مشترک‌اند و سه migration Production جدیدتر هستند:

- `2026_10_19_174509_create_game_booking_payment_tables`
- `2026_10_19_195815_add_internal_uuid_to_users_table`
- `2026_10_19_203036_add_recipient_decision_to_team_invites_table`

هیچ migration اجرا نشد؛ فقط `migrate:status` خوانده شد.

## 6. تنظیمات محلی و Production

در پروژه محلی فایل `.env` وجود نداشت؛ بنابراین مقدار Secret یا محیط runtime محلی چاپ نشد.

از `docker-compose.production.yml` و کانفیگ‌ها، بدون نمایش Secret، موارد زیر قابل مشاهده است:

- `APP_ENV=production` و `APP_DEBUG=false`
- DB target نوع PostgreSQL، سرویس داخلی `nexo-postgres`، پورت داخلی 5432
- Redis با سرویس داخلی `nexo-redis` و پورت 6379
- Queue روی Redis؛ سرویس‌های queue و scheduler تعریف شده‌اند
- Nginx روی پورت داخلی 80؛ bind بیرونی پیش‌فرض `127.0.0.1:${NEXO_HTTP_PORT:-18100}`
- Control Center پشت Nginx روی `control:3000`
- `NEXT_PUBLIC_API_BASE_URL=/api/v1` در Dockerfile و fallback همان مقدار در Control Center
- Flutter API به‌صورت compile-time از `API_BASE_URL` می‌آید؛ تست emulator از `10.0.2.2:8000/api/v1` استفاده می‌کند و آدرس‌های `2.188.205.35:18100` و `:18099` را در allowlist تست می‌شناسد
- Production: `APP_ENV=production`، `APP_DEBUG=false`، DB روی PostgreSQL به host داخلی `nexo-postgres`، Redis client برابر `predis` و host داخلی `nexo-redis`، Queue و Cache روی Redis، session driver برابر `file`.
- Production `APP_URL` به `http://127.0.0.1:18100` تنظیم است؛ Nginx بیرونی روی HTTP port 18100 bind شده است. وضعیت TLS termination خارجی، Cookie secure، CORS و BFF URL: **UNKNOWN**.
- `nexo-control` در Image، `package.json` برابر Local است: hash هر دو `88d62a33366048571fabe8583dae9526bf52d7e65f87584f9af5a9378b018c6e`.
- Source TypeScript/Next داخل Image موجود نیست؛ فقط `/app/.next` و `server.js` وجود دارد. بنابراین برابری محتوای source Control Center: **UNKNOWN**.
- کانفیگ واقعی `/etc/nginx/conf.d/default.conf` با `docker/nginx-production.conf` Local یکسان است: hash هر دو `d84d5f480fc24505544babe3fe7088b8bde17db08fbbcd14cb6fdbdb052b1dca`.

## 7. تشخیص Drift

Drift واقعی Backend:

- **Different (16 فایل):** شامل `routes/api.php` و تغییرات در Team، Game Room، Session dependencies، Scenario، Location، Organization و User logic است.
- **Production Only (11 فایل):** Booking/Payment، UUID داخلی User و تصمیم‌گیری recipient برای team invite.
- **Local Only (1 فایل):** `CreateSuperAdmin.php`.
- **Identical (213 فایل):** در scope hashشده.

موارد مهم و تفسیر قابلیت‌ها:

- Team Lobby/Rooms و team experience: migrationهای game rooms، routeهای rooms/teams و کد Flutter lobby
- جداسازی Admin/Player: routeهای admin، organization و middlewareهای هویتی
- Scenario authoring/runtime، QR/location/media و scoring/hints
- Booking/Payment: در Production فعال و در Local فعلی غایب است؛ این یک Production Only واقعی و خطر Regression مستقیم است.
- Team invitations/player search: چند endpoint و فایل Production Only هستند؛ انتشار Local می‌تواند این قابلیت‌ها را حذف کند.
- Team Lobby v3 و Admin/Player separation در هر دو lineage وجود دارند، اما فایل‌های منطق Team و routeها Different هستند و نیازمند review قراردادی‌اند.

موارد طبیعی/انتظارشده‌ی غیرقابل‌مقایسه:

- `vendor`، `node_modules`، build/cache، APK و ابزارهای build از محتوای مقایسه حذف شدند.
- Flutter روی سرور اجرا نمی‌شود؛ فقط Contract و Base URL آن با Backend محلی بررسی شد.

## 8. قابلیت‌های Local در برابر Production

- Local و در Production نیست: مسیر archive سناریو و `CreateSuperAdmin.php` در source Local دیده می‌شود؛ route archive در Production فعال نیست. علت/اثر عملیاتی باید قبل از Deploy بررسی شود.
- Production و در Local نیست: Booking/Payment کامل، booking lookup، payment checkout/test-confirm، booking/payment history، player search و incoming player invitations.
- بنابراین Production قطعاً قابلیت‌هایی دارد که Local فعلی فاقد آن است.

## 9. ریسک جایگزینی Image Production با نسخه‌ی محلی

ریسک جایگزینی Image فعلی با Local **High** است، چون:

- Production دارای 11 فایل source و 11 مسیر قابلیت‌محور اضافه نسبت به Local است؛ سه مسیر اضافه‌ی دیگر framework/operational هستند.
- Production دارای 3 migration اضافه‌ی ثبت‌شده است.
- مسیر archive سناریو در Local وجود دارد ولی در Production نیست؛ این اختلاف نیز باید توضیح داده شود.
- Control Center source قابل استخراج نیست؛ فقط package و build artifact بررسی شد.
- untracked Composeهای آزمایشی و خروجی‌های Flutter در workspace وجود دارند و نباید وارد build/deploy شوند.
- `origin/main` برابر HEAD است، اما این فقط وضعیت Git محلی را نشان می‌دهد و محتوای Image را ثابت نمی‌کند.

## 10. پیشنهاد همگام‌سازی امن

1. قبل از هر انتشار، Booking/Payment و سه migration Production را به Local منتقل یا درباره‌ی حذف آگاهانه‌ی آن‌ها تصمیم‌گیری کنید.
2. اختلاف Team/Scenario/Location/Organization و route archive را با contract review و تست regression بررسی کنید.
3. Source واقعی Control Center یا manifest قابل‌اعتماد build را فراهم کنید؛ `.next` به‌تنهایی برای اثبات برابری source کافی نیست.
4. یک artifact reproducible با commit و image digest قطعی تولید کنید؛ untrackedهای build/test وارد release نشوند.
5. migration plan و rollback plan جداگانه تهیه کنید؛ در این ممیزی هیچ migration اجرا نشده است.
6. تا رفع Production Onlyهای Booking/Payment و تکمیل بررسی Control Center، نسخه محلی بدون خطر از دست‌دادن قابلیت‌های فعلی قابل انتشار نیست.
## 11. Production-First Source Synchronization — 2026-10-24

این بخش نتیجه‌ی ادامه‌ی ممیزی و ایجاد artifactهاست. هیچ فایل tracked پروژه، توسعه‌ی محلی، Git history یا untracked موجود حذف یا بازنویسی نشد.

### حفاظت Local

- Repository واقعی: `C:\Users\1\Desktop\ganj\ganj`
- Branch: `main`
- HEAD: `3c25207708b3faca81be938f2cf9a2ecfe7b7afa`
- بکاپ کامل شامل untrackedها: `C:\Users\1\Desktop\ganj\nexo-local-backup-20261024.tar`
- حجم بکاپ: `2,014,234,112` bytes
- SHA-256 بکاپ: `E3811F8A13E6A592BAC3780F7AFC4D6032D104BBE1A755F39B87F05D114394F3`
- `git reset --hard` و `git clean` اجرا نشدند.

### Production Baseline

- مسیر: `C:\Users\1\Desktop\ganj\nexo-production-baseline-20261024`
- Backend استخراج‌شده از Image فعال `nexo-app`: **239 فایل**
- Route manifest: **157 route** در `manifests/production-routes.txt`
- Migration manifest: **26 مورد Ran** در `manifests/production-migrations.txt`
- Hash manifest Backend: `manifests/backend-sha256.txt`
- Image/container metadata: `manifests/image-metadata.txt`
- Nginx فعال از `/etc/nginx/conf.d/default.conf` استخراج شد و SHA-256 آن با کانفیگ Local برابر است.
- Volume عملیاتی `nexo_app_storage`، دیتابیس، env، Secret، Token، vendor، cache و node_modules کپی نشدند.

### Backend parity با Production Baseline

در scope شامل `app/`، `routes/`، `database/migrations/`، `config/`، `bootstrap/` غیر-cache و Composer manifests:

| دسته | تعداد |
|---|---:|
| Local scoped files | 229 |
| Production baseline files | 239 |
| Hash Identical | 212 |
| Hash Different | 16 |
| Local Only | 1 |
| Production Only | 11 |

Exact hash parity نسبت به فایل‌های Local برابر **92.6%** است (`212/229`). این درصد به‌معنای آمادگی Deploy نیست؛ 11 فایل Production Only شامل Booking/Payment و migrationهای عملیاتی هستند.

### Control Center

Source واقعی TypeScript/Next از Image فعال قابل بازیابی نبود؛ Image فقط `package.json`، `server.js` و artifactهای `.next` را داشت. `package.json` با Local hash یکسان دارد، اما source parity **UNKNOWN** است و تأییدشده اعلام نشده است.

### تغییرات Local حفظ‌شده و Conflictها

- هیچ ادغام، overwrite یا بازنویسی روی Local انجام نشد.
- تغییرات توسعه‌ای/Untracked مانند Team Lobby v3، Join Requests، QR Invite و Control Center BFF دست‌نخورده باقی ماندند.
- Conflictهای محتوایی مهم برای ادغام بعدی: Booking/Payment Production Only، Internal UUID، targeted team invitations، اختلاف Team/Scenario/Location/Organization و route archive سناریو.
- به‌دلیل این Conflictها، ادغام خودکار انجام نشد.

### آمادگی برای یکسان‌سازی نهایی

**آماده نیست.** Baseline واقعی ایجاد و قابل شناسایی شده، اما قبل از هر تغییر Local باید بررسی و تست ایزوله‌ی زیر انجام شود:

1. ادغام یا تصمیم صریح درباره‌ی 11 فایل Production Only و سه migration Production.
2. بررسی contract و regression برای Booking/Payment، Team Invitations، Player Search و قابلیت‌های فعلی Backend.
3. بررسی اختلاف‌های 16 فایل Different و route archive.
4. بازیابی Source یا build provenance معتبر Control Center.
5. اجرای تست‌های Backend/Booking/Team/Security در PostgreSQL/PostGIS ایزوله؛ هیچ تست DB روی Production مجاز نیست.

تا دریافت تأیید بعدی، فقط Baseline و backup ایجاد شده‌اند و هیچ synchronization code change انجام نشده است.
