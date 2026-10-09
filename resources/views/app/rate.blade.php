<x-layouts.app title="فاکتور جدید" page="rate">
    @if ($quota['limit'] !== null && $quota['remaining'] !== null && $quota['remaining'] <= 2 && $quota['remaining'] > 0)
        <div class="notice warn between"><span><span class="badge warn">{{ fa($quota['remaining']) }} فاکتور مانده</span> از {{ fa($quota['limit']) }} فاکتور این ماه، {{ fa($quota['used']) }} صادر شده.</span><a href="{{ route('settings.plan') }}">ارتقا</a></div>
    @endif

    <section class="hero" aria-labelledby="price-label" data-quote='@json($quote)' data-poll="{{ $pollSeconds }}">
        <div class="between">
            <span class="label" id="price-label">قیمت هر گرم طلای ۱۸ عیار</span>
            @if ($quote['is_demo'])<span class="badge dark">عدد نمونه</span>@endif
            <span class="badge warn" data-emergency @if(! $quote['is_emergency']) hidden @endif>نرخ اعلامی زرلیو (دستی)</span>
        </div>
        <div><span class="price" data-price aria-live="polite">{{ $quote['value_toman_fa'] ?? '—' }}</span> <span class="unit">تومان</span></div>
        @if ($canIssue)
            {{-- One clear action: what it does (new invoice) and the rate it fixes for this sale. --}}
            <button class="btn btn-gold block lg btn-start" type="button" data-start data-busy-text="در حال ساخت فاکتور…" @disabled(! $quote['value_irr'])>
                <span class="btn-start-main"><span class="btn-start-plus" aria-hidden="true">+</span>ثبت فاکتور جدید</span>
                <span class="btn-start-sub">با نرخ <span class="num" data-start-rate>{{ $quote['value_toman_fa'] ?? '—' }}</span> تومان</span>
            </button>
            <p class="xs start-note">همین نرخ روی فاکتور ثابت می‌ماند؛ تغییر بعدی بازار فاکتور را عوض نمی‌کند.</p>
        @endif
        <div class="between meta"><span>آخرین دریافت: <span class="num" data-time>{{ $quote['fetched_at_fa'] ?? '—' }}</span></span>@include('partials.freshness', ['f' => $quote['freshness']])</div>
        <div class="meta">به‌روزرسانی {{ \App\Domain\Market\QuoteService::pollLabelFa() }} · منبع: <span data-source>{{ $quote['source_fa'] ?? '—' }}</span></div>
        <div class="notice err {{ $quote['freshness'] === 'ERROR' ? '' : 'hidden' }}" data-error-note role="status">
            <span>سرویس نرخ پاسخ نمی‌دهد. آخرین نرخ معتبر نمایش داده می‌شود؛ صفحه هر ۳ دقیقه خودش دوباره تلاش می‌کند.</span>
            <button type="button" class="btn sm btn-dark" data-retry-quote data-busy-text="در حال دریافت…">تلاش دوباره الان</button>
        </div>
        <p class="xs" data-start-hint @if($quote['value_irr']) hidden @endif>نرخ بازار هنوز در دسترس نیست؛ پایین همین صفحه «ثبت نرخ دستی» یا «فاکتور فقط متفرقه» را بزنید.</p>
    </section>

    <div class="grid-2">
        <div class="band"><span class="small muted">طلای ۲۴ عیار</span><strong class="num">{{ $board['rows']['GOLD_24']['display_fa'] ?? '—' }} <span class="small muted">تومان</span></strong></div>
        <div class="band"><span class="small muted">دلار</span><strong class="num">{{ $board['rows']['USD_IRR']['display_fa'] ?? '—' }} <span class="small muted">تومان</span></strong></div>
    </div>
    <a class="list-item" href="{{ route('mazneh') }}"><span class="body"><strong>مظنه کامل: خرید و فروش ۱۸، ۲۴ عیار، دلار، انس</strong></span><span aria-hidden="true">‹</span></a>

    @if ($draft)
        <a class="list-item" href="{{ route('invoices.items', $draft) }}"><span class="body"><strong>ادامه پیش‌نویس قبلی</strong><span class="sub">{{ fa($draft->items_count) }} ردیف · ذخیره‌شده {{ jtime($draft->updated_at) }}</span></span><span aria-hidden="true">‹</span></a>
    @endif

    @if ($canIssue)
        <div class="stack-sm center">
            <button type="button" class="btn-link btn" data-manual>نرخ در دسترس نیست؟ ثبت نرخ دستی</button>
            <button type="button" class="btn-link btn" data-misc-only>فاکتور فقط متفرقه (بدون نرخ طلا)</button>
        </div>
    @endif

    <template data-manual-tpl>
        <div class="between"><h2>ثبت نرخ دستی برای این فاکتور</h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>
        <form method="post" class="stack" data-manual-form novalidate>
            <div class="field"><label for="m-value">قیمت هر گرم طلای ۱۸ عیار</label><div class="input-wrap ltr-input"><input id="m-value" name="value_toman" inputmode="numeric" data-money required><span class="unit">تومان</span></div><div class="err"></div></div>
            <fieldset class="field"><legend class="label">دلیل</legend>
                <div class="chips">
                    <label class="chip"><input type="radio" name="reason" value="MARKET_UNAVAILABLE" checked>نرخ بازار در دسترس نیست</label>
                    <label class="chip"><input type="radio" name="reason" value="CUSTOMER_AGREEMENT">توافق با مشتری</label>
                    <label class="chip"><input type="radio" name="reason" value="PEER_RATE">نرخ همکار</label>
                </div>
            </fieldset>
            <p class="hint">روی فاکتور و صفحه بررسی، «نرخ دستی» نوشته می‌شود. آخرین نرخ بازار: <span class="num">{{ $quote['value_toman_fa'] ?? '—' }}</span> تومان.</p>
            <button class="btn btn-gold block" type="submit">ثبت فاکتور جدید با این نرخ</button>
            <button class="btn btn-line block" type="button" data-close>انصراف</button>
        </form>
    </template>
</x-layouts.app>
