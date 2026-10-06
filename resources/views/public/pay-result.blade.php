@php
    $ok = in_array($o->status, ['FULFILLED', 'PAID'], true);
    $failed = in_array($o->status, ['FAILED', 'EXPIRED'], true);
    $pending = ! $ok && ! $failed;
    $what = $o->product === 'PLAN' ? 'پلن '.($o->price_snapshot['plan_label_fa'] ?? $o->plan_code).' '.($o->period === 'yearly' ? 'سالانه' : 'ماهانه') : 'اعتبار پیامک';
@endphp
<x-layouts.public title="نتیجه پرداخت" page="pay-result">
    @php $bootData = ['status_url' => route('pay.result.status', [$o->public_id, 's' => $sig]), 'pending' => $pending]; @endphp
    <script type="application/json" id="boot">@json($bootData)</script>
    @if ($mock)<div class="notice warn">پرداخت آزمایشی (شبیه‌سازی‌شده)؛ هیچ پولی جابه‌جا نشده است.</div>@endif
    <section class="hero stack-sm" aria-live="polite">
        @if ($ok)
            <span class="badge ok">پرداخت موفق</span>
            <h2>{{ $what }} فعال شد.</h2>
            @if ($access === 'full' && $o->product === 'PLAN' && $sub)<p class="meta">اعتبار تا {{ jdate($sub->ends_at) }}</p>@endif
            @if ($access === 'full' && $o->product === 'SMS_CREDIT')<p class="meta">موجودی جدید: <span class="num">{{ $balanceFa }}</span> تومان</p>@endif
        @elseif ($failed)
            <span class="badge err">پرداخت ناموفق</span>
            <h2>{{ $o->failure_message ?: 'پرداخت تأیید نشد.' }}</h2>
            <p class="meta">هیچ تغییری در پلن یا اعتبار شما ایجاد نشد. اگر مبلغی از حساب کم شده، طبق قوانین بانک حداکثر تا ۷۲ ساعت برمی‌گردد.</p>
        @else
            <span class="badge warn">در حال بررسی</span>
            <h2>نتیجه پرداخت از بانک در حال بررسی است.</h2>
            <p class="meta">این صفحه خودکار به‌روز می‌شود. دوباره پرداخت نکنید.</p>
        @endif
    </section>
    <section class="band stack-sm">
        <dl class="kv">
            <div><dt>شماره سفارش</dt><dd class="num ltr">{{ $o->public_ref }}</dd></div>
            @if ($access === 'full')
                <div><dt>خرید</dt><dd>{{ $what }}</dd></div>
                <div><dt>مبلغ بدون مالیات</dt><dd class="num">{{ toman($o->subtotal_irr) }} تومان</dd></div>
                <div><dt>مالیات بر ارزش افزوده</dt><dd class="num">{{ toman($o->vat_irr) }} تومان</dd></div>
                <div><dt>مبلغ پرداختی</dt><dd class="num">{{ toman($o->amount_irr) }} تومان</dd></div>
                @if ($attempt?->ref_id)<div><dt>کد پیگیری بانک</dt><dd class="num ltr">{{ $attempt->ref_id }}</dd></div>@endif
                @if ($attempt?->card_mask)<div><dt>کارت</dt><dd class="num ltr">{{ $attempt->card_mask }}</dd></div>@endif
                @if ($paidFa)<div><dt>زمان</dt><dd class="num">{{ $paidFa }}</dd></div>@endif
            @endif
        </dl>
    </section>
    @if ($access === 'full')
        <div class="grid-2">
            <a class="btn btn-gold block" href="{{ $next }}">ادامه</a>
            @if ($ok)<a class="btn btn-line block" href="{{ route('settings.receipt', $o) }}">رسید پرداخت</a>@elseif($failed)<a class="btn btn-line block" href="{{ $o->product === 'PLAN' ? route('settings.plan') : route('settings.sms') }}">تلاش دوباره</a>@endif
        </div>
    @else
        <a class="btn btn-gold block" href="{{ route('login') }}">ورود به طلاتا برای جزئیات</a>
    @endif
</x-layouts.public>
