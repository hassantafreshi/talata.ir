# استقرار production — زرلیو (فاز ۱)

این سند راه‌اندازی نسخه اول روی سرور را شرح می‌دهد. هیچ بخشی از آن روی سرور واقعی اجرا و اندازه‌گیری نشده است؛ نمونه پیکربندی‌ها نقطه شروع‌اند و باید با سرور انتخابی مالک تطبیق داده شوند. پیش از روشن‌کردن سرویس، `php artisan talata:preflight` باید بدون خطای «FAIL» تمام شود.

## ۱. اجزا

| جزء | توضیح |
| --- | --- |
| وب | nginx (TLS، فشرده‌سازی، فایل‌های ثابت `public/build`) + PHP-FPM 8.3 با `pdo_pgsql`، `gd`، `intl`، `mbstring`، `bcmath` اختیاری، OPcache روشن |
| پایگاه داده | PostgreSQL 16؛ دو اتصال در `config/database.php` (`pgsql` و `pgsql_log` برای لاگ فنی) می‌توانند به یک پایگاه اشاره کنند |
| صف | `php artisan queue:work --queue=otp,default` با supervisor (صف `otp` اول: کد ورود منتظر فاکتورها نمی‌ماند) |
| زمان‌بند | cron هر دقیقه `php artisan schedule:run` (مظنه ۱۸۰ ثانیه، استعلام پرداخت، وضعیت پیامک، انقضای اعتبار، یادآوری قسط، کمیسیون همکاران، پاک‌سازی لاگ) |
| فایل | `storage/app/private` (لوگوی فروشگاه‌ها، بازکدگذاری‌شده به PNG) — باید پشتیبان داشته باشد |
| بیرونی | درگاه زرین‌پال (`payment.zarinpal.com`)، کاوه‌نگار (`api.kavenegar.com`)، سرویس نرخ (پس از انتخاب). مرورگر کاربر به هیچ دامنه بیرونی وصل نمی‌شود |

## ۲. متغیرهای محیطی production (حداقل)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://zarlio.ir
APP_KEY=base64:...                 # یک بار بسازید و جای امن نگه دارید (رمز توکن QR فاکتورها)
APP_LOCALE=fa
APP_FALLBACK_LOCALE=fa
TALATA_PUBLIC_URL=https://zarlio.ir # دامنه لینک /i و QR /v؛ بعداً فقط با redirect دامنه قبلی عوض شود
TRUSTED_PROXIES=127.0.0.1           # اگر پشت load balancer است، نشانی/CIDR دقیق آن؛ هرگز * (جعل X-Forwarded-For)

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=talata
DB_USERNAME=talata
DB_PASSWORD=...

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_SAME_SITE=lax
CACHE_STORE=database                 # یا redis
QUEUE_CONNECTION=database            # یا redis
LOG_STACK=daily,errors_db

TALATA_PAYMENT_DRIVER=zarinpal
TALATA_ZARINPAL_MERCHANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
TALATA_ZARINPAL_SANDBOX=false

TALATA_SMS_DRIVER=kavenegar
KAVENEGAR_API_KEY=...
KAVENEGAR_SENDER=...
KAVENEGAR_OTP_TEMPLATE=...           # الگوی Verify Lookup با %token

