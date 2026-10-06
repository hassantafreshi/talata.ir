@php
    $boot = [
        'id' => $invoice->public_id, 'version' => $invoice->version,
        'issue_url' => route('api.drafts.issue', $invoice), 'items_url' => route('invoices.items', $invoice),
        'business_url' => route('settings.business', ['return' => $invoice->public_id]),
        'can_sms' => $canSms, 'profile_complete' => $profileComplete,
    ];
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
                <li class="list-item"><span class="body"><strong>{{ $row->name ?: ($row->item_type === 'GOLD' ? 'طلا' : 'متفرقه') }}</strong>
                    <span class="sub">@if($row->item_type === 'GOLD'){{ \App\Domain\Invoices\InvoicePresenter::weight($row->net_weight_g) }} گرم · {{ \App\Domain\Invoices\InvoicePresenter::purityLabel($row->purity_ppt) }} · اجرت {{ \App\Domain\Invoices\InvoicePresenter::percent($row->wage_percent) }} · سود {{ \App\Domain\Invoices\InvoicePresenter::percent($row->profit_percent) }}@else متفرقه@endif</span></span>
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
        <div class="row-total"><span>مبلغ قابل پرداخت</span><strong class="num">{{ $state['totals']['payable_fa'] }} تومان</strong></div>
        @if ($invoice->rate_mode !== 'NONE')
            <p class="xs muted">نرخ معامله: {{ toman($invoice->accepted_rate_irr) }} تومان/گرم ۱۸ عیار @if($invoice->rate_mode === 'MANUAL')(نرخ دستی)@endif · مبالغ به تومان · محاسبه نهایی سرور</p>
        @endif
    </section>

    <form class="stack" data-issue-form novalidate>
        <section class="band stack-sm" aria-labelledby="buyer-h">
            <h2 id="buyer-h">مشتری</h2>
            <div class="field"><label for="buyer-name">نام مشتری (اختیاری)</label><div class="input-wrap"><input id="buyer-name" name="buyer_name" maxlength="80" value="{{ $invoice->buyer_name }}" autocomplete="off"></div><div class="err"></div></div>
            <div class="field"><label for="buyer-mobile">موبایل مشتری</label><div class="input-wrap ltr-input"><input id="buyer-mobile" name="buyer_mobile" inputmode="tel" maxlength="14" value="{{ $buyerMobile }}" placeholder="۰۹۱۲ ۳۴۵ ۶۷۸۹" autocomplete="off" data-digits></div><div class="err"></div>
                <p class="hint">برای ارسال پیامکی لازم است. مشتری نیازی به ثبت‌نام ندارد.</p></div>
            <label class="check"><input type="checkbox" name="save_customer" value="1" @disabled($customersQuota['remaining'] === 0)> ذخیره در فهرست مشتریان
                @if ($customersQuota['limit'] !== null)<span class="xs muted">({{ fa($customersQuota['remaining']) }} مشتری جدید دیگر در این ماه)</span>@endif</label>
        </section>

        @if ($canSms)
            <section class="band stack-sm" aria-labelledby="sms-h">
                <h2 id="sms-h">پیش‌نمایش پیامک</h2>
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
            </section>
        @endif

        <div class="sticky-bar stack-sm">
            @if ($canSms)
                <button class="btn btn-gold block lg" type="submit" value="ISSUE_AND_SMS" data-mode="ISSUE_AND_SMS" data-busy-text="در حال صدور…">صدور و ارسال پیامکی</button>
                <button class="btn btn-line block" type="submit" value="ISSUE_ONLY" data-mode="ISSUE_ONLY" data-busy-text="در حال صدور…">فقط صدور</button>
            @else
                <button class="btn btn-gold block lg" type="submit" value="ISSUE_ONLY" data-mode="ISSUE_ONLY" data-busy-text="در حال صدور…">صدور فاکتور</button>
                <p class="xs muted center">ارسال پیامکی در این پلن فعال نیست. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></p>
            @endif
            <p class="xs muted center">پس از صدور، فاکتور قابل ویرایش نیست؛ فقط ابطال و صدور جایگزین.</p>
        </div>
    </form>
</x-layouts.app>
