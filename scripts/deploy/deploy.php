<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

/*
 * Zarlio on a cPanel host WITHOUT shell access: pure-PHP deployment (docs/DEPLOYMENT.md §13).
 *
 * Why: some hosts allow Cron only for PHP files and disable exec/shell functions, so the bash script
 * (cpanel-deploy.sh) cannot run. This file does the same job with plain PHP file functions and runs the Artisan
 * commands inside this process. It uses NO shell function (exec, shell_exec, system, passthru, proc_open, popen,
 * escapeshellarg).
 *
 * Use: upload and extract the release zip to ~/zarlio-src (outside public_html), then run this file once:
 *   Cron Jobs → Command:  /usr/local/bin/php /home/<user>/zarlio-src/scripts/deploy/deploy.php
 * Delete the cron job after the first minute. Output: ~/zarlio/deploy.log
 *
 * Layout (same as the bash script; nothing outside these two places is touched):
 *   ~/zarlio/releases/<id>   the extracted folder is MOVED here (instant, no copy); the newest three are kept
 *   ~/zarlio/current         -> the active release (a symlink)
 *   ~/zarlio/shared/         .env, storage/ and the SQLite database: survive every release
 *   ~/public_html            receives a COPY of the release's public/ files and a front controller pointing at
 *                            the release. .env, vendor/ and the code are NOT inside it.
 * Optional overrides in ~/zarlio/shared/deploy.conf (KEY=value lines): ZARLIO_DOCROOT, ZARLIO_URL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@ini_set('memory_limit', '1024M');
@set_time_limit(0);
@ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$SRC = dirname(__DIR__, 2);
$HOME = getenv('HOME') ?: dirname($SRC);
if (! is_dir($HOME.'/public_html')) {
    $HOME = dirname($SRC);
}
$APP = $HOME.'/zarlio';
$SHARED = $APP.'/shared';
@mkdir($SHARED, 0755, true);
$LOG = $APP.'/deploy.log';

function say(string $m): void
{
    global $LOG;
    $line = $m.PHP_EOL;
    echo $line;
    @file_put_contents($LOG, $line, FILE_APPEND);
}

function fail(string $m, int $code = 2): never
{
    say('ERROR: '.$m);
    exit($code);
}

function rrmdir(string $dir): void
{
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);

        return;
    }
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            rrmdir($dir.'/'.$f);
        }
    }
    @rmdir($dir);
}

function rcopy(string $from, string $to): void
{
    if (is_dir($from) && ! is_link($from)) {
        @mkdir($to, 0755, true);
        foreach (scandir($from) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                rcopy($from.'/'.$f, $to.'/'.$f);
            }
        }
    } else {
        @copy($from, $to);
    }
}

/** Symlink when the host allows it; otherwise copy (and say so). */
function link_or_copy(string $target, string $link): bool
{
    if (is_link($link) || is_file($link)) {
        @unlink($link);
    } elseif (is_dir($link)) {
        rrmdir($link);
    }
    if (function_exists('symlink') && @symlink($target, $link)) {
        return true;
    }
    rcopy($target, $link);
    say("note: symlink is not available, copied $target to $link instead");

    return false;
}

say('=== deploy '.gmdate('c')." from $SRC (pure PHP) ===");

// ---- checks ----------------------------------------------------------------------------------------------------
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    fail('PHP '.PHP_VERSION.' is too old; this cron must use PHP 8.3 (e.g. /usr/local/bin/ea-php83).', 3);
}
say('php: '.PHP_BINARY.' '.PHP_VERSION);
foreach (['pdo_sqlite', 'mbstring', 'intl', 'gd', 'bcmath', 'openssl', 'fileinfo', 'ctype', 'tokenizer', 'xml', 'curl'] as $ext) {
    if (! extension_loaded($ext)) {
        say("warning: PHP extension $ext is not loaded".($ext === 'pdo_sqlite' ? ' (SQLite cannot work without it: enable it in cPanel › Select PHP Version › Extensions)' : ''));
    }
}
if (! extension_loaded('pdo_sqlite')) {
    fail('pdo_sqlite is missing: the database cannot be created. Enable it in cPanel › Select PHP Version › Extensions, then run again.', 3);
}

