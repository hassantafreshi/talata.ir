#!/usr/bin/env bash
# Zarlio on a cPanel shared host, deployed by cPanel «Git Version Control» (docs/DEPLOYMENT.md §13).
# Runs ON the server from `.cpanel.yml` when «Deploy HEAD Commit» is pressed (or the cPanel API does it). The
# repository it runs in is the `cpanel-release` branch that CI builds: it already contains vendor/ and public/build.
#
# Layout (nothing outside these two places is touched):
#   ~/zarlio/releases/<id>   one folder per release (the newest three are kept)
#   ~/zarlio/current         -> the active release
#   ~/zarlio/shared/         .env, storage/ and the SQLite database: survive every release
#   ~/zarlio/deploy.log      output of the last deployments
#   ~/public_html            the web root cPanel gives the domain (cannot be moved): receives a COPY of the
#                            release's public/ files and a front controller that points at ~/zarlio/current.
#                            .env, vendor/ and the code are NOT inside it.
# Optional overrides in ~/zarlio/shared/deploy.conf:  ZARLIO_DOCROOT=...  ZARLIO_URL=https://...  ZARLIO_PHP=/path/to/php
# Remove everything: delete ~/zarlio and the files listed in ~/zarlio/before-zarlio (originals moved aside).
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HOME="${HOME:-$(getent passwd "$(id -u)" | cut -d: -f6)}"
APP="$HOME/zarlio"; SHARED="$APP/shared"
mkdir -p "$APP" "$SHARED"
exec > >(tee -a "$APP/deploy.log") 2>&1
echo "=== deploy $(date -u +%FT%TZ) from $SRC ==="

[ -f "$SHARED/deploy.conf" ] && . "$SHARED/deploy.conf"
DOCROOT="${ZARLIO_DOCROOT:-$HOME/public_html}"
SITE_URL="${ZARLIO_URL:-https://zarlio.ir}"
RID="$(cat "$SRC/REVISION" 2>/dev/null || git -C "$SRC" rev-parse --short=12 HEAD)"
case "$RID" in *[!A-Za-z0-9]*|"") echo "bad release id"; exit 2;; esac
REL="$APP/releases/$RID"
[ -d "$DOCROOT" ] || { echo "web root $DOCROOT does not exist"; exit 2; }
[ -f "$SRC/vendor/autoload.php" ] && [ -f "$SRC/public/build/manifest.json" ] || { echo "this is not a built release (vendor/ or public/build missing): deploy the cpanel-release branch"; exit 2; }

# PHP 8.3 CLI: cPanel keeps one binary per version (MultiPHP / CloudLinux), the default `php` is often older.
PHP=""
for c in "${ZARLIO_PHP:-}" php8.3 /usr/local/bin/ea-php83 /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php83/usr/bin/php php; do
  [ -n "$c" ] || continue
  p="$(command -v "$c" 2>/dev/null || true)"; [ -n "$p" ] || continue
  if "$p" -r 'exit(version_compare(PHP_VERSION, "8.3.0") < 0 ? 1 : 0);' 2>/dev/null; then PHP="$p"; break; fi
done
[ -n "$PHP" ] || { echo "PHP 8.3+ CLI not found. In cPanel › MultiPHP Manager choose 8.3 for the domain; or set ZARLIO_PHP in $SHARED/deploy.conf"; exit 3; }
echo "php: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
for ext in pdo_sqlite mbstring intl gd bcmath openssl; do "$PHP" -m | grep -qi "^$ext$" || echo "warning: PHP CLI extension $ext not loaded"; done

# ---- release folder -------------------------------------------------------------------------------------------
rm -rf "$REL"; mkdir -p "$REL"
(cd "$SRC" && tar cf - --exclude=.git .) | tar xf - -C "$REL"
mkdir -p "$REL/bootstrap/cache"

for d in app/public app/private framework/cache/data framework/sessions framework/views logs; do mkdir -p "$SHARED/storage/$d"; done
rm -rf "$REL/storage"; ln -s "$SHARED/storage" "$REL/storage"
mkdir -p "$SHARED/database"; DB="$SHARED/database/zarlio.sqlite"; [ -f "$DB" ] || touch "$DB"; chmod 600 "$DB"

# ---- .env: created once, then edited by hand (cPanel › File Manager › zarlio/shared/.env) ---------------------
ENVF="$SHARED/.env"
if [ ! -f "$ENVF" ]; then
  KEY="base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  cat > "$ENVF" <<ENV
