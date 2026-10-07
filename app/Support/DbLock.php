<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Transaction-scoped named lock. PostgreSQL: pg_advisory_xact_lock. SQLite (shared-hosting test server only):
 * nothing to do — transactions start IMMEDIATE there (config/database.php), so writers are already serialized.
 */
final class DbLock
{
    public static function key(string $name): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$name]);
        }
    }
}
