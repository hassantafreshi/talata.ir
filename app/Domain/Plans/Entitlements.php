<?php

namespace App\Domain\Plans;

use App\Models\Customer;
use App\Models\FeatureOverride;
use App\Models\Invoice;
use App\Models\InvoiceShare;
use App\Models\SmsMessage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Digits;
use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * Server-side capability and quota engine. Domain code asks can()/quota(); it never
 * branches on plan names (docs/PLANS_AND_QUOTAS.md). Counts are derived from the
 * source records inside the caller's transaction, so a tenant row lock makes them race-free.
 */
final class Entitlements
{
    public const RESOURCES = [
        'invoices_per_month' => 'فاکتور',
        'new_customers_per_month' => 'مشتری جدید',
        'links_per_month' => 'لینک فاکتور',
    ];

    /** Capabilities staff may override per shop (with expiry). Always-on entries (مظنه, calculator, printing) are not listed. */
    public const OVERRIDABLE_FA = [
        'invoice.finalize' => 'صدور فاکتور',
        'invoice.sms_share' => 'ارسال پیامکی فاکتور',
        'invoice.customize' => 'شخصی‌سازی فاکتور',
        'invoice.shop_logo' => 'لوگوی فروشگاه روی فاکتور',
        'invoice.hide_provider_brand' => 'حذف نام زرلیو از پای فاکتور',
        'customers.manage' => 'مدیریت مشتریان',
        'installments.manage' => 'اقساط',
        'installments.sms_remind' => 'یادآوری پیامکی قسط',
        'history.all' => 'دسترسی به سوابق ماه‌های قبل',
        'reports.financial' => 'گزارش مالی',
        'dashboard.view' => 'داشبورد فروش',
        'settings.backup' => 'پشتیبان تنظیمات',
        'sms.template_edit' => 'ویرایش متن پیامک فاکتور',
        'team.permissions_edit' => 'تعیین دسترسی همکاران',
    ];

    public function __construct(private readonly CommercialConfig $config) {}

    public function subscription(Tenant $tenant): ?Subscription
    {
        return Subscription::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
            ->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->orderByDesc('ends_at')->first();
    }

    public function planCode(Tenant $tenant): string
    {
        return $this->subscription($tenant)?->plan_code ?? 'free';
    }

    public function plan(Tenant $tenant): array
    {
        return $this->config->plan($this->planCode($tenant)) + ['code' => $this->planCode($tenant)];
    }

    public function can(Tenant $tenant, string $capability): bool
    {
        $override = FeatureOverride::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('key', $capability)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->first();
        if ($override) {
            return (bool) ($override->value['enabled'] ?? false);
        }

        return (bool) ($this->plan($tenant)['capabilities'][$capability] ?? false);
    }

    /** Whether the tenant's plan (in the active pricing version) defines the capability at all. */
    public function defines(Tenant $tenant, string $capability): bool
    {
        return array_key_exists($capability, $this->plan($tenant)['capabilities'] ?? []);
    }

    /** Quota with a safe default when an older pricing version does not define it (null = unlimited). */
    public function limitOr(Tenant $tenant, string $resource, ?int $default): ?int
    {
        $quotas = $this->plan($tenant)['quotas'] ?? [];

        return array_key_exists($resource, $quotas) ? ($quotas[$resource] === null ? null : (int) $quotas[$resource]) : $default;
    }

    public function limit(Tenant $tenant, string $resource): ?int
    {
        $value = $this->plan($tenant)['quotas'][$resource] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** @return array{used:int,limit:?int,remaining:?int,starts_at:CarbonImmutable,resets_at:CarbonImmutable,resets_at_fa:string} */
    public function quota(Tenant $tenant, string $resource): array
    {
        [$start, $end] = Jalali::monthBounds(now(), $tenant->timezone);
        $used = match ($resource) {
            'invoices_per_month' => Invoice::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
                ->whereIn('status', ['issued', 'void'])->where('issued_at', '>=', $start)->where('issued_at', '<', $end)->count(),
            'new_customers_per_month' => Customer::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
            'links_per_month' => InvoiceShare::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
            default => throw new \InvalidArgumentException($resource),
        };
        $limit = $this->limit($tenant, $resource);

        return [
            'used' => $used, 'limit' => $limit, 'remaining' => $limit === null ? null : max(0, $limit - $used),
            'starts_at' => $start, 'resets_at' => $end, 'resets_at_fa' => Jalali::date($end, $tenant->timezone),
        ];
    }

    public function assertQuota(Tenant $tenant, string $resource): void
    {
        $q = $this->quota($tenant, $resource);
        if ($q['limit'] !== null && $q['used'] >= $q['limit']) {
            $label = self::RESOURCES[$resource];
            throw new QuotaExceeded(
                'QUOTA_'.strtoupper($resource),
                Digits::toPersian((string) $q['limit'])." {$label} این ماه استفاده شد. سهمیه از {$q['resets_at_fa']} دوباره پر می‌شود؛ یا پلن را ارتقا دهید.",
                409,
                ['resource' => $resource, 'limit' => $q['limit'], 'resets_at_fa' => $q['resets_at_fa'], 'upgrade_url' => route('settings.plan')],
            );
        }
    }

    public function assertCan(Tenant $tenant, string $capability, string $messageFa): void
    {
        if (! $this->can($tenant, $capability)) {
            throw new QuotaExceeded('CAPABILITY_'.strtoupper(str_replace('.', '_', $capability)), $messageFa, 403, ['capability' => $capability, 'upgrade_url' => route('settings.plan')]);
        }
    }

    /** Free invoice SMS left in the rolling 365-day window (failed sends give the allowance back). */
    public function freeSmsRemaining(Tenant $tenant): int
    {
        $limit = (int) ($this->plan($tenant)['quotas']['free_sms_per_year'] ?? 0);
        if ($limit === 0) {
            return 0;
        }
        $used = SmsMessage::query()->forTenant($tenant->id)->where('charge_source', 'FREE_YEARLY')
            ->whereNotIn('status', ['FAILED', 'CANCELLED'])->where('created_at', '>=', now()->subDays(365))->count();

        return max(0, $limit - $used);
    }

    public function smsPerSegmentIrr(Tenant $tenant): string
    {
        return (string) ((int) $this->config->sms()['per_segment_toman'][$this->planCode($tenant)] * 10);
    }

    public function summary(Tenant $tenant): array
    {
        $sub = $this->subscription($tenant);
        $plan = $this->plan($tenant);
        $quotas = [];
        foreach (array_keys(self::RESOURCES) as $resource) {
            $q = $this->quota($tenant, $resource);
            $quotas[$resource] = ['used' => $q['used'], 'limit' => $q['limit'], 'remaining' => $q['remaining'], 'resets_at_fa' => $q['resets_at_fa']];
        }

        return [
            'plan' => ['code' => $plan['code'], 'label_fa' => $plan['label_fa'], 'period' => $sub?->period, 'ends_at_fa' => $sub ? Jalali::date($sub->ends_at, $tenant->timezone) : null],
            'capabilities' => $plan['capabilities'],
            'quotas' => $quotas,
            'free_sms_remaining' => $this->freeSmsRemaining($tenant),
            'free_sms_per_year' => (int) ($plan['quotas']['free_sms_per_year'] ?? 0),
        ];
    }
}
