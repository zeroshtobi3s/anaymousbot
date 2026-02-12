# ربات تلگرام پیام ناشناس (PHP 8.2+)

ربات به هر کاربر یک لینک اختصاصی می‌دهد تا دیگران بتوانند ناشناس پیام بفرستند.  
گیرنده می‌تواند روی هر پیام:
- `Reply` (پاسخ ناشناس)
- `Block` (بلاک ارسال‌کننده برای خودش)
- `Report` (ارسال گزارش برای ادمین‌ها)

پروژه روی این محیط‌ها قابل اجراست:
- Windows + XAMPP (بدون HTTPS با Polling)
- Linux/VPS (Webhook یا Polling)
- Shared Host (Webhook توصیه‌شده)

## ساختار پروژه

```text
phpBot/
├─ cli/
│  └─ polling.php
├─ logs/
├─ migrations/
│  └─ migration.sql
├─ public/
│  ├─ index.php
│  ├─ webhook.php
│  └─ polling.php
├─ src/
│  ├─ Bootstrap/
│  │  ├─ AppContainer.php
│  │  └─ ApplicationFactory.php
│  ├─ Config/
│  │  └─ AppConfig.php
│  ├─ Controllers/
│  │  └─ UpdateController.php
│  ├─ Database/
│  │  └─ Database.php
│  ├─ Repositories/
│  │  ├─ BlockRepository.php
│  │  ├─ ConversationStateRepository.php
│  │  ├─ MessageRepository.php
│  │  ├─ ReportRepository.php
│  │  └─ UserRepository.php
│  ├─ Security/
│  │  ├─ CallbackSigner.php
│  │  └─ TextSanitizer.php
│  ├─ Services/
│  │  ├─ AnonymousMessageService.php
│  │  ├─ AntiSpamService.php
│  │  ├─ BotIdentityService.php
│  │  ├─ ConversationService.php
│  │  ├─ MaintenanceService.php
│  │  ├─ ReportService.php
│  │  ├─ SettingsService.php
│  │  └─ UserService.php
│  ├─ Telegram/
│  │  └─ TelegramClient.php
│  └─ Utils/
│     └─ Logger.php
├─ .env.example
├─ composer.json
└─ README_FA.md
```

## پیش‌نیازها

- PHP `8.2+`
- Composer
- MySQL/MariaDB
- دسترسی به BotFather برای دریافت `BOT_TOKEN`

## نصب سریع

1. نصب وابستگی‌ها:
```bash
composer install
```

2. ساخت فایل env:
```bash
cp .env.example .env
```

3. مقداردهی `.env` (نمونه پایین را ببینید).

4. ایجاد دیتابیس و ایمپورت:
- فایل `migrations/migration.sql` را در phpMyAdmin یا mysql import کنید.

## نمونه `.env`

```env
BOT_TOKEN=123456:ABC-DEF
BOT_USERNAME=MyAnonMessageBot
APP_MODE=polling
APP_BASE_URL=
WEBHOOK_SECRET=change_this_to_a_long_random_secret
TELEGRAM_CA_FILE=./cacert.pem

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=phpbot
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

ADMIN_IDS=123456789,987654321
MESSAGE_RETENTION_DAYS=90
RATE_LIMITS=sender_per_minute=3;sender_per_hour=20;target_per_minute=25;duplicate_window_seconds=120
MAX_TEXT_LENGTH=2000
MAX_CAPTION_LENGTH=900
MAX_PHOTO_SIZE_MB=10
POLLING_TIMEOUT=25
POLLING_IDLE_SLEEP=1
```

## راه‌اندازی روی Windows + XAMPP (Polling / localhost)

1. پروژه را داخل مسیر مثلاً زیر بگذارید:
`C:\xampp\htdocs\phpBot`

2. در XAMPP:
- Apache و MySQL را روشن کنید.

3. دیتابیس:
- وارد `http://localhost/phpmyadmin` شوید.
- یک DB با نام `phpbot` بسازید.
- فایل `migrations/migration.sql` را import کنید.

4. `.env` را تنظیم کنید:
- `APP_MODE=polling`
- `DB_HOST=127.0.0.1`
- `DB_USER=root`
- `DB_PASS=` (معمولاً خالی در XAMPP پیش‌فرض)
- `WEBHOOK_SECRET` حتماً مقدار قوی داشته باشد.

5. اجرای Polling:
```bash
cd C:\xampp\htdocs\phpBot
php cli\polling.php
```

6. در تلگرام ربات را `/start` کنید.

نکته: در Polling نیازی به HTTPS و `setWebhook` ندارید.

## راه‌اندازی روی Linux/VPS

1. وابستگی‌ها:
```bash
composer install --no-dev --optimize-autoloader
```

2. دیتابیس:
```bash
mysql -u <db_user> -p <db_name> < migrations/migration.sql
```

