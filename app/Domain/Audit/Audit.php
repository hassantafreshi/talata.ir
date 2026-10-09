<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Append-only activity log, attributable per merchant user, per staff member and per service.
 * Visible only in the administrator dashboard. Never pass OTP codes, tokens, keys or full card
 * numbers in $data.
 */
final class Audit
{
    /** Event prefix → service. */
    public const SERVICES = [
        'auth' => 'auth', 'passkey' => 'auth', 'invoice' => 'invoices', 'sms' => 'sms', 'sms_credit' => 'sms',
        'billing' => 'billing', 'customer' => 'customers', 'installment' => 'customers', 'membership' => 'team',
        'profile' => 'settings', 'layout' => 'settings', 'tenant' => 'tenants', 'admin' => 'admin', 'affiliate' => 'affiliate', 'pricing' => 'admin',
        'quotes' => 'market', 'tax' => 'admin', 'system' => 'admin',
    ];

    public const SERVICE_LABELS = [
        'auth' => 'ورود و امنیت', 'invoices' => 'فاکتور', 'sms' => 'پیامک', 'billing' => 'پرداخت', 'customers' => 'مشتری و اقساط',
        'team' => 'کاربران فروشگاه', 'settings' => 'تنظیمات', 'tenants' => 'فروشگاه', 'admin' => 'مدیریت', 'affiliate' => 'همکاری در فروش', 'market' => 'نرخ و مظنه', 'app' => 'سایر',
    ];

    public static function serviceFor(string $event): string
    {
        return self::SERVICES[explode('.', $event, 2)[0]] ?? 'app';
    }

    public static function record(string $event, ?Model $subject = null, array $data = [], ?int $tenantId = null, string $actorType = 'user'): void
    {
        $context = app(TenantContext::class);
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $staffId = Auth::guard('staff')->id();
        AuditEvent::create([
            'tenant_id' => $tenantId ?? $context->id(),
            'actor_user_id' => $actorType === 'user' ? Auth::guard('web')->id() : null,
            'staff_id' => $actorType === 'staff' ? $staffId : null,
            'actor_type' => $actorType,
            'event' => $event,
            'service' => self::serviceFor($event),
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject ? (string) ($subject->public_id ?? $subject->getKey()) : null,
            'data' => $data,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 200) : null,
            'request_id' => Context::get('request_id'),
            'created_at' => now(),
        ]);
    }
}
