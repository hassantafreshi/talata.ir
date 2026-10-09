<x-layouts.app title="پلن‌ها" page="billing" :back="route('settings')">
    @php($bootData = ['vat' => $vat])
    <script type="application/json" id="boot">@json($bootData)</script>
    @if ($mock)<div class="notice warn">حالت آزمایشی: درگاه پرداخت واقعی هنوز انتخاب نشده و پرداخت‌ها شبیه‌سازی می‌شوند.</div>@endif
    @if ($pendingOrder)
        <div class="notice warn between">
            <span>نتیجه پرداخت قبلی شما (کد <span class="num ltr">{{ $pendingOrder->public_ref }}</span>) هنوز قطعی نشده است. پیش از پرداخت دوباره، وضعیت آن را ببینید.</span>
            <a class="btn sm btn-dark" href="{{ $pendingUrl }}">دیدن وضعیت</a>
        </div>
    @endif
    <p class="small">پلن فعلی: <strong>{{ $summary['plan']['label_fa'] }}</strong>@if($summary['plan']['ends_at_fa']) · تا {{ $summary['plan']['ends_at_fa'] }}@endif</p>
    @if ($canBuy)
        <form method="post" class="band stack-sm" data-discount-form novalidate>
            <div class="field"><label for="dc">کد تخفیف یا کد معرف</label>
                <div class="jdp-typed-row"><div class="input-wrap ltr-input"><input id="dc" name="discount_code" value="{{ $prefillCode }}" maxlength="20" autocomplete="off" placeholder="مثلاً TLAB12CD"></div>
                    <button class="btn btn-dark" type="submit" data-busy-text="…">اعمال</button></div>
                <div class="err"></div><p class="hint" data-discount-msg role="status"></p></div>
        </form>
    @endif
    <div class="seg" role="radiogroup" aria-label="دوره">
        <label><input type="radio" name="period" value="monthly" checked>ماهانه</label>
        <label><input type="radio" name="period" value="yearly">سالانه</label>
    </div>
    <div class="tiles plans">
        @foreach ($plans as $code => $p)
            @php($current = $summary['plan']['code'] === $code)
            <article class="band stack-sm plan-card {{ $current ? 'em' : '' }}">
                <div class="between"><h2>{{ $p['label_fa'] }}</h2>@if($current)<span class="badge dark">پلن فعلی</span>@endif</div>
                @if ($code === 'free')
                    <div><strong class="price-sm">رایگان</strong></div>
                @else
                    @foreach (['monthly', 'yearly'] as $period)
                        <div class="stack-sm" data-period="{{ $period }}" data-plan="{{ $code }}" @if($period === 'yearly') hidden @endif>
                            <div><strong class="price-sm num">{{ toman($p['prices'][$period]['subtotal']) }}</strong> <span class="small">تومان {{ $period === 'monthly' ? 'در ماه' : 'در سال' }}</span></div>
                            @if ($period === 'yearly' && (int) $p['prices']['monthly']['subtotal'] > 0)
                                @php($perMonth = intdiv((int) $p['prices']['yearly']['subtotal'], 12))
                                @php($saving = (int) $p['prices']['monthly']['subtotal'] * 12 - (int) $p['prices']['yearly']['subtotal'])
                                <p class="xs">معادل ماهی <span class="num">{{ toman((string) $perMonth) }}</span> تومان@if($saving > 0) · <strong>{{ toman((string) $saving) }} تومان</strong> کمتر از ۱۲ ماه پرداخت ماهانه@endif</p>
                            @endif
                            <dl class="kv small">
                                <div><dt>مبلغ پلن</dt><dd class="num" data-f="list">{{ toman($p['prices'][$period]['subtotal']) }}</dd></div>
                                <div class="hidden" data-discount-row><dt>تخفیف</dt><dd class="num" data-f="discount"></dd></div>
                                <div><dt>مالیات بر ارزش افزوده {{ fa($vat) }}٪</dt><dd class="num" data-f="vat">{{ toman($p['prices'][$period]['vat']) }}</dd></div>
                                <div><dt><strong>قابل پرداخت</strong></dt><dd class="num"><strong data-f="total">{{ toman($p['prices'][$period]['total']) }}</strong></dd></div>
                            </dl>
                            @if ($canBuy)
                                <button type="button" class="btn {{ $current ? 'btn-line' : 'btn-gold' }} block" data-buy-plan="{{ $code }}" data-period-btn="{{ $period }}" data-busy-text="انتقال به درگاه…">{{ $current ? 'تمدید' : 'خرید '.$p['label_fa'] }}</button>
                            @endif
                        </div>
                    @endforeach
                @endif
                <ul class="feature-list small">@foreach ($p['highlights_fa'] as $h)<li>{{ $h }}</li>@endforeach</ul>
                @if (! empty($p['not_included_fa']))<ul class="feature-list off small">@foreach ($p['not_included_fa'] as $h)<li>{{ $h }}</li>@endforeach</ul>@endif
            </article>
        @endforeach
    </div>
    <p class="xs muted">قیمت‌ها بدون مالیات بر ارزش افزوده است؛ مالیات هنگام پرداخت جدا نمایش و اضافه می‌شود. تمدید زودهنگام: روزهای باقیمانده به دوره جدید اضافه می‌شود. پرداخت ناموفق هیچ تغییری در پلن ایجاد نمی‌کند.</p>
    @unless ($canBuy)<div class="notice info">خرید پلن فقط با دسترسی «پلن و پرداخت» ممکن است.</div>@endunless
</x-layouts.app>
