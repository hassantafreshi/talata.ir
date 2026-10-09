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
# (http context) Token URLs never reach access logs in full: the public invoice/verification token and the
# payment-result id are replaced before logging (anyone holding a log copy could otherwise open the invoice).
map $request_uri $zarlio_log_uri {
    ~^/(?<seg>i|v)/[^/?]+   /$seg/[token];
    ~^/pay/result/          /pay/result/[order];
    default                 $request_uri;
}
log_format zarlio '$remote_addr - [$time_local] "$request_method $zarlio_log_uri" $status $body_bytes_sent "$http_user_agent"';

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
    access_log /var/log/nginx/zarlio.access.log zarlio;
    gzip on; gzip_types text/css application/javascript image/svg+xml application/json application/manifest+json;

    location /build/ { access_log off; add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }
    location /fonts/ { access_log off; add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }
    # PWA: the worker must be re-checked on every visit so updates reach installed phones.
    location = /sw.js { add_header Cache-Control "no-cache"; try_files $uri =404; }
    location = /manifest.webmanifest { default_type application/manifest+json; add_header Cache-Control "public, max-age=3600"; try_files $uri =404; }
    location /icons/ { access_log off; add_header Cache-Control "public, max-age=604800"; try_files $uri =404; }
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
4. نشانی بازگشت بانک: `https://zarlio.ir/pay/callback/zarinpal` (در صفحه «اتصال‌ها» و خروجی `talata:preflight` نمایش داده می‌شود).
5. `php artisan talata:preflight --live` روی همان سرور: دسترسی خروجی به `payment.zarinpal.com` را بدون ساختن تراکنش بررسی می‌کند (فقط یک GET؛ درخواست پرداخت ساخته نمی‌شود).

قرارداد API از کتابخانه عمومی `riviera-zarinpal` برداشته شده چون مستندات رسمی از محیط ساخت در دسترس نبود؛ پیش از go-live با مستندات رسمی زرین‌پال تطبیق دهید (`docs/PAYMENTS_AND_SMS_CREDIT.md` §۷).

## ۸. پیامک کاوه‌نگار

وقتی کلید و خط آماده شد (هیچ تغییر کدی لازم نیست):

1. در پنل کاوه‌نگار خط اختصاصی را در `KAVENEGAR_SENDER` و کلید API را در `KAVENEGAR_API_KEY` بگذارید.
2. دو الگوی Verify Lookup بسازید و پس از تأیید کاوه‌نگار نامشان را در env بگذارید:
   - ورود (`KAVENEGAR_OTP_TEMPLATE`): «کد ورود زرلیو: %token — این کد را به کسی ندهید.»
   - تغییر شماره ورود (`KAVENEGAR_OTP_TEMPLATE_MOBILE_CHANGE`): «کد تغییر شماره ورود زرلیو: %token — اگر خودتان درخواست نکرده‌اید، این کد را به هیچ‌کس ندهید.»
   بدون الگو، همین متن‌ها با `sms/send` از خط شما فرستاده می‌شود؛ کد تغییر شماره **هرگز** با الگوی ورود فرستاده نمی‌شود.
3. `TALATA_SMS_DRIVER=kavenegar`، سپس `php artisan config:cache` و `php artisan talata:preflight --live`: کلید را با `account/info` (بدون ارسال پیامک) بررسی می‌کند و اعتبار حساب را نشان می‌دهد.
4. از «اتصال‌ها» در کنسول مدیر «ارسال آزمایشی به شماره من» را بزنید، سپس یک ورود و یک فاکتور با «صدور و ارسال پیامکی» به شماره خودتان انجام دهید.
5. سقف روزانه کد ورود (`TALATA_OTP_*_BUDGET`) را با حجم واقعی تنظیم کنید؛ در ۸۰٪ داشبورد مدیر هشدار می‌دهد.

## ۹. مدیر سامانه