TALATA_WEBAUTHN_RP_ID=zarlio.ir
TALATA_ADMIN_REQUIRE_PASSKEY=true
TALATA_ADMIN_ALLOWED_IPS=            # پیشنهاد: IP دفتر
TALATA_OTP_DAILY_BUDGET=2000
TALATA_OTP_EXISTING_DAILY_BUDGET=3000
TALATA_RELEASE=                      # اسکریپت استقرار پر می‌کند (مثلاً git short hash)
TALATA_BACKUP_HEARTBEAT_FILE=/var/backups/zarlio/last_backup_ok
TALATA_RESTORE_DRILL_FILE=/var/backups/zarlio/last_restore_drill_ok
```

`TALATA_ALLOW_MOCK_PAYMENTS_IN_PRODUCTION` و `TALATA_ALLOW_DEV_SMS_IN_PRODUCTION` باید `false` بمانند. سرویس نرخ تا انتخاب ارائه‌دهنده `demo` است و همه جا برچسب «نمونه» دارد؛ preflight آن را هشدار می‌دهد، نه خطا.

## ۳. استقرار

اولین بار:

```bash
git clone … /var/www/zarlio && cd /var/www/zarlio
composer install --no-dev --optimize-autoloader
cp .env.example .env    # مقدارهای بخش ۲
php artisan key:generate   # فقط اولین بار
npm ci && npm run build && rm -f public/hot
php artisan migrate --force --seed     # نسخه قیمت ۱، قاعده مالیات نمونه، اولین مظنه
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan talata:staff 09xxxxxxxxx "نام مدیر" --role=admin
php artisan talata:preflight
```

هر استقرار بعدی (به ترتیب):

```bash
php artisan down --render="errors::503" --retry=30   # اختیاری برای migrationهای سنگین
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build && rm -f public/hot
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart          # workerها کد تازه را بارگیری می‌کنند
TALATA_RELEASE=$(git rev-parse --short HEAD) php artisan talata:preflight || exit 1
php artisan up
```

نکته: پس از `config:cache` تابع `env()` بیرون از فایل‌های config مقدار `.env` را نمی‌خواند؛ همه تنظیم‌ها از `config/*.php` خوانده می‌شوند (از جمله `TRUSTED_PROXIES` از `config/talata.php`).

## ۴. nginx (نمونه)

```nginx
server {
    listen 80;
    server_name zarlio.ir www.zarlio.ir;
    return 301 https://zarlio.ir$request_uri;
}
server {
    listen 443 ssl http2;
    server_name zarlio.ir;
    root /var/www/zarlio/public;
    index index.php;
    ssl_certificate     /etc/ssl/zarlio/fullchain.pem;
    ssl_certificate_key /etc/ssl/zarlio/privkey.pem;
    client_max_body_size 2m;              # لوگو حداکثر ۱ مگابایت
    server_tokens off;
    gzip on; gzip_types text/css application/javascript image/svg+xml application/json;

    location /build/ { access_log off; add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }
    location /fonts/ { access_log off; add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }
    location ~ /\.(?!well-known) { deny all; }
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }
}
```

سرآیندهای امنیتی (CSP سخت بدون اسکریپت درون‌خطی، HSTS روی HTTPS، `frame-ancestors 'none'`، …) را خود برنامه می‌فرستد (`SecurityHeaders`). در `php.ini`: `expose_php=Off`، `display_errors=Off`، OPcache روشن.

## ۵. صف و زمان‌بند

supervisor (`/etc/supervisor/conf.d/zarlio.conf`):

```ini
[program:zarlio-worker]
command=php /var/www/zarlio/artisan queue:work --queue=otp,default --sleep=1 --tries=1 --max-time=3600
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
user=www-data
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/zarlio/worker.log
```

cron کاربر `www-data`:

```cron
* * * * * cd /var/www/zarlio && php artisan schedule:run >> /dev/null 2>&1
```

آخرین اجرای هر کار زمان‌بند در «سلامت سیستم» کنسول مدیر (`/admin/system`) دیده می‌شود. `SendSms` یک‌بار اجرا می‌شود و ردیف را اتمی claim می‌کند؛ اجرای دوباره کار ناموفق از همان صفحه امن است.

## ۶. پشتیبان و بازیابی

- روزانه: `pg_dump -Fc talata > /var/backups/zarlio/talata-$(date +%F).dump && touch /var/backups/zarlio/last_backup_ok` و نگهداری بیرون از سرور. داشبورد مدیر اگر پشتیبان بیش از ۲۶ ساعت قدیمی باشد هشدار می‌دهد.
- `storage/app/private` (لوگوها) و فایل `.env` (به‌ویژه `APP_KEY`) را هم پشتیبان بگیرید. بدون `APP_KEY` چاپ دوباره QR فاکتورهای قدیمی ممکن نیست (بررسی اصالت چاپ‌های قبلی همچنان کار می‌کند). چرخش کلید با `APP_PREVIOUS_KEYS`.
- ماهانه: بازیابی آزمایشی روی سرور جدا (`pg_restore`)، اجرای `php artisan talata:preflight` و باز کردن یک فاکتور، سپس `touch /var/backups/zarlio/last_restore_drill_ok`.
- جدول `audit_events` با trigger پایگاه داده فقط‌افزودنی است؛ لاگ فنی پس از `TALATA_TECH_LOG_DAYS` (پیش‌فرض ۹۰ روز) پاک می‌شود.

## ۷. راه‌اندازی درگاه زرین‌پال

1. در پنل زرین‌پال دامنه `zarlio.ir` را برای درگاه ثبت کنید و کد پذیرنده (۳۶ نویسه) را در `TALATA_ZARINPAL_MERCHANT_ID` بگذارید.
2. روی محیط آزمایشی با `TALATA_ZARINPAL_SANDBOX=true` یک خرید پلن، یک انصراف و یک خرید اعتبار انجام دهید؛ نتیجه را در `/admin/payments` و لاگ فنی (سرویس `payments`) ببینید.
3. در production با `TALATA_ZARINPAL_SANDBOX=false` یک پرداخت واقعی کوچک انجام دهید، رسید را با پنل زرین‌پال تطبیق دهید و «استعلام دوباره از بانک» را امتحان کنید.
4. نشانی بازگشت بانک: `https://zarlio.ir/pay/callback/zarinpal` (در صفحه «اتصال‌ها» نمایش داده می‌شود).

قرارداد API از کتابخانه عمومی `riviera-zarinpal` برداشته شده چون مستندات رسمی از محیط ساخت در دسترس نبود؛ پیش از go-live با مستندات رسمی زرین‌پال تطبیق دهید (`docs/PAYMENTS_AND_SMS_CREDIT.md` §۷).

## ۸. پیامک کاوه‌نگار

خط اختصاصی و الگوی Verify Lookup با `%token` بسازید، کلید را تنظیم کنید و از «اتصال‌ها» در کنسول مدیر «ارسال آزمایشی به شماره من» را بزنید. سقف روزانه کد ورود (`TALATA_OTP_*_BUDGET`) را با حجم واقعی تنظیم کنید؛ در ۸۰٪ داشبورد مدیر هشدار می‌دهد.

## ۹. مدیر سامانه

- اولین مدیر با `php artisan talata:staff`؛ بقیه از `/admin/staff` (نقش‌های مالی، عملیات فنی، پشتیبانی).
- هر کارمند پس از اولین ورود پیامکی باید در «حساب من» کلید عبور اضافه کند؛ تا آن موقع فقط داشبورد باز است.
- کارهای حساس به ورود تازه (پیش‌فرض ۱۵ دقیقه) نیاز دارند. `TALATA_ADMIN_ALLOWED_IPS` کنسول را برای بقیه IPها ۴۰۴ می‌کند.

## ۱۰. پایش پس از راه‌اندازی

داشبورد مدیر (`/admin`): هشدارهای باز (درگاه آزمایشی در production، debug روشن، مظنه قدیمی/قطع، پرداخت نیازمند بررسی، پیامک نامعلوم، کار ناموفق صف، سقف کد ورود، پشتیبان قدیمی)، «کارهای امروز» و خطاهای فنی ۲۴ ساعت. لاگ فنی هر سرویس در `/admin/tech`.

## ۱۱. بازگشت (rollback)

`git checkout <نسخه قبل>`، `composer install --no-dev`، `npm ci && npm run build`، cacheها، `php artisan queue:restart`. migrationها فقط اضافه‌کننده‌اند؛ `migrate:rollback` را فقط پس از بررسی `down()` و پشتیبان تازه اجرا کنید. فاکتورهای صادرشده snapshot دارند و با بازگشت کد تغییر نمی‌کنند.
