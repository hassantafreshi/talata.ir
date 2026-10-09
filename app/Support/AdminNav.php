<?php

namespace App\Support;

use App\Models\BillingOrder;
use App\Models\SmsMessage;
use App\Models\StaffUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/** Admin sidebar items and their counters (A-00). Counters are cached briefly: they are hints, not data. */
final class AdminNav
{
    /** @return list<array{route:string,label:string,count:?int,alert:bool,match:list<string>}> */
    public static function items(StaffUser $staff): array
    {
        $c = Cache::remember('talata.admin.nav_counts', 30, fn () => [
            'tenants' => Tenant::query()->count(),
            'payments' => BillingOrder::withoutGlobalScope('tenant')->where(fn ($w) => $w->whereIn('status', ['PENDING_VERIFICATION', 'PAID'])
                ->orWhere(fn ($v) => $v->where('status', 'VERIFYING')->where('updated_at', '<', now()->subMinutes(5))))->count(),
            'sms' => SmsMessage::query()->where(fn ($w) => $w->where(fn ($u) => $u->where('status', 'UNKNOWN')->where('updated_at', '<', now()->subMinutes(30)))
                ->orWhere('status', 'AWAITING_CREDIT'))->count(),
        ]);
        $items = [
            ['admin.dashboard', 'داشبورد', null, false],
            ['admin.tenants', 'فروشگاه‌ها', $c['tenants'], false],
            ['admin.payments', 'پرداخت‌ها', $c['payments'] ?: null, $c['payments'] > 0],
            ['admin.sms', 'پیامک', $c['sms'] ?: null, $c['sms'] > 0],
            ['admin.pricing', 'قیمت پلن و پیامک', null, false],
            ['admin.quotes', 'نرخ و مظنه', null, false],
            ['admin.tax', 'قواعد مالیات', null, false],
            ['admin.affiliates', 'همکاری در فروش', null, false],
            ['admin.integrations', 'اتصال‌ها', null, false],
            ['admin.staff', 'کارکنان و دسترسی', null, false],
            ['admin.activity', 'سوابق (لاگ فعالیت)', null, false],
        ];
        if ($staff->allows('logs.tech')) {
            $items[] = ['admin.tech', 'لاگ فنی', null, false];
        }
        $items[] = ['admin.system', 'سلامت سیستم', null, false];
        $items[] = ['admin.account', 'حساب من', null, false];

        // A section stays highlighted on its detail pages (admin.tenants → admin.tenant/{id}).
        return array_map(fn ($i) => ['route' => $i[0], 'label' => $i[1], 'count' => $i[2], 'alert' => $i[3],
            'match' => [$i[0], $i[0].'.*', rtrim($i[0], 's'), rtrim($i[0], 's').'.*', ...($i[0] === 'admin.affiliates' ? ['admin.affiliate'] : []), ...($i[0] === 'admin.activity' ? ['admin.user'] : [])]], $items);
    }
}
