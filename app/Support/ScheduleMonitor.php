<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;

/**
 * Records the last run of each scheduled job (start, end, duration, result) for the admin
 * «سلامت سیستم» page. Laravel's scheduler keeps no history of its own.
 */
final class ScheduleMonitor
{
    /** key => [label, cadence label]; the order is the table order. */
    public const JOBS = [
        'quotes' => ['دریافت مرکزی نرخ‌ها', 'هر ۳ دقیقه (۱۸۰ ثانیه)'],
        'payments-reconcile' => ['استعلام پرداخت‌های نامعلوم و انقضای سفارش‌ها', 'هر دقیقه'],
        'sms-reconcile' => ['وضعیت پیامک‌های نامعلوم', 'هر دقیقه'],
        'sms-credit-expire' => ['انقضای اعتبار ماهانه پیامک', 'هر ۵ دقیقه'],
        'reminders' => ['یادآوری پیامکی قسط', 'هر ساعت'],
        'affiliate-approve' => ['قابل‌پرداخت شدن کمیسیون همکاران', 'هر ساعت'],
        'logs-prune' => ['پاک‌سازی لاگ فنی قدیمی', 'روزانه ۰۳:۳۰'],
        'failed-prune' => ['پاک‌سازی کارهای ناموفق قدیمی صف', 'روزانه'],
    ];

    public static function track(Event $event, string $key): Event
    {
        return $event
            ->before(fn () => Cache::forever("talata.sched.{$key}.started", now()->getTimestampMs()))
            ->onSuccess(fn () => self::finish($key, true))
            ->onFailure(fn () => self::finish($key, false));
    }

    private static function finish(string $key, bool $ok): void
    {
        $started = (int) Cache::get("talata.sched.{$key}.started", now()->getTimestampMs());
        Cache::forever("talata.sched.{$key}", ['at' => now()->toIso8601String(), 'ok' => $ok, 'duration_ms' => max(0, now()->getTimestampMs() - $started)]);
    }

    /** @return array{at:?string,ok:?bool,duration_ms:?int} */
    public static function last(string $key): array
    {
        return Cache::get("talata.sched.{$key}") ?? ['at' => null, 'ok' => null, 'duration_ms' => null];
    }
}