$conf = [];
if (is_file($SHARED.'/deploy.conf')) {
    foreach (file($SHARED.'/deploy.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $l, $m)) {
            $conf[$m[1]] = trim($m[2], "\"'");
        }
    }
}
$DOCROOT = rtrim($conf['ZARLIO_DOCROOT'] ?? $HOME.'/public_html', '/');
$SITE_URL = $conf['ZARLIO_URL'] ?? 'https://zarlio.ir';
$RID = trim((string) @file_get_contents($SRC.'/REVISION'));
if ($RID === '' || preg_match('/[^A-Za-z0-9]/', $RID)) {
    $RID = gmdate('YmdHis');
}
$REL = $APP.'/releases/'.$RID;
if (! is_dir($DOCROOT)) {
    fail("web root $DOCROOT does not exist");
}
if (! is_file($SRC.'/vendor/autoload.php') || ! is_file($SRC.'/public/build/manifest.json')) {
    fail('this is not a built release (vendor/ or public/build missing): use the zarlio-release.zip from CI');
}
if (realpath($SRC) === realpath($DOCROOT) || str_starts_with((string) realpath($SRC), (string) realpath($DOCROOT).'/')) {
    fail('the release folder is inside the web root; move it outside public_html (e.g. ~/zarlio-src) and run again');
}

// ---- release folder: move the extracted folder (no copy); fall back to copy ------------------------------------
@mkdir($APP.'/releases', 0755, true);
if (is_dir($REL)) {
    rrmdir($REL);
}
if (! @rename($SRC, $REL)) {
    say('note: could not move the folder, copying it instead (slower)');
    rcopy($SRC, $REL);
    if (! is_file($REL.'/vendor/autoload.php')) {
        fail('copy failed (disk quota?)');
    }
}
say("release: $REL");
@mkdir($REL.'/bootstrap/cache', 0755, true);

foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $d) {
    @mkdir($SHARED.'/storage/'.$d, 0755, true);
}
$storageLinked = link_or_copy($SHARED.'/storage', $REL.'/storage');
@mkdir($SHARED.'/database', 0755, true);
$DB = $SHARED.'/database/zarlio.sqlite';
if (! is_file($DB)) {
    touch($DB);
}
@chmod($DB, 0600);

// ---- .env: created once, then edited by hand (File Manager › zarlio/shared/.env) --------------------------------
$ENVF = $SHARED.'/.env';
if (! is_file($ENVF)) {
    $key = 'base64:'.base64_encode(random_bytes(32));
    $env = <<<ENV
APP_NAME=Zarlio
APP_ENV=staging
APP_KEY=$key
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

ENV;
    file_put_contents($ENVF, $env);
    @chmod($ENVF, 0600);
    say("created $ENVF — fill KAVENEGAR_API_KEY and BRSAPI_KEY there (File Manager), then deploy once more");
}
$envLinked = link_or_copy($ENVF, $REL.'/.env');

// ---- database and caches: Artisan inside this process (no shell) ---------------------------------------------------
chdir($REL);
$artisan = function (string $cmd, array $args = []): int {
    try {
        $code = Artisan::call($cmd, $args);
        $out = trim(Artisan::output());
        if ($out !== '') {
            say($out);
        }

        return $code;
    } catch (Throwable $e) {
        say("artisan $cmd failed: ".get_class($e).': '.$e->getMessage());

        return 1;
    }
};
try {
    require $REL.'/vendor/autoload.php';
    $app = require $REL.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $e) {
    fail('the application did not start: '.$e->getMessage(), 4);
}

