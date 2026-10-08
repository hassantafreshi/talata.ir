@php
    $boot = [
        'id' => $invoice->public_id, 'version' => $invoice->version,
        'issue_url' => route('api.drafts.issue', $invoice), 'items_url' => route('invoices.items', $invoice),
        'business_url' => route('settings.business', ['return' => $invoice->public_id]),
        'proforma_url' => route('api.proformas.send', $invoice),
        'can_sms' => $canSms, 'auto_sms' => $canSms && $autoSms, 'links_out' => $linksOut ?? false, 'profile_complete' => $profileComplete,
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
                    <span class="sub">@if($row->item_type === 'GOLD' && ($row->item_attributes['settlement'] ?? '') === 'WEIGHT')<span class="badge info">تسویه وزنی</span> @endif @if($row->item_type === 'GOLD'){{ \App\Domain\Invoices\InvoicePresenter::weight($row->net_weight_g) }} گرم · {{ \App\Domain\Invoices\InvoicePresenter::purityLabel($row->purity_ppt) }} · اجرت {{ \App\Domain\Invoices\InvoicePresenter::percent($row->wage_percent) }} · سود {{ \App\Domain\Invoices\InvoicePresenter::percent($row->profit_percent) }}@else متفرقه@endif</span>
                    @if ($row->description)<span class="sub">{{ $row->description }}</span>@endif</span>
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
                <p class="hint">روی فاکتور چاپ می‌شود. چند حرف بنویسید تا مشتریان ثبت‌شده پیشنهاد شوند.</p></div>
            <div class="field"><label for="buyer-mobile">موبایل مشتری</label><div class="input-wrap ltr-input"><input id="buyer-mobile" name="buyer_mobile" inputmode="tel" maxlength="14" value="{{ $buyerMobile }}" placeholder="۰۹۱۲ ۳۴۵ ۶۷۸۹" autocomplete="off" data-digits></div><div class="err"></div>
                <p class="hint">برای ارسال پیامکی لازم است. مشتری نیازی به ثبت‌نام ندارد.</p></div>
            <div class="field"><label for="buyer-nid">کد ملی (اختیاری)</label><div class="input-wrap ltr-input"><input id="buyer-nid" name="buyer_national_id" inputmode="numeric" maxlength="12" value="{{ $invoice->buyer_national_id ? fa($invoice->buyer_national_id) : '' }}" autocomplete="off" data-digits></div><div class="err"></div>
                <p class="hint">روی فاکتور چاپی می‌آید؛ در لینک و صفحه بررسی اصالت نمایش داده نمی‌شود.</p></div>
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
                    <div class="notice warn">اعتبار پیامک کافی نیست (هزینه {{ toman($sms['cost_irr']) }}، موجودی {{ toman($sms['balance_irr']) }} تومان). فاکتور صادر می‌شود و پیامک پس از خرید اعتبار خودکار ارسال می‌شود.</div>
                @endif
                <a class="small" href="{{ route('settings.sms', ['return' => $invoice->public_id]) }}">خرید پیامک بیشتر</a>
            </section>
        @endif

        {{-- پیش‌فاکتور: the customer confirms first (mobile + SMS code), then the sales invoice is issued. --}}
        <section class="band pf-offer" aria-labelledby="pf-h">
            <details>
                <summary><span class="stack-xs"><strong id="pf-h">ارسال پیش‌فاکتور برای تأیید مشتری</strong><span class="xs muted">به‌جای صدور فوری: مشتری لینک را باز می‌کند و با موبایل و کد پیامکی تأیید می‌کند؛ سپس فاکتور فروش صادر می‌شود.</span></span></summary>
                <div class="stack-sm pf-offer-body">
                    <fieldset class="field"><legend class="label">مدت اعتبار پیش‌فاکتور</legend>
                        <div class="chips" role="radiogroup" aria-label="مدت اعتبار">
                            @foreach (\App\Domain\Invoices\ProformaService::HOURS as $h)
                                <label class="chip"><input type="radio" name="pf_hours" value="{{ $h }}" @checked($h === $proformaHours)>{{ \App\Domain\Invoices\ProformaService::hoursFa($h) }}@if($h === 24) (پیش‌فرض)@endif</label>
                            @endforeach
                        </div>
                        <p class="hint">اگر مشتری تا این مدت تأیید نکند، پیش‌فاکتور خودکار ابطال می‌شود. قیمت‌ها تا پایان مهلت ثابت می‌ماند.</p>
                    </fieldset>
                    <p class="pf-lock"><span aria-hidden="true">🔒</span> قیمت با نرخ همین پیش‌نویس@if($invoice->accepted_rate_irr) ({{ toman($invoice->accepted_rate_irr) }} تومان هر گرم ۱۸ عیار)@endif قفل می‌شود و فقط تا پایان مدت اعتبار معتبر است.</p>
                    <p class="xs">پس از تأیید مشتری: <strong>{{ $proformaAuto ? 'فاکتور فروش خودکار صادر می‌شود' : 'شما «صدور فاکتور فروش» را می‌زنید' }}</strong> · <a href="{{ route('settings.proforma') }}">تنظیمات پیش‌فاکتور</a></p>
                    @if ($canSms)
                        <label class="check"><input type="checkbox" name="pf_sms" value="1" checked> ارسال پیامک پیش‌فاکتور (با مهلت تأیید و لینک) به موبایل مشتری</label>
                    @else
                        <p class="xs muted">ارسال پیامکی در این پلن فعال نیست؛ پس از ساخت، لینک را با «اشتراک‌گذاری» بفرستید.</p>
                    @endif
                    <button class="btn btn-dark block" type="button" data-proforma data-busy-text="در حال ارسال…">ارسال پیش‌فاکتور</button>
                    <p class="xs muted">موبایل مشتری (بالا) لازم است. تا تأیید مشتری یا پایان مهلت، این پیش‌نویس قفل می‌شود.</p>
                </div>
            </details>
        </section>

        <div class="sticky-bar stack-sm">
            <div class="notice err hidden" data-issue-unknown role="alert">
                <span data-issue-unknown-text></span>
                <button class="btn sm btn-dark" type="button" data-issue-retry>بررسی دوباره</button>
            </div>
            @if ($canSms && $autoSms)
                {{-- «ارسال خودکار پیامک» is on: the main button issues and, when a mobile is entered, sends by itself. --}}
                <button class="btn btn-gold lg block" type="submit" value="AUTO" data-mode="AUTO" data-busy-text="در حال صدور…">صدور فاکتور</button>
                <p class="xs center" data-auto-hint aria-live="polite"
                   data-with="{{ $linksOut ? 'لینک‌های این ماه تمام شده؛ این بار پیامک فرستاده نمی‌شود.' : 'پس از صدور، پیامک فاکتور خودکار برای مشتری فرستاده می‌شود.' }}"
                   data-without="موبایل مشتری را بنویسید تا پیامک فاکتور خودکار فرستاده شود."></p>
                <button class="btn btn-line block" type="submit" value="ISSUE_ONLY" data-mode="ISSUE_ONLY" data-busy-text="در حال صدور…">صدور بدون پیامک (فقط این بار)</button>
                <p class="xs muted center">ارسال خودکار پیامک روشن است. <a href="{{ route('settings.sms_template') }}">تغییر در تنظیمات</a></p>
            @elseif ($canSms)
                <button class="btn {{ $smsPrimary ? 'btn-line' : 'btn-gold lg' }} block" type="submit" value="ISSUE_ONLY" data-mode="ISSUE_ONLY" data-busy-text="در حال صدور…">صدور فاکتور</button>
                <button class="btn {{ $smsPrimary ? 'btn-gold' : 'btn-line' }} block" type="submit" value="ISSUE_AND_SMS" data-mode="ISSUE_AND_SMS" data-busy-text="در حال صدور…" @disabled($linksOut)>صدور و ارسال پیامکی</button>
                <p class="xs muted center">ارسال خودکار پیامک خاموش است. <a href="{{ route('settings.sms_template') }}">روشن کردن در تنظیمات</a></p>
            @else
                <button class="btn btn-gold block lg" type="submit" value="ISSUE_ONLY" data-mode="ISSUE_ONLY" data-busy-text="در حال صدور…">صدور فاکتور</button>
                <p class="xs muted center">ارسال پیامکی در این پلن فعال نیست. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></p>
            @endif
            <p class="xs muted center">پس از صدور، فاکتور قابل ویرایش نیست؛ فقط ابطال و صدور جایگزین.</p>
        </div>
    </form>
</x-layouts.app>
