@php
    $boot = [
        'id' => $invoice->public_id, 'version' => $invoice->version,
        'issue_url' => route('api.drafts.issue', $invoice), 'items_url' => route('invoices.items', $invoice),
        'business_url' => route('settings.business', ['return' => $invoice->public_id]),
        'can_sms' => $canSms, 'profile_complete' => $profileComplete,
    ];
    // SMS step facts (what will happen, decided before the merchant taps): link quota and credit.
    $linksOut = $canSms && $links['limit'] !== null && $links['remaining'] === 0;
    $creditShort = $canSms && $sms['free_remaining'] === 0 && \Brick\Math\BigInteger::of($sms['balance_irr'])->isLessThan($sms['cost_irr']);
    $smsPrimary = $canSms && ! $linksOut && ! $creditShort;
    $byUid = collect($state['rows'])->keyBy('row_uid');
@endphp
<x-layouts.app title="مرور و صدور" page="review" :back="route('invoices.items', $invoice)" badge="پیش‌نویس">
    <script type="application/json" id="boot">@json($boot)</script>

    @unless ($profileComplete)
        <div class="notice warn between"><span>پیش از اولین صدور، نام فروشگاه، موبایل کسب‌وکار و نشانی را کامل کنید.</span><a class="btn sm btn-dark" href="{{ $boot['business_url'] }}">تکمیل اطلاعات</a></div>
    @endunless

    <section class="band stack-sm" aria-labelledby="rows-h">
        <div class="between"><h2 id="rows-h">اقلام ({{ fa(count($rows)) }} ردیف)</h2><a href="{{ route('invoices.items', $invoice) }}" class="small">ویرایش اقلام</a></div>
        <ul class="list">
            @foreach ($rows as $row)
                @php $s = $byUid[$row->row_uid] ?? null; @endphp
                @if ($row->item_type === 'GOLD_IN')
                    @php $a = $row->item_attributes ?? []; $c = $s['computed'] ?? []; @endphp
                    <li class="list-item"><span class="body"><strong><span class="badge info">دریافتی</span> {{ $row->name ?: (\App\Domain\Invoices\InvoicePresenter::GOLD_IN_KINDS[$a['kind'] ?? 'OLD_GOLD'] ?? 'طلای دریافتی').' از مشتری' }}</strong>
                        <span class="sub">{{ \App\Domain\Invoices\InvoicePresenter::weight($row->net_weight_g) }} گرم · عیار {{ fa((string) \Brick\Math\BigDecimal::of($row->purity_ppt)->strippedOfTrailingZeros()) }} · معادل {{ \App\Domain\Invoices\InvoicePresenter::weight($c['weight_750'] ?? null) }} گرم ۷۵۰ · {{ \App\Domain\Invoices\InvoicePresenter::GOLD_IN_BASES[$a['rate_basis'] ?? 'BUY'] ?? '' }} @if(isset($c['rate_irr_per_g']))({{ toman($c['rate_irr_per_g']) }})@endif @if(($c['D'] ?? '0') !== '0')· کسر {{ toman($c['D']) }}@endif</span></span>
                        <span class="num strong nowrap">@if(($c['settlement'] ?? '') === 'WEIGHT')بستانکار {{ \App\Domain\Invoices\InvoicePresenter::weight($c['credit_750']) }} گرم@elseif($s['total_irr'] ?? null)−{{ $s['total_fa'] }}@else—@endif</span></li>
                    @continue
                @endif
                <li class="list-item"><span class="body"><strong>{{ $row->name ?: ($row->item_type === 'GOLD' ? 'طلا' : 'متفرقه') }}</strong>
                    <span class="sub">@if($row->item_type === 'GOLD' && ($row->item_attributes['settlement'] ?? '') === 'WEIGHT')<span class="badge info">تسویه وزنی</span> @endif @if($row->item_type === 'GOLD'){{ \App\Domain\Invoices\InvoicePresenter::weight($row->net_weight_g) }} گرم · {{ \App\Domain\Invoices\InvoicePresenter::purityLabel($row->purity_ppt) }} · اجرت {{ \App\Domain\Invoices\InvoicePresenter::percent($row->wage_percent) }} · سود {{ \App\Domain\Invoices\InvoicePresenter::percent($row->profit_percent) }}@else متفرقه@endif</span></span>
                    <span class="num strong nowrap">{{ $s['total_fa'] ?? '—' }}</span></li>
            @endforeach
        </ul>
        <dl class="kv">
            @if ($state['totals']['gold_irr'] !== '0')
                <div><dt>ارزش طلا</dt><dd class="num">{{ $state['totals']['components']['M'] ?? '—' }}</dd></div>
                <div><dt>اجرت</dt><dd class="num">{{ $state['totals']['components']['W'] ?? '—' }}</dd></div>
                <div><dt>سود</dt><dd class="num">{{ $state['totals']['components']['P'] ?? '—' }}</dd></div>
                <div><dt>مالیات بر ارزش افزوده (روی اجرت و سود)</dt><dd class="num">{{ $state['totals']['components']['V'] ?? '—' }}</dd></div>
            @endif
            @if ($state['totals']['misc_irr'] !== '0')<div><dt>اقلام متفرقه</dt><dd class="num">{{ $state['totals']['misc_fa'] }}</dd></div>@endif
        </dl>
        @if ($state['totals']['has_weight_settlement'])
            <dl class="kv">
                <div><dt>طلا بدهکار / بستانکار (گرم ۷۵۰)</dt><dd class="num">{{ \App\Domain\Invoices\InvoicePresenter::weight($state['totals']['ledger']['gold_debit_750']) }} / {{ \App\Domain\Invoices\InvoicePresenter::weight($state['totals']['ledger']['gold_credit_750']) }}</dd></div>
                <div><dt>مانده طلایی این سند</dt><dd class="num strong">{{ $state['totals']['gold_balance_fa'] }} گرم · {{ \App\Domain\Invoices\InvoicePresenter::SIDE_FA[$state['totals']['ledger']['gold_balance_750'] === '0.000' ? 'ZERO' : $state['totals']['gold_balance_side']] }}</dd></div>
            </dl>
        @endif
        @if ($state['totals']['has_gold_in'])
            <dl class="kv">
                <div><dt>جمع فروش</dt><dd class="num">{{ $state['totals']['sales_fa'] }}</dd></div>
                <div><dt>ارزش طلای دریافتی از مشتری</dt><dd class="num">−{{ $state['totals']['gold_in_fa'] }}</dd></div>
                <div><dt>طلای فروخته‌شده / دریافتی (معادل ۷۵۰)</dt><dd class="num">{{ $state['totals']['weights']['out_750'] }} / {{ $state['totals']['weights']['in_750'] }} گرم</dd></div>
            </dl>
        @endif
        <div class="row-total"><span>{{ $state['totals']['customer_credit'] ? 'مانده به نفع مشتری' : 'مبلغ قابل پرداخت' }}</span><strong class="num">{{ $state['totals']['payable_abs_fa'] }} تومان</strong></div>
        @if ($state['totals']['customer_credit'])<p class="notice warn small">ارزش طلای دریافتی از جمع فروش بیشتر است. این مبلغ را باید به مشتری بپردازید.</p>@endif
        @if ($invoice->rate_mode !== 'NONE')
            <p class="xs muted">نرخ معامله: {{ toman($invoice->accepted_rate_irr) }} تومان/گرم ۱۸ عیار @if($invoice->rate_mode === 'MANUAL')(نرخ دستی)@elseif($invoice->rate_source === 'EMERGENCY')(نرخ اعلامی زرلیو)@endif · مبالغ به تومان · محاسبه نهایی سرور</p>
        @endif
    </section>

    <form class="stack" method="post" action="{{ route('api.drafts.issue', $invoice) }}" data-issue-form novalidate>
        @csrf
        <section class="band stack-sm" aria-labelledby="buyer-h">
            <h2 id="buyer-h">مشتری</h2>
            <div class="field"><label for="buyer-name">نام خریدار (اختیاری)</label><div class="input-wrap"><input id="buyer-name" name="buyer_name" maxlength="80" value="{{ $invoice->buyer_name }}" autocomplete="off"></div><div class="err"></div>
                <p class="hint">روی فاکتور چاپ می‌شود.</p></div>
            <div class="field"><label for="buyer-mobile">موبایل مشتری</label><div class="input-wrap ltr-input"><input id="buyer-mobile" name="buyer_mobile" inputmode="tel" maxlength="14" value="{{ $buyerMobile }}" placeholder="۰۹۱۲ ۳۴۵ ۶۷۸۹" autocomplete="off" data-digits></div><div class="err"></div>
                <p class="hint">برای ارسال پیامکی لازم است. مشتری نیازی به ثبت‌نام ندارد.</p></div>
            <label class="check"><input type="checkbox" name="save_customer" value="1" @disabled($customersQuota['remaining'] === 0)> ذخیره در فهرست مشتریان
                @if ($customersQuota['limit'] !== null)<span class="xs muted">({{ fa($customersQuota['remaining']) }} مشتری جدید دیگر در این ماه)</span>@endif</label>
        </section>

        @if ($canSms)
            <section class="band stack-sm" aria-labelledby="sms-h">
                <h2 id="sms-h">پیش‌نمایش پیامک</h2>
                <p class="small">پیامک به <bdi class="num ltr" dir="ltr" data-sms-to>{{ $buyerMobile ?: '—' }}</bdi></p>
                <p class="white-box small" dir="rtl">{{ $sms['body'] }}</p>
                <p class="xs muted">
                    {{ fa($sms['segments']) }} بخش ·
                    @if ($sms['free_remaining'] > 0)
                        از سهمیه رایگان سالانه ({{ fa($sms['free_remaining']) }} پیامک مانده)
                    @else
                        هزینه {{ toman($sms['cost_irr']) }} تومان از اعتبار پیامک · موجودی {{ toman($sms['balance_irr']) }} تومان
                    @endif
                    · شماره فاکتور و لینک پس از صدور ساخته می‌شود.
                </p>
                @if ($links['limit'] !== null)
                    <p class="xs muted">لینک‌های اشتراک این ماه: {{ fa($links['used']) }} از {{ fa($links['limit']) }}</p>
                @endif
                @if ($linksOut)
                    <div class="notice warn">لینک‌های فاکتور این ماه تمام شده است؛ ارسال پیامکی ممکن نیست، اما «فقط صدور» و چاپ همیشه آزاد است. سهمیه از {{ $links['resets_at_fa'] }} دوباره پر می‌شود. <a href="{{ route('settings.plan') }}">ارتقای پلن</a></div>
                @elseif ($creditShort)
                    <div class="notice warn">اعتبار پیامک کافی نیست (هزینه {{ toman($sms['cost_irr']) }}، موجودی {{ toman($sms['balance_irr']) }} تومان). اگر «صدور و ارسال پیامکی» را بزنید، فاکتور صادر می‌شود و پیامک پس از خرید اعتبار خودکار ارسال می‌شود.</div>
                @endif
                <a class="small" href="{{ route('settings.sms', ['return' => $invoice->public_id]) }}">خرید پیامک بیشتر</a>
            </section>
        @endif

        <div class="sticky-bar stack-sm">
            <div class="notice err hidden" data-issue-unknown role="alert">
                <span data-issue-unknown-text></span>
                <button class="btn sm btn-dark" type="button" data-issue-retry>بررسی دوباره</button>
            </div>
            @if ($canSms)
                <button class="btn {{ $smsPrimary ? 'btn-gold lg' : 'btn-line' }} block" type="submit" value="ISSUE_AND_SMS" data-mode="ISSUE_AND_SMS" data-busy-text="در حال صدور…" @disabled($linksOut)>صدور و ارسال پیامکی</button>
                <button class="btn {{ $smsPrimary ? 'btn-line' : 'btn-gold lg' }} block" type="submit" value="ISSUE_ONLY" data-mode="ISSUE_ONLY" data-busy-text="در حال صدور…">فقط صدور (بدون پیامک)</button>
            @else
                <button class="btn btn-gold block lg" type="submit" value="ISSUE_ONLY" data-mode="ISSUE_ONLY" data-busy-text="در حال صدور…">صدور فاکتور</button>
                <p class="xs muted center">ارسال پیامکی در این پلن فعال نیست. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></p>
            @endif
            <p class="xs muted center">پس از صدور، فاکتور قابل ویرایش نیست؛ فقط ابطال و صدور جایگزین.</p>
        </div>
    </form>
</x-layouts.app>
