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
        [0, 2, 180],     // 00:00–02:00 every 3 min
        [2, 6, 1200],    // 02:00–06:00 every 20 min
        [6, 8, 300],     // 06:00–08:00 every 5 min
        [8, 9, 180],     // 08:00–09:00 every 3 min
        [9, 10, 120],    // 09:00–10:00 every 2 min
        [10, 12, 60],    // 10:00–12:00 every 1 min
        [12, 16, 60],    // 12:00–16:00 every 1 min
        [16, 20, 60],    // 16:00–20:00 every 1 min
        [20, 24, 120],   // 20:00–24:00 every 2 min
    ];

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
