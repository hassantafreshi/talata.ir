@php $isAdmin = auth('staff')->user()->isAdmin(); @endphp
<x-layouts.admin :title="'همکار فروش '.\App\Support\Mobile::display($affiliate->user->mobile)" page="admin-affiliates">
    <div class="desk-2">
        <section class="band stack-sm">
            <h2>کد و لینک اختصاصی</h2>
            <div class="price-sm mono">{{ $affiliate->code }}</div>
            <div class="input-wrap ltr-input"><input readonly value="{{ $affiliate->link() }}" aria-label="لینک معرفی"></div>
            <p class="xs muted">این کد و لینک را به همکار بدهید (در پنل خودش هم می‌بیند). مشتری با لینک یا وارد کردن کد در صفحه پلن‌ها به او تعلق می‌گیرد؛ تعلق قطعی پس از اولین پرداخت موفق است و دیگر عوض نمی‌شود.</p>
            <a class="btn btn-line sm" href="{{ route('admin.user', $affiliate->user_id) }}">فعالیت این کاربر</a>
        </section>
        <section class="stat-grid">
            <div class="stat"><span class="small muted">مشتری معرفی‌شده</span><strong>{{ fa($totals['referrals']) }}</strong></div>
            <div class="stat"><span class="small muted">در انتظار</span><strong>{{ toman($totals['PENDING']) }}</strong></div>
            <div class="stat"><span class="small muted">قابل پرداخت</span><strong>{{ toman($totals['APPROVED']) }}</strong></div>
            <div class="stat"><span class="small muted">پرداخت‌شده</span><strong>{{ toman($totals['PAID']) }}</strong></div>
        </section>
    </div>

    @if ($isAdmin)
        <h2>شرایط همکاری</h2>
        <p class="xs muted">تغییر درصد فقط روی پرداخت‌های بعدی اثر دارد؛ کمیسیون‌های ثبت‌شده با درصد زمان خرید ثابت می‌مانند.</p>
        @include('admin.partials.affiliate-form', ['a' => $affiliate])

        <h2>ثبت واریز</h2>
        <form class="filters band" data-payout-form data-url="{{ route('admin.affiliates.payout', $affiliate->id) }}" novalidate>
            <p class="small">مبلغ قابل پرداخت: <strong class="num">{{ toman($totals['APPROVED']) }}</strong> تومان. پس از واریز بانکی، شماره پیگیری را ثبت کنید.</p>
            <label class="small field">شماره پیگیری واریز<input name="reference" dir="ltr" required><span class="err"></span></label>
            <label class="small field">یادداشت<input name="note" maxlength="250"></label>
            <button class="btn btn-dark" type="submit" @disabled($totals['APPROVED'] === '0') data-busy-text="…">ثبت واریز</button>
        </form>
    @endif

    <h2>مشتریان معرفی‌شده</h2>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>فروشگاه</th><th>موبایل خریدار</th><th>روش</th><th>تاریخ</th></tr></thead>
        <tbody>
        @forelse ($referrals as $r)
            <tr><td><a href="{{ route('admin.tenant', $r->tenant_id) }}">{{ $tenants[$r->tenant_id]?->profile?->name ?: '#'.$r->tenant_id }}</a></td>
                <td class="mono">{{ \App\Support\Mobile::display($r->buyer_mobile) }}</td><td>{{ $r->source === 'LINK' ? 'لینک' : 'کد تخفیف' }}</td><td class="n">{{ jdate($r->attributed_at, true) }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted center">—</td></tr>
        @endforelse
        </tbody>
    </table></div>

    <h2>کمیسیون‌ها</h2>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>تاریخ</th><th>سفارش</th><th>خریدار</th><th class="n">مبنا (بدون مالیات)</th><th>درصد</th><th class="n">کمیسیون</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        @forelse ($commissions as $c)
            @php [$label, $kind] = \App\Models\AffiliateCommission::STATUS_FA[$c->status]; @endphp
            <tr><td class="n">{{ jdate($c->created_at) }}</td><td class="mono">{{ $orders[$c->order_id] ?? $c->order_id }}</td><td class="mono">{{ \App\Support\Mobile::display($c->referral?->buyer_mobile) }}</td>
                <td class="n">{{ toman($c->base_irr) }}</td><td>{{ fa(rtrim(rtrim($c->percent, '0'), '.')) }}٪</td><td class="n"><strong>{{ toman($c->amount_irr) }}</strong></td>
                <td><span class="badge {{ $kind }}">{{ $label }}</span>@if($c->void_reason)<div class="xs muted">{{ $c->void_reason }}</div>@endif</td>
                <td>@if ($isAdmin && in_array($c->status, ['PENDING', 'APPROVED'], true))<button type="button" class="btn btn-link sm" data-void="{{ route('admin.affiliates.void', $c->id) }}">لغو</button>@endif</td></tr>
        @empty
            <tr><td colspan="8" class="muted center">هنوز کمیسیونی ثبت نشده است.</td></tr>
        @endforelse
        </tbody>
    </table></div>

    @if ($payouts->isNotEmpty())
        <h2>واریزها</h2>
        <table class="t"><tbody>@foreach ($payouts as $p)<tr><td class="n">{{ jdate($p->paid_at, true) }}</td><td class="n"><strong>{{ toman($p->amount_irr) }}</strong> تومان</td><td class="mono">{{ $p->reference }}</td><td>{{ $p->note }}</td></tr>@endforeach</tbody></table>
    @endif
</x-layouts.admin>
