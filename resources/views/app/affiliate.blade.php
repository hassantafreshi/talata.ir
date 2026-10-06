@php
    $a = $affiliate;
    $pct = fn ($v) => pct($v);
@endphp
<x-layouts.app title="همکاری در فروش" page="affiliate" :back="route('settings')">
    @php $bootData = ['link' => $a->link(), 'code' => $a->code]; @endphp
    <script type="application/json" id="boot">@json($bootData)</script>
    @if (! $a->isActive())<div class="notice warn">همکاری شما موقتاً متوقف است؛ فروش‌های جدید کمیسیون نمی‌گیرند. با پشتیبانی زرلیو تماس بگیرید.</div>@endif

    <section class="hero stack-sm" aria-labelledby="code-h">
        <span class="label" id="code-h">کد تخفیف شما برای مشتریان</span>
        <div class="price num ltr" data-code>{{ $a->code }}</div>
        <p class="meta">مشتری جدیدی که با این کد یا لینک ثبت‌نام یا خرید کند، مشتری شما ثبت می‌شود.
            @if ((float) $a->discount_percent > 0) او {{ $pct($a->discount_percent) }}٪ تخفیف روی خرید اول پلن می‌گیرد.@endif</p>
        <div class="input-wrap ltr-input"><input readonly value="{{ $a->link() }}" data-link aria-label="لینک معرفی"></div>
        <div class="grid-2">
            <button type="button" class="btn btn-gold block" data-copy-link>کپی لینک</button>
            <button type="button" class="btn btn-line block" data-share>اشتراک‌گذاری</button>
        </div>
    </section>

    <div class="stat-grid">
        <div class="stat"><span class="small muted">کل درآمد</span><strong>{{ toman($totals['TOTAL']) }}</strong><span class="xs muted">تومان</span></div>
        <div class="stat"><span class="small muted">در انتظار تأیید</span><strong>{{ toman($totals['PENDING']) }}</strong><span class="xs muted">تا {{ fa(config('talata.affiliate.hold_days')) }} روز پس از خرید</span></div>
        <div class="stat"><span class="small muted">قابل پرداخت</span><strong>{{ toman($totals['APPROVED']) }}</strong></div>
        <div class="stat"><span class="small muted">پرداخت‌شده</span><strong>{{ toman($totals['PAID']) }}</strong></div>
        <div class="stat"><span class="small muted">مشتریان معرفی‌شده</span><strong>{{ fa($totals['referrals']) }}</strong></div>
    </div>
    <p class="xs muted">کمیسیون شما: {{ $pct($a->commission_percent) }}٪ از مبلغ پرداختی مشتری (بدون مالیات) — {{ \App\Models\Affiliate::MODES[$a->commission_mode] }}@if($a->include_sms_credit)، شامل خرید اعتبار پیامک@endif.</p>

    <section class="stack-sm" aria-labelledby="buyers-h">
        <h2 id="buyers-h">خریداران شما</h2>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>مشتری</th><th>از تاریخ</th><th class="n">تعداد پرداخت</th><th class="n">درآمد (تومان)</th></tr></thead>
            <tbody>
            @forelse ($buyers as $b)
                <tr><td class="num ltr">{{ $b['mask'] }}</td><td class="n">{{ jdate($b['since']) }}</td><td class="n">{{ fa($b['payments']) }}</td><td class="n"><strong>{{ toman($b['income']) }}</strong></td></tr>
            @empty
                <tr><td colspan="4" class="muted center">هنوز مشتری‌ای با کد شما ثبت نشده است. لینک یا کد را برای مشتریان بفرستید.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @if ($entries->isNotEmpty())
        <section class="stack-sm" aria-labelledby="inc-h">
            <h2 id="inc-h">ریز درآمد</h2>
            <ul class="list">
                @foreach ($entries as $e)
                    @php [$label, $kind] = \App\Models\AffiliateCommission::STATUS_FA[$e->status]; @endphp
                    <li class="list-item"><span class="body"><strong class="num ltr">{{ \App\Domain\Affiliate\AffiliateService::maskBuyer($e->referral?->buyer_mobile) }}</strong>
                        <span class="sub">{{ jdate($e->created_at) }} · {{ $e->product === 'PLAN' ? 'خرید پلن' : 'اعتبار پیامک' }} · {{ $pct($e->percent) }}٪</span></span>
                        <span class="num strong nowrap">{{ toman($e->amount_irr) }}</span><span class="badge {{ $kind }}">{{ $label }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($payouts->isNotEmpty())
        <section class="stack-sm"><h2>واریزها</h2>
            <ul class="list">@foreach ($payouts as $p)<li class="list-item"><span class="body"><strong class="num">{{ toman($p->amount_irr) }} تومان</strong><span class="sub">{{ jdate($p->paid_at) }} · پیگیری <span class="mono">{{ $p->reference }}</span></span></span></li>@endforeach</ul>
        </section>
    @endif
</x-layouts.app>
