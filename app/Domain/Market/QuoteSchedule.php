<?php

namespace App\Domain\Market;

use Carbon\CarbonInterface;

/**
 * How often the quote API is asked, by Tehran time of day (owner decision 2026-10-09). The requester itself
 * decides (QuoteService::refreshIfDue); the scheduler just wakes it every minute, so there is one cron entry.
 */
final class QuoteSchedule
{
    /** [from hour (inclusive), to hour (exclusive), seconds between requests] in Asia/Tehran. */
    public const WINDOWS = [
        [0, 2, 120],     // 00:00–02:00 every 2 min →  60 requests
        [2, 6, 180],     // 02:00–06:00 every 3 min →  80
        [6, 8, 120],     // 06:00–08:00 every 2 min →  60
        [8, 9, 120],     // 08:00–09:00 every 2 min →  30
        [9, 10, 60],     // 09:00–10:00 every 1 min →  60
        [10, 20, 60],    // 10:00–20:00 every 1 min → 600
        [20, 24, 120],   // 20:00–24:00 every 2 min → 120   (1,010 requests a day)
    ];

    /** Requests a window makes per day (shown in the admin console). */
    public static function requestsPerDay(int $from, int $to, int $seconds): int
    {
        return intdiv(($to - $from) * 3600, $seconds);
    }

    public const TIMEZONE = 'Asia/Tehran';

    public static function intervalSeconds(CarbonInterface $at): int
    {
        $hour = (int) $at->copy()->setTimezone(self::TIMEZONE)->format('G');
        foreach (self::WINDOWS as [$from, $to, $seconds]) {
            if ($hour >= $from && $hour < $to) {
                return $seconds;
            }
        }

        return 300;
    }
}
