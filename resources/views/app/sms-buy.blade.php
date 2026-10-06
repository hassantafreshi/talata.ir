@php($perToman = \App\Support\Money::irrToToman($perSegmentIrr))
<x-layouts.app title="خرید اعتبار پیامک" page="billing" :back="route('settings')">
    @php($bootData = ['vat' => $vat, 'per_segment_toman' => $perToman, 'return' => $returnId])
    <script type="application/json" id="boot">@json($bootData)</script>
    @if ($mock)<div class="notice warn">حالت آزمایشی: پرداخت شبیه‌سازی می‌شود.</div>@endif
    <section class="band stack-sm">
        <div class="between"><span>موجودی فعلی</span><strong class="num">{{ toman($balanceIrr) }} تومان</strong></div>
        <p class="small muted">تعرفه پلن {{ $plan['label_fa'] }}: هر بخش پیامک {{ fa(number_format((int) $perToman, 0, '.', '٬')) }} تومان. پیامک فارسی تا ۷۰ نویسه یک بخش است.
            @if ($freeYear) پیامک رایگان سالانه: {{ fa($free) }} از {{ fa($freeYear) }}.@endif</p>
        @unless ($carries)<p class="small notice warn">در پلن رایگان، اعتبار خریداری‌شده تا پایان ماه شمسی جاری معتبر است.</p>@endunless
    </section>

    @if ($canBuy)
        <form method="post" class="stack" data-sms-form novalidate>
            <fieldset class="field"><legend class="label">مبلغ شارژ (بدون مالیات)</legend>
                {{-- Default: 200,000 toman, or the plan minimum when it is higher (Free: 400,000). --}}
                @php($defaultPack = collect($packs)->first(fn ($p) => (int) $p >= max(200000, (int) $minToman)) ?? collect($packs)->first())
                <div class="chips pack-chips">
                    @foreach ($packs as $p)
                        <label class="chip pack-chip"><input type="radio" name="pack" value="{{ $p }}" @checked((string) $p === (string) $defaultPack)>
                            <span>{{ fa(number_format((int) $p, 0, '.', '٬')) }} تومان</span>
                            @if ((int) $perToman > 0)<span class="xs">≈ {{ fa(number_format(intdiv((int) $p, (int) $perToman), 0, '.', '٬')) }} پیامک</span>@endif
                        </label>
                    @endforeach
                </div>
                <p class="hint">حداقل خرید در این پلن {{ fa(number_format((int) $minToman, 0, '.', '٬')) }} تومان.</p>
            </fieldset>
            <dl class="kv band" aria-live="polite">
                <div><dt>اعتبار پیامک</dt><dd class="num" data-sub></dd></div>
                <div><dt>مالیات بر ارزش افزوده {{ fa($vat) }}٪</dt><dd class="num" data-vat></dd></div>
                <div><dt><strong>قابل پرداخت</strong></dt><dd class="num"><strong data-total></strong></dd></div>
                <div><dt>تقریباً</dt><dd class="num" data-count></dd></div>
            </dl>
            <button class="btn btn-gold block" type="submit" data-busy-text="انتقال به درگاه…" data-pay-label>پرداخت و شارژ</button>
            <p class="xs muted">اعتبار برابر مبلغ بدون مالیات است. اگر پرداخت ناموفق باشد و مبلغی کم شده باشد، طبق قوانین بانک حداکثر تا ۷۲ ساعت برمی‌گردد.</p>
        </form>
    @else
        <div class="notice info">خرید اعتبار فقط با دسترسی «پلن و پرداخت» ممکن است.</div>
    @endif

    @if ($orders->isNotEmpty())
        <section class="stack-sm"><h2>خریدهای اخیر</h2>
            <ul class="list">
                @foreach ($orders as $o)
                    <li class="list-item"><span class="body"><strong class="num">{{ toman($o->subtotal_irr) }} تومان</strong><span class="sub">{{ jdate($o->created_at, true) }} · {{ $o->public_ref }}</span></span>
                        @if (in_array($o->status, ['FULFILLED', 'PAID'], true))<a class="btn btn-link sm" href="{{ route('settings.receipt', $o) }}">رسید</a><span class="badge ok">موفق</span>
                        @elseif (in_array($o->status, ['FAILED', 'EXPIRED'], true))<span class="badge err">ناموفق</span>
                        @else<a class="badge warn" href="{{ route('pay.result', $o) }}">در حال بررسی</a>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