- اولین مدیر با `php artisan talata:staff`؛ بقیه از `/admin/staff` (نقش‌های مالی، عملیات فنی، پشتیبانی).
- هر کارمند پس از اولین ورود پیامکی باید در «حساب من» کلید عبور اضافه کند؛ تا آن موقع فقط داشبورد باز است.
- کارهای حساس به ورود تازه (پیش‌فرض ۱۵ دقیقه) نیاز دارند. `TALATA_ADMIN_ALLOWED_IPS` کنسول را برای بقیه IPها ۴۰۴ می‌کند.

## ۱۰. پایش پس از راه‌اندازی

داشبورد مدیر (`/admin`): هشدارهای باز (درگاه آزمایشی در production، debug روشن، مظنه قدیمی/قطع، پرداخت نیازمند بررسی، پیامک نامعلوم، کار ناموفق صف، سقف کد ورود، پشتیبان قدیمی)، «کارهای امروز» و خطاهای فنی ۲۴ ساعت. لاگ فنی هر سرویس در `/admin/tech`.

## ۱۱. اندازه‌گیری سرعت و تست نفوذ مستقل (پس از استقرار)

- **حجم بسته‌ها (هر build، بدون سرور):** `npm run build && npm run perf:bundle` — با بودجه‌های `docs/PERFORMANCE_BUDGET.md` مقایسه می‌کند و در صورت عبور کد خروج ۱ می‌دهد؛ در CI قبل از استقرار اجرا شود.
- **زمان‌های پروفایل A/B روی production:** از یک دستگاه اندازه‌گیری، طبق «Running the measurement» در `docs/PERFORMANCE_BUDGET.md` (`npm run perf:measure`). نتیجه و trace در `storage/perf/<زمان>/` (در git نیست). محل آزمون و دستگاه را کنار نتیجه ثبت کنید؛ این اندازه‌گیری آزمایشگاهی است، نه داده میدانی.
- **تست نفوذ مستقل:** آماده‌سازی محیط، دامنه، حساب‌ها و قالب گزارش در `docs/PENTEST_READINESS.md`.

## ۱۲. بازگشت (rollback)

`git checkout <نسخه قبل>`، `composer install --no-dev`، `npm ci && npm run build`، cacheها، `php artisan queue:restart`. migrationها فقط اضافه‌کننده‌اند؛ `migrate:rollback` را فقط پس از بررسی `down()` و پشتیبان تازه اجرا کنید. فاکتورهای صادرشده snapshot دارند و با بازگشت کد تغییر نمی‌کنند.

## ۱۳. سرور آزمایشی روی هاست cPanel (zarlio.ir) — 2026-10-09

جایگزین هاستینگر (که کنار گذاشته شد؛ اسکریپت و کار CI قدیمی حذف شدند و secretهای `HOSTINGER_SSH_*` باید از تنظیمات مخزن پاک شوند). **تولید همچنان PostgreSQL با زمان‌بند و صف است**؛ این حالت برای آزمون است.

### چرا شاخه `cpanel-release`
هاست ترمینال، Composer و Node ندارد، و `vendor/` و `public/build` در گیت نیستند. کار `publish-cpanel-release` در `.github/workflows/ci.yml` پس از سبز شدن تست‌ها برنامه را کامل می‌سازد (`composer install --no-dev` و `npm run build`) و نتیجه را در شاخه `cpanel-release` می‌گذارد (حدود ۱۲۰ مگابایت vendor؛ هر انتشار یک commit جدید روی همان شاخه؛ تاریخچه بازنویسی نمی‌شود تا «Update from Remote» کار کند). این شاخه خروجی تولیدشده است و دستی ویرایش نمی‌شود. `.gitignore` عمداً در آن نیست.