APP_NAME=Zarlio
APP_ENV=staging
APP_KEY=$KEY
APP_DEBUG=false
APP_URL=$SITE_URL
APP_LOCALE=fa
LOG_CHANNEL=stack
LOG_LEVEL=info
DB_CONNECTION=sqlite
DB_DATABASE=$DB
DB_LOG_CONNECTION=sqlite
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
TALATA_SMS_DRIVER=kavenegar
KAVENEGAR_API_KEY=
KAVENEGAR_SENDER=10007770000707
TALATA_QUOTE_DRIVER=brsapi
BRSAPI_KEY=
TALATA_QUOTES_REFRESH_ON_READ=true
TALATA_PAYMENT_DRIVER=mock
TALATA_SUPPORT_PHONE=09396727215
TALATA_STARTER_SMS_CREDIT_TOMAN=50000
TALATA_ADMIN_REQUIRE_PASSKEY=false
TEST_ADMIN_MOBILE=09396727215
ENV
  chmod 600 "$ENVF"
  echo "created $ENVF — fill KAVENEGAR_API_KEY and BRSAPI_KEY there (File Manager), then deploy once more"
fi
ln -sf "$ENVF" "$REL/.env"

# ---- database and caches ---------------------------------------------------------------------------------------
cd "$REL"
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --force || echo "warning: seeding stopped early (usually the quote service key is missing); fix .env and deploy again"
ADMIN_MOBILE="$(grep '^TEST_ADMIN_MOBILE=' "$ENVF" | cut -d= -f2- || true)"
[ -n "$ADMIN_MOBILE" ] && { "$PHP" artisan talata:staff "$ADMIN_MOBILE" "مدیر سامانه" --role=admin || true; }
# Not config:cache on purpose: there is no terminal on this host, so a hand-edited .env must take effect at once.
"$PHP" artisan route:cache && "$PHP" artisan view:cache

# ---- web root: copy of public/ + a front controller that points at the release --------------------------------
BEFORE="$APP/before-zarlio"; mkdir -p "$BEFORE"
for f in index.html index.htm default.html default.htm; do   # a placeholder page would win over index.php
  [ -f "$DOCROOT/$f" ] && [ ! -e "$BEFORE/$f" ] && { mv "$DOCROOT/$f" "$BEFORE/$f"; echo "moved aside: $DOCROOT/$f"; }
done
[ -f "$DOCROOT/.htaccess" ] && [ ! -e "$BEFORE/.htaccess" ] && cp -p "$DOCROOT/.htaccess" "$BEFORE/.htaccess"

# cPanel › MultiPHP Manager writes its PHP handler block into the web root's .htaccess: keep it.
HT_OLD="$DOCROOT/.htaccess"; HT_NEW="$REL/public/.htaccess.zarlio"
{
  [ -f "$HT_OLD" ] && sed -n '/^# php -- BEGIN cPanel-generated handler/,/^# php -- END cPanel-generated handler/p' "$HT_OLD"
  cat "$REL/public/.htaccess"
} > "$HT_NEW"

cp -a "$REL/public/." "$DOCROOT/"
mv -f "$DOCROOT/.htaccess.zarlio" "$DOCROOT/.htaccess"
# Front controller = the release's public/index.php with its ../ paths made absolute, and public_path() = web root.
sed -e "s#__DIR__\.'/\.\./#'$APP/current/#g" -e "s#^\(.*bootstrap/app\.php.*\)\$#\1\n\$app->usePublicPath(__DIR__);#" "$REL/public/index.php" > "$DOCROOT/index.php"
grep -q "usePublicPath" "$DOCROOT/index.php" && grep -q "$APP/current/vendor/autoload.php" "$DOCROOT/index.php" || { echo "could not build the front controller"; exit 4; }
rm -f "$DOCROOT/storage"; ln -sfn "$SHARED/storage/app/public" "$DOCROOT/storage"
find "$DOCROOT/build/assets" -type f -mtime +7 -delete 2>/dev/null || true   # hashed assets of releases older than a week

ln -sfn "$REL" "$APP/current"
ls -1dt "$APP/releases"/*/ | tail -n +4 | xargs -r rm -rf

echo "--- preflight (read-only) ---"
(cd "$APP/current" && "$PHP" artisan talata:preflight --live) || true
echo "--- cron line for cPanel › Cron Jobs (every minute) ---"
echo "* * * * * cd $APP/current && $PHP artisan schedule:run >/dev/null 2>&1"
echo "deployed $RID"
