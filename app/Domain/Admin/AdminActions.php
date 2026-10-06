<?php

namespace App\Domain\Admin;

use App\Domain\DomainError;
use App\Models\AdminAction;
use App\Models\StaffUser;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runs a dangerous staff action exactly once per idempotency key (double click, retry after a
 * timeout, two tabs). The action and its admin_actions row commit together; a concurrent duplicate
 * fails on the unique key, rolls back its own effects and returns the stored result.
 */
final class AdminActions
{
    public static function validKey(mixed $key): string
    {
        $key = (string) $key;
        if (! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
            throw new DomainError('IDEMPOTENCY_KEY', 'درخواست نامعتبر است. صفحه را دوباره باز کنید.', 422);
        }

        return $key;
    }

    /** @param Closure():array $fn returns a JSON-safe result */
    public function once(StaffUser $staff, string $action, mixed $key, ?int $tenantId, Closure $fn): array
    {
        $key = self::validKey($key);
        try {
            return DB::transaction(function () use ($staff, $action, $key, $tenantId, $fn) {
                $done = AdminAction::query()->where('idempotency_key', $key)->first();
                if ($done) {
                    return $this->replay($done, $action, $staff);
                }
                $result = $fn();
                AdminAction::query()->create([
                    'idempotency_key' => $key, 'action' => $action, 'staff_id' => $staff->id, 'tenant_id' => $tenantId,
                    'result' => $result, 'created_at' => now(),
                ]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            $done = AdminAction::query()->where('idempotency_key', $key)->firstOrFail();

            return $this->replay($done, $action, $staff);
        }
    }

    private function replay(AdminAction $done, string $action, StaffUser $staff): array
    {
        if ($done->action !== $action || $done->staff_id !== $staff->id) {
            throw new DomainError('IDEMPOTENCY_KEY_REUSED', 'این درخواست قبلاً برای کار دیگری ثبت شده است. صفحه را دوباره باز کنید.', 409);
        }

        return $done->result + ['replayed' => true];
    }
}