### راه‌اندازی یک‌باره در cPanel
1. **PHP:** MultiPHP Manager › دامنه `zarlio.ir` › نسخه ۸٫۳ (افزونه‌های `intl`، `gd`، `mbstring`، `pdo_sqlite`، `bcmath`، `openssl` فعال باشند).
2. **دسترسی خواندنی به مخزن خصوصی** (یکی از دو راه):
   - *کلید استقرار (بهتر):* cPanel › SSH Access › Manage SSH Keys › Generate a New Key › Authorize. سپس کلید عمومی را در گیت‌هاب: Settings › Deploy keys › Add (بدون Allow write). آدرس clone: `git@github.com:hassantafreshi/talata.ir.git`.
   - *اگر بخش SSH Access در cPanel نیست:* یک Fine-grained Personal Access Token فقط برای همین مخزن و فقط `Contents: Read-only` بسازید و آدرس clone را `https://<TOKEN>@github.com/hassantafreshi/talata.ir.git` بدهید (توکن در `.git/config` سرور می‌ماند).
3. **Git Version Control › Create:** تیک Clone a Repository، آدرس بالا، Repository Path مثلاً `repositories/talata.ir`. پس از ساخت: Manage › *Checked-Out Branch* را روی **`cpanel-release`** بگذارید.
   (شاخه `cpanel-release` با اولین اجرای موفق CI ساخته می‌شود؛ تا آن موقع در گیت‌هاب ببینید که وجود دارد.)
4. **Deploy:** Manage › Pull or Deploy › *Update from Remote* و سپس *Deploy HEAD Commit*. خروجی در `~/zarlio/deploy.log` است (File Manager). اولین استقرار این‌ها را می‌سازد: `~/zarlio/{releases,current,shared}` و فایل `.env`.
5. **کلیدها:** در File Manager فایل `~/zarlio/shared/.env` را باز کنید و `KAVENEGAR_API_KEY` و `BRSAPI_KEY` را بنویسید (در مخزن و CI نیستند). ذخیره کنید؛ بدون استقرار دوباره اعمال می‌شود (عمداً `config:cache` اجرا نمی‌شود).
6. **SSL:** cPanel › SSL/TLS Status › Run AutoSSL برای `zarlio.ir` (کوکی‌ها `Secure` هستند).
7. **زمان‌بند:** cPanel › Cron Jobs › هر دقیقه؛ خط دقیق (با مسیر PHP همان هاست) در انتهای `deploy.log` چاپ می‌شود، شکل کلی: `cd ~/zarlio/current && /usr/local/bin/ea-php83 artisan schedule:run >/dev/null 2>&1`.

### چیدمان و رفتار
- `~/public_html` (ریشه دامنه، غیرقابل تغییر) فقط **نسخه کپی‌شده `public/`** + یک `index.php` می‌گیرد که به `~/zarlio/current` اشاره می‌کند؛ `.env`، `vendor` و کد داخل آن نیستند. `index.html` پیش‌فرض cPanel و `.htaccess` قبلی به `~/zarlio/before-zarlio` منتقل/کپی می‌شوند و بلوک «php -- BEGIN cPanel-generated handler» که MultiPHP می‌نویسد در `.htaccess` جدید حفظ می‌شود.
- هر استقرار: پوشه `releases/<نسخه>`، migration و seed (idempotent)، ساخت مدیر سامانه (`TEST_ADMIN_MOBILE`)، همگام‌سازی `public_html`، سوییچ `current`، نگه‌داشتن سه نسخه آخر، `talata:preflight --live`. برگشت به نسخه سالم: commit مشکل‌دار را در شاخه کاری revert و push کنید؛ CI نسخه سالم را دوباره می‌سازد و با «Update from Remote» و «Deploy HEAD Commit» (یا استقرار خودکار) فعال می‌شود. پوشه‌های سه نسخه آخر در `releases` می‌مانند.
- `~/zarlio/shared/{.env,storage,database}` بین نسخه‌ها می‌مانند. حذف کامل: پوشه `~/zarlio` را پاک کنید و فایل‌های `before-zarlio` را برگردانید.
- **پایگاه داده:** SQLite در `~/zarlio/shared/database/zarlio.sqlite` (آزموده در CI با `phpunit.sqlite.xml`؛ یک نویسنده در لحظه). **MySQL/MariaDB این هاست استفاده نشده**: برنامه و آزمون‌ها فقط روی PostgreSQL و SQLite اجرا شده‌اند و MySQL بدون کار و آزمون جدا پشتیبانی نمی‌شود.
- **بدون کارگر دائمی:** `QUEUE_CONNECTION=sync` و `TALATA_QUOTES_REFRESH_ON_READ=true` (نرخ هنگام خواندن تازه می‌شود)؛ کارهای دوره‌ای با cron بالا.
- **تنظیمات آزمایشی `.env`:** `APP_ENV=staging`، درگاه پرداخت `mock`، `TALATA_STARTER_SMS_CREDIT_TOMAN=50000`، `TALATA_ADMIN_REQUIRE_PASSKEY=false`. **پیش از راه‌اندازی واقعی** باید درگاه زرین‌پال، PostgreSQL، کلید عبور اجباری مدیر و صف واقعی جایگزین شوند (بخش‌های ۲ تا ۷ همین سند).

