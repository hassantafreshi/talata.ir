#!/usr/bin/env bash
# Zarlio TEST server on Hostinger shared hosting (docs/DEPLOYMENT.md §«سرور آزمایشی هاستینگر»).
# Runs ON the server, called by the CI job «deploy-test-server». Everything stays inside ONE site folder:
#   ~/domains/<domain>/zarlio/        releases, shared .env, storage and the SQLite file
#   ~/domains/<domain>/public_html    → symlink to the current release's public/ (the original is kept as
#                                       public_html.before-zarlio)
# Removing the test install = delete ~/domains/<domain>/zarlio and restore public_html.before-zarlio.
#
# Usage: hostinger-test.sh <domain-dir relative to $HOME> <release.tgz> <release-id> [secrets-file]
set -euo pipefail

BASE="$HOME/$1"; TGZ="$2"; RID="$3"; SECRETS="${4:-}"
case "$1" in *..*|/*) echo "bad domain dir"; exit 2;; esac
[ -d "$BASE" ] || { echo "no site folder $BASE"; exit 2; }
APP="$BASE/zarlio"; REL="$APP/releases/$RID"; SHARED="$APP/shared"

PHP="$(command -v php8.3 || command -v php)"
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0") < 0 ? 1 : 0);' || { echo "PHP 8.3+ needed (found $("$PHP" -r 'echo PHP_VERSION;')); choose it in hPanel → PHP"; exit 3; }
for ext in pdo_sqlite mbstring intl gd; do "$PHP" -m | grep -qi "^$ext$" || echo "warning: PHP extension $ext not loaded"; done

mkdir -p "$APP/releases" "$SHARED/database" "$SHARED/storage"
rm -rf "$REL"; mkdir -p "$REL"
tar xzf "$TGZ" -C "$REL"; rm -f "$TGZ"

# Shared storage (logs, sessions, cache, logos) survives releases.
for d in app/public app/private framework/cache/data framework/sessions framework/views logs; do mkdir -p "$SHARED/storage/$d"; done
rm -rf "$REL/storage"; ln -s "$SHARED/storage" "$REL/storage"
DB="$SHARED/database/zarlio-test.sqlite"; [ -f "$DB" ] || touch "$DB"
chmod 600 "$DB"

ENVF="$SHARED/.env"
if [ ! -f "$ENVF" ]; then
  KEY="base64:$("$PHP" -r 'echo base64_encode(random_bytes(32));')"
  cat > "$ENVF" <<ENV
APP_NAME=Zarlio
APP_ENV=staging
APP_KEY=$KEY
APP_DEBUG=false
APP_URL=https://$(basename "$BASE")
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
TALATA_QUOTE_DRIVER=brsapi
TALATA_QUOTES_REFRESH_ON_READ=true
TALATA_PAYMENT_DRIVER=mock
TALATA_SUPPORT_PHONE=09396727215
ENV
  chmod 600 "$ENVF"
fi
# Secrets from the CI job (BRSAPI_KEY, KAVENEGAR_API_KEY, KAVENEGAR_SENDER…): set or replace, never printed.
if [ -n "$SECRETS" ] && [ -f "$SECRETS" ]; then
  while IFS='=' read -r k v; do
    [ -n "$k" ] || continue
    case "$k" in *[!A-Z0-9_]*) continue;; esac
    grep -v "^$k=" "$ENVF" > "$ENVF.tmp" || true
    printf '%s=%s\n' "$k" "$v" >> "$ENVF.tmp"; mv "$ENVF.tmp" "$ENVF"
  done < "$SECRETS"
  rm -f "$SECRETS"; chmod 600 "$ENVF"
fi
ln -sf "$ENVF" "$REL/.env"

cd "$REL"
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --force   # idempotent baseline: pricing v1, sample tax rules, first quote fetch
ADMIN_MOBILE="$(grep '^TEST_ADMIN_MOBILE=' "$ENVF" | cut -d= -f2- || true)"
[ -n "$ADMIN_MOBILE" ] && "$PHP" artisan talata:staff "$ADMIN_MOBILE" "مدیر سامانه" --role=admin || true
# `php artisan storage:link` needs PHP's symlink()/exec(), both disabled on this host's PHP config
# (common shared-hosting hardening): it would fail silently and public/storage would never exist. Make the
# same link (config/filesystems.php: public_path('storage') => storage_path('app/public')) with the shell
# instead, which has its own symlink capability independent of PHP's disabled functions.
ln -sfn "../storage/app/public" "$REL/public/storage"
"$PHP" artisan config:cache && "$PHP" artisan route:cache && "$PHP" artisan view:cache

ln -sfn "$REL" "$APP/current"
if [ -e "$BASE/public_html" ] && [ ! -L "$BASE/public_html" ]; then mv "$BASE/public_html" "$BASE/public_html.before-zarlio"; fi
ln -sfn "$APP/current/public" "$BASE/public_html"

# Keep the three newest releases.
ls -1dt "$APP/releases"/*/ | tail -n +4 | xargs -r rm -rf

echo "--- preflight (live, read-only) ---"
"$PHP" artisan talata:preflight --live || true
echo "deployed $RID"