if ($artisan('migrate', ['--force' => true]) !== 0) {
    fail('migration failed (see above); the site was NOT switched to this release', 5);
}
if ($artisan('db:seed', ['--force' => true]) !== 0) {
    say('warning: seeding stopped early (usually the quote service key is missing); fix .env and deploy again');
}
$adminMobile = '';
if (preg_match('/^TEST_ADMIN_MOBILE=(.*)$/m', (string) file_get_contents($ENVF), $m)) {
    $adminMobile = trim($m[1]);
}
if ($adminMobile !== '') {
    $artisan('talata:staff', ['mobile' => $adminMobile, 'name' => 'مدیر سامانه', '--role' => 'admin']);
}
// Not config:cache on purpose: there is no terminal on this host, so a hand-edited .env must take effect at once.
$artisan('route:cache');
$artisan('view:cache');

// ---- web root: copy of public/ + a front controller that points at the release ------------------------------------
$BEFORE = $APP.'/before-zarlio';
@mkdir($BEFORE, 0755, true);
foreach (['index.html', 'index.htm', 'default.html', 'default.htm'] as $f) {   // a placeholder page would win over index.php
    if (is_file("$DOCROOT/$f") && ! file_exists("$BEFORE/$f")) {
        rename("$DOCROOT/$f", "$BEFORE/$f");
        say("moved aside: $DOCROOT/$f");
    }
}
$htOld = is_file($DOCROOT.'/.htaccess') ? (string) file_get_contents($DOCROOT.'/.htaccess') : '';
if ($htOld !== '' && ! file_exists($BEFORE.'/.htaccess')) {
    copy($DOCROOT.'/.htaccess', $BEFORE.'/.htaccess');
}
// cPanel › MultiPHP Manager writes its PHP handler block into the web root's .htaccess: keep it.
$handler = '';
if (preg_match('/^# php -- BEGIN cPanel-generated handler.*?^# php -- END cPanel-generated handler[^\n]*\n?/ms', $htOld, $m)) {
    $handler = rtrim($m[0])."\n";
}
$htNew = $handler.(string) file_get_contents($REL.'/public/.htaccess');

rcopy($REL.'/public', $DOCROOT);
file_put_contents($DOCROOT.'/.htaccess', $htNew);

$base = is_link($APP.'/current') || function_exists('symlink') ? $APP.'/current' : $REL;
$index = (string) file_get_contents($REL.'/public/index.php');
$index = str_replace("__DIR__.'/../", "'".$base.'/', $index);
$index = preg_replace('/^(.*bootstrap\/app\.php.*)$/m', "$1\n\$app->usePublicPath(__DIR__);", $index, 1);
if (! str_contains($index, 'usePublicPath') || ! str_contains($index, $base.'/vendor/autoload.php')) {
    fail('could not build the front controller', 4);
}
file_put_contents($DOCROOT.'/index.php', $index);
@unlink($DOCROOT.'/storage');
if (is_dir($DOCROOT.'/storage') && ! is_link($DOCROOT.'/storage')) {
    rrmdir($DOCROOT.'/storage');
}
if (function_exists('symlink')) {
    @symlink($SHARED.'/storage/app/public', $DOCROOT.'/storage');
}
foreach (glob($DOCROOT.'/build/assets/*') ?: [] as $f) {   // hashed assets of releases older than a week
    if (is_file($f) && filemtime($f) < time() - 7 * 86400) {
        @unlink($f);
    }
}

// ---- switch ------------------------------------------------------------------------------------------------------
if (function_exists('symlink')) {
    $tmp = $APP.'/current.new';
    @unlink($tmp);
    if (@symlink($REL, $tmp)) {
        rename($tmp, $APP.'/current');
    } else {
        say('warning: could not create the current link; the front controller points at the release folder directly');
    }
}
$releases = glob($APP.'/releases/*', GLOB_ONLYDIR) ?: [];
usort($releases, fn ($a, $b) => filemtime($b) <=> filemtime($a));
foreach (array_slice($releases, 3) as $old) {
    if (dirname($old) === $APP.'/releases') {
        rrmdir($old);
    }
}

say('--- preflight (read-only) ---');
$artisan('talata:preflight', ['--live' => true]);
say('--- cron line for cPanel › Cron Jobs (every minute) ---');
say('* * * * * '.PHP_BINARY.' '.$APP.'/current/artisan schedule:run');
say("deployed $RID");