### استقرار خودکار پس از هر push (اختیاری)
بدون آن، پس از هر انتشار باید در cPanel دو دکمه را بزنید. برای خودکار شدن، در cPanel › Security › **Manage API Tokens** یک توکن بسازید و در گیت‌هاب (Settings › Secrets and variables › Actions) این secretها را بگذارید: `CPANEL_HOST` (مثل `3114551444.cloudylink.com`)، `CPANEL_USER`، `CPANEL_TOKEN`، `CPANEL_REPO` (مسیر مخزن روی سرور، مثل `/home/<کاربر>/repositories/talata.ir`) و اختیاری `ZARLIO_SITE_URL` (`https://zarlio.ir`) برای تطبیق نسخه زنده از `release.txt`. CI سپس `VersionControl/update` و `VersionControlDeployment/create` را از API رسمی cPanel صدا می‌زند؛ اگر پاسخ نداد، فقط هشدار می‌دهد و دو دکمه دستی کار می‌کنند. **این مرحله هنوز روی هاست واقعی آزموده نشده است.**

### هاست بدون Shell: استقرار با PHP خالص (آزموده‌شده در sandbox، نه روی هاست واقعی)
اگر هاست Cron را فقط برای فایل PHP می‌پذیرد و توابع `exec`/`escapeshellarg` بسته است، اسکریپت bash اجرا نمی‌شود و «Git Version Control» هم ممکن است دسترسی Shell نخواهد. فایل `scripts/deploy/deploy.php` همان کار را با توابع فایل PHP و اجرای Artisan درون همان پردازش انجام می‌دهد (بدون هیچ تابع shell).
1. `zarlio-release.zip` (خروجی CI، artifact) را **بیرون از `public_html`** در `~/zarlio-src` باز کنید.
2. cPanel › Cron Jobs › هر دقیقه › `/usr/local/bin/php /home/<کاربر>/zarlio-src/scripts/deploy/deploy.php`؛ پس از یک دقیقه Cron را حذف کنید. خروجی: `~/zarlio/deploy.log`.
3. پوشه استخراج‌شده **منتقل** می‌شود به `~/zarlio/releases/<نسخه>` (بدون کپی)، پس Cron یک‌بارمصرف است و بعد از اجرا مسیرش دیگر وجود ندارد. برای نسخه بعد دوباره zip را در `~/zarlio-src` باز کنید و یک‌بار Cron بسازید.
4. زمان‌بند دائمی (هر دقیقه؛ بدون `cd`، `&&` و تغییر مسیر خروجی): `/opt/alt/php83/usr/bin/php /home/<کاربر>/zarlio/current/artisan talata:tick`. **نه `schedule:run`**: آن هر کار را با `proc_open` به‌صورت پردازش شل اجرا می‌کند و روی هاستی که `proc_open` بسته است خطای «The Process class relies on proc_open» می‌دهد؛ `talata:tick` همان کارهای موعددار را داخل همین پردازش PHP اجرا می‌کند.
5. نیاز: افزونه `pdo_sqlite` در PHP هاست (Select PHP Version › Extensions)؛ اگر نباشد، اجرا با پیام روشن متوقف می‌شود.