3. `.env`:
- `APP_MODE=webhook` (توصیه برای production)
- `APP_BASE_URL=https://your-domain.com`
- `BOT_USERNAME` را ست کنید.
- `WEBHOOK_SECRET` قوی و طولانی باشد.

4. تنظیم وب‌سرور:
- DocumentRoot روی `public/` باشد.
- مسیر webhook: `https://your-domain.com/webhook.php`

5. ثبت webhook:
```bash
curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
  -d "url=https://your-domain.com/webhook.php?secret=<WEBHOOK_SECRET>" \
  -d "secret_token=<WEBHOOK_SECRET>"
```

6. بررسی:
```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/getWebhookInfo"
```

## راه‌اندازی روی Shared Host

1. پروژه را آپلود کنید (public_html).
2. `vendor/` را با `composer install` روی سرور یا لوکال آماده کنید.
3. `migrations/migration.sql` را با phpMyAdmin import کنید.
4. `.env` را تنظیم کنید (`APP_MODE=webhook`).
5. `setWebhook` را مانند بخش بالا اجرا کنید.

نکته: بیشتر Shared Hostها برای پردازش دائمی Polling مناسب نیستند. Webhook انتخاب اصلی است.

## سوییچ بین Webhook و Polling

- در `.env`:
  - `APP_MODE=webhook` یا `APP_MODE=polling`

### Polling Mode
```bash
php cli/polling.php
```

### Webhook Mode
- آدرس `public/webhook.php` باید روی اینترنت در دسترس باشد.
- سپس `setWebhook` بزنید.

## رفتارهای اصلی ربات

- `/start`
  - ثبت کاربر
  - ساخت `public_slug` یکتا
  - تولید لینک اختصاصی
- `start=<slug>`
  - ورود به حالت ارسال ناشناس
- ارسال متن/عکس
  - ذخیره در DB
  - ارسال به گیرنده با `Reply | Block | Report`
- Reply ناشناس
  - با state داخلی
- Block
  - بلاک ارسال‌کننده برای همان گیرنده
- Report
  - ثبت گزارش + اعلان به ادمین‌ها + امکان Block توسط ادمین
- `/inbox`
  - 10 پیام آخر
- `/stats`
  - پیام‌ها، بلاک‌ها، گزارش‌ها
- `/settings`
  - فعال/غیرفعال دریافت پیام
  - متن یا متن+مدیا
  - کلمات ممنوع

## سناریوهای تست

1. ساخت لینک
- User A: `/start`
- انتظار: نمایش لینک اختصاصی + دکمه «لینک من».

2. ارسال پیام ناشناس
- User B: روی لینک User A وارد شود (`start=<slug>`).
- پیام متن یا عکس بفرستد.
- انتظار: User A پیام را با دکمه‌های `Reply/Block/Report` دریافت کند.

3. Reply ناشناس
- User A روی `Reply` بزند و متن پاسخ ارسال کند.
- انتظار: User B پاسخ ناشناس دریافت کند.

4. Block
- User A روی `Block` همان پیام بزند.
- User B مجدد پیام بدهد.
- انتظار: پیام جدید به User A نرسد.

5. Report + Admin Block
- User A روی `Report` بزند.
- ادمین اعلان گزارش بگیرد.
- ادمین روی `Block Sender` بزند.
- انتظار: فرستنده برای گیرنده بلاک شود.

## نکات امنیتی

- webhook با `WEBHOOK_SECRET` محافظت شده (header/query secret).
- callback_data با HMAC امضا می‌شود و از tampering جلوگیری می‌کند.
- همه Queryها با PDO Prepared Statements.
- لاگ‌ها امن هستند و مقادیر حساس mask می‌شوند.
- خطاها به‌صورت graceful مدیریت می‌شوند و دیتای حساس نشت نمی‌کند.

## نکات بهینگی

- Queryهای محدود و index-friendly.
- Polling با `offset + timeout + sleep` برای مصرف CPU پایین.
- جلوگیری از flood با rate limit سمت sender و target.
- جلوگیری از پیام تکراری با `content_hash`.
- پاکسازی دوره‌ای پیام‌های قدیمی بر اساس `MESSAGE_RETENTION_DAYS`.

## چک‌لیست نهایی امنیت/بهینه‌سازی

- [ ] `WEBHOOK_SECRET` قوی تنظیم شده
- [ ] `BOT_TOKEN` فقط در `.env` نگهداری می‌شود
- [ ] پوشه `logs/` قابل نوشتن است
- [ ] indexهای migration اعمال شده‌اند
- [ ] `APP_MODE` درست انتخاب شده
- [ ] در production روی `public/` محدود شده‌اید (DocumentRoot)
- [ ] `ADMIN_IDS` معتبر تنظیم شده
- [ ] تست سناریوهای 1 تا 5 انجام شده

