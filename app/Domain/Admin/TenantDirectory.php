<?php

namespace App\Domain\Admin;

use App\Domain\Plans\CommercialConfig;
use App\Models\BillingOrder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ShopProfile;
use App\Models\SmsCreditLot;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Mobile;
use App\Support\Tokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Admin tenant list (docs/handoff/04_SCREENS_ADMIN.md A-02): filters over plan, status, quota use and
 * period end, plus per-row usage. Never touches merchant customers' names or mobiles.
 */
final class TenantDirectory
{
    public const PLANS_FA = ['free' => 'رایگان', 'basic' => 'پایه', 'professional' => 'حرفه‌ای'];

    public const STATUS_FA = ['active' => 'فعال', 'suspended' => 'تعلیق', 'incomplete' => 'پروفایل ناقص'];

    public const QUOTA_FA = ['at_cap' => 'به سقف رسیده', 'near_cap' => 'نزدیک سقف (۸۰٪)'];

    public const PERIOD_FA = ['soon' => 'پایان تا ۷ روز', 'expired' => 'تمام‌شده در ۳۰ روز اخیر'];

    private const PLAN_SQL = "coalesce((select s.plan_code from subscriptions s where s.tenant_id = tenants.id and s.status = 'active' and s.starts_at <= CURRENT_TIMESTAMP and s.ends_at > CURRENT_TIMESTAMP order by s.ends_at desc limit 1), 'free')";

    public function __construct(private readonly CommercialConfig $config) {}

    public function query(array $f): Builder
    {
        $q = Tenant::query()->orderByDesc('tenants.id');
        $s = trim(Digits::toLatin((string) ($f['q'] ?? '')));
        if ($s !== '') {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $mobile = Mobile::normalize($s);
            $q->where(function ($w) use ($s, $like, $mobile) {
                $w->where('tenants.public_id', strtolower($s))
                    ->orWhereIn('tenants.id', ShopProfile::withoutGlobalScope('tenant')->whereLike('name', $like)->select('tenant_id'))
                    ->orWhereIn('tenants.id', BillingOrder::withoutGlobalScope('tenant')->where('public_ref', strtoupper($s))->select('tenant_id'));
                if ($mobile) {
                    $w->orWhereIn('tenants.id', ShopProfile::withoutGlobalScope('tenant')->where('business_mobile', $mobile)->select('tenant_id'));
                }
                if (Tokens::isWellFormed($s)) {
                    $w->orWhereIn('tenants.id', Invoice::withoutGlobalScope('tenant')->where('verify_token_hash', Tokens::hash($s))->select('tenant_id'));
                }
                if (ctype_digit($s)) {
                    $w->orWhere('tenants.id', (int) $s);
                }
            });
        }
        if (array_key_exists($f['plan'] ?? '', self::PLANS_FA)) {
            $q->whereRaw(self::PLAN_SQL.' = ?', [$f['plan']]);
        }
        match ($f['status'] ?? '') {
            'active' => $q->where('tenants.status', 'active'),
            'suspended' => $q->where('tenants.status', 'suspended'),
            'incomplete' => $q->whereIn('tenants.id', ShopProfile::withoutGlobalScope('tenant')
                ->where(fn ($w) => $w->whereNull('name')->orWhere('name', '')->orWhereNull('business_mobile')->orWhereNull('address')->orWhere('address', ''))->select('tenant_id')),
            default => null,
        };
        if (array_key_exists($f['quota'] ?? '', self::QUOTA_FA)) {
            [$start, $end] = Jalali::monthBounds(now(), config('talata.timezone'));
            $used = "(select count(*) from invoices i where i.tenant_id = tenants.id and i.status in ('issued','void') and i.issued_at >= ? and i.issued_at < ?)";
            $limit = 'CASE '.self::PLAN_SQL;
            $bindings = [];
            foreach (array_keys(self::PLANS_FA) as $code) {
                $cap = $this->config->plan($code)['quotas']['invoices_per_month'] ?? null;
                $limit .= ' WHEN ? THEN '.($cap === null ? 'NULL' : (int) $cap);
                $bindings[] = $code;
            }
            $limit .= ' END';
            $f['quota'] === 'at_cap'
                ? $q->whereRaw("$used >= ($limit)", [$start, $end, ...$bindings])
                : $q->whereRaw("$used >= ($limit) * 0.8 and $used < ($limit)", [$start, $end, ...$bindings, $start, $end, ...$bindings]);
        }
        match ($f['period'] ?? '') {
            'soon' => $q->whereIn('tenants.id', Subscription::withoutGlobalScope('tenant')->where('status', 'active')
                ->whereBetween('ends_at', [now(), now()->addDays(7)])->select('tenant_id')),
            'expired' => $q->whereIn('tenants.id', Subscription::withoutGlobalScope('tenant')->where('status', 'active')
                ->whereBetween('ends_at', [now()->subDays(30), now()])->select('tenant_id'))
                ->whereRaw(self::PLAN_SQL." = 'free'"),
            default => null,
        };

        return $q;
    }

    /** Per-row facts for a page of tenants, keyed by tenant id. */
    public function rows(Collection $tenants): Collection
    {
        $ids = $tenants->pluck('id');
        $tz = config('talata.timezone');
        [$start, $end] = Jalali::monthBounds(now(), $tz);
        $profiles = ShopProfile::withoutGlobalScope('tenant')->whereIn('tenant_id', $ids)->get()->keyBy('tenant_id');
        $subs = Subscription::withoutGlobalScope('tenant')->whereIn('tenant_id', $ids)->where('status', 'active')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderBy('ends_at')->get()->keyBy('tenant_id');
        $invoices = Invoice::withoutGlobalScope('tenant')->whereIn('tenant_id', $ids)->whereIn('status', ['issued', 'void'])
            ->where('issued_at', '>=', $start)->where('issued_at', '<', $end)->selectRaw('tenant_id, count(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        $customers = Customer::withoutGlobalScope('tenant')->whereIn('tenant_id', $ids)->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw('tenant_id, count(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        $credit = SmsCreditLot::withoutGlobalScope('tenant')->whereIn('tenant_id', $ids)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->selectRaw('tenant_id, sum(remaining_irr) s')->groupBy('tenant_id')->pluck('s', 'tenant_id');

        return $tenants->mapWithKeys(function (Tenant $t) use ($profiles, $subs, $invoices, $customers, $credit, $tz) {
            $p = $profiles[$t->id] ?? null;
            $sub = $subs[$t->id] ?? null;
            $plan = $sub?->plan_code ?? 'free';
            $quotas = $this->config->plan($plan)['quotas'] ?? [];

            return [$t->id => [
                'name' => $p?->name, 'business_mobile' => $p?->business_mobile, 'complete' => $p?->isComplete() ?? false,
                'plan' => $plan, 'plan_fa' => self::PLANS_FA[$plan] ?? $plan, 'period' => $sub?->period,
                'ends_fa' => $sub ? Jalali::date($sub->ends_at, $tz) : null,
                'invoices' => (int) ($invoices[$t->id] ?? 0), 'invoice_limit' => $quotas['invoices_per_month'] ?? null,
                'customers' => (int) ($customers[$t->id] ?? 0), 'customer_limit' => $quotas['new_customers_per_month'] ?? null,
                'credit_irr' => (string) ($credit[$t->id] ?? '0'),
            ]];
        });
    }
}
