@php
    $rowsJson = $rows->map(fn ($r) => [
        'row_uid' => $r->row_uid, 'item_type' => $r->item_type, 'name' => $r->name, 'description' => $r->description,
        'net_weight_g' => $r->net_weight_g !== null ? (string) \Brick\Math\BigDecimal::of($r->net_weight_g)->strippedOfTrailingZeros() : '',
        'purity_ppt' => $r->purity_ppt !== null ? (string) \Brick\Math\BigDecimal::of($r->purity_ppt)->strippedOfTrailingZeros() : '750',
        'wage_percent' => $r->wage_percent !== null ? (string) \Brick\Math\BigDecimal::of($r->wage_percent)->strippedOfTrailingZeros() : '0',
        'profit_percent' => $r->profit_percent !== null ? (string) \Brick\Math\BigDecimal::of($r->profit_percent)->strippedOfTrailingZeros() : '0',
        'discount_toman' => $r->discount_irr ? \App\Support\Money::irrToToman($r->discount_irr) : '',
        'discount_scope' => $r->discount_scope ?? 'TAXABLE_COMPONENTS',
        'manual_total_toman' => $r->manual_total_irr ? \App\Support\Money::irrToToman($r->manual_total_irr) : '',
    ])->values();
    $boot = [
        'id' => $invoice->public_id, 'version' => $invoice->version, 'rows' => $rowsJson, 'state' => $state,
        'rate_irr' => $invoice->accepted_rate_irr, 'rate_mode' => $invoice->rate_mode, 'vat' => $vat,
        'latest_irr' => $latest['value_irr'], 'latest_fa' => $latest['value_toman_fa'],
        'buyer' => ['name' => $invoice->buyer_name, 'mobile' => $invoice->buyer_mobile],
        'limits' => config('talata.invoices'),
        'review_url' => route('invoices.review', $invoice),
    ];
@endphp
<x-layouts.app title="اقلام فاکتور" page="items" :back="route('invoices.new')" badge="پیش‌نویس">
    <script type="application/json" id="boot">@json($boot)</script>
    <div class="between small"><span class="muted" data-save-state role="status">پیش‌نویس ذخیره شد</span>
        @if ($invoice->rate_mode !== 'NONE')
            <span>نرخ معامله: <strong class="num" data-rate>{{ toman($invoice->accepted_rate_irr) }}</strong> تومان/گرم ۱۸ عیار @if($invoice->rate_mode === 'MANUAL')<span class="badge warn">نرخ دستی</span>@endif</span>
        @else
            <span class="badge info">فاکتور فقط متفرقه</span>
        @endif
    </div>
    <div class="notice warn hidden between" data-new-rate><span>نرخ تازه بازار: <strong class="num" data-new-rate-value></strong> تومان</span><button type="button" class="btn sm btn-dark" data-use-new-rate>استفاده از نرخ جدید</button></div>

    <div class="sticky-top">
        <button type="button" class="btn btn-dark block" data-add-row>+ افزودن ردیف</button>
    </div>

    <div class="stack" data-rows aria-live="polite"></div>

    <div class="sticky-bar">
        <div class="between"><span>جمع فاکتور (<span data-row-count>۰</span> ردیف)</span><strong class="num" data-payable>—</strong></div>
        <div class="xs muted center" data-preview-note>پیش‌نمایش محلی؛ محاسبه نهایی توسط سرور انجام می‌شود.</div>
        <a class="btn btn-gold block" href="{{ route('invoices.review', $invoice) }}" data-review>مرور فاکتور</a>
    </div>
    <button type="button" class="btn btn-link" data-delete-draft>حذف این پیش‌نویس</button>

    <template data-row-tpl>
        <article class="row-card" data-row>
            <div class="between"><h3>ردیف <span data-row-no></span></h3><button type="button" class="btn btn-link sm" data-remove>حذف (قابل برگشت)</button></div>
            <div class="seg" role="radiogroup" aria-label="نوع کالا">
                <label><input type="radio" value="GOLD" data-f="item_type">طلا</label>
                <label><input type="radio" value="MISC" data-f="item_type">متفرقه</label>
            </div>
            <div data-gold>
                <div class="stack">
                    <div class="field"><label>نام کالا</label><div class="input-wrap"><input data-f="name" maxlength="120" placeholder="طلای ۱۸ عیار"></div><div class="err"></div></div>
                    <div class="field"><label>وزن خالص طلا</label><div class="input-wrap ltr-input"><input data-f="net_weight_g" inputmode="decimal" placeholder="۰"><span class="unit">گرم</span></div><div class="err"></div><p class="hint">وزن سنگ و قسمت‌های غیرطلایی را وارد نکنید.</p></div>
                    <fieldset class="field"><legend class="label">عیار</legend>
                        <div class="chips purity-chips" data-purity>
                            <button type="button" class="chip" data-p="750">۱۸ عیار (۷۵۰)</button>
                            <button type="button" class="chip" data-p="875">۲۱ عیار (۸۷۵)</button>
                            <button type="button" class="chip" data-p="1000">۲۴ عیار (۱۰۰۰)</button>
                            <button type="button" class="chip" data-p="custom">عیار دقیق…</button>
                        </div>
                        <div class="input-wrap ltr-input hidden" data-purity-custom><input data-f="purity_ppt" inputmode="decimal" aria-label="عیار دقیق (از هزار)"><span class="unit">از ۱۰۰۰</span></div>
                        <div class="err"></div>
                        <p class="hint" data-eff></p>
                    </fieldset>
                    <div class="grid-2">
                        <div class="field"><label>اجرت</label><div class="input-wrap ltr-input"><input data-f="wage_percent" inputmode="decimal"><span class="unit">٪</span></div><div class="err"></div></div>
                        <div class="field"><label>سود</label><div class="input-wrap ltr-input"><input data-f="profit_percent" inputmode="decimal"><span class="unit">٪</span></div><div class="err"></div></div>
                    </div>
                    <div class="field"><label>تخفیف اجرت و سود</label><div class="input-wrap ltr-input"><input data-f="discount_toman" inputmode="numeric"><span class="unit">تومان</span></div><div class="err"></div></div>
                </div>
            </div>
            <div data-misc class="stack">
                <div class="field"><label>عنوان (الزامی)</label><div class="input-wrap"><input data-f="name" maxlength="120" placeholder="مثلاً جعبه هدیه"></div><div class="err"></div></div>
                <div class="field"><label>قیمت ردیف (الزامی)</label><div class="input-wrap ltr-input"><input data-f="manual_total_toman" inputmode="numeric"><span class="unit">تومان</span></div><div class="err"></div><p class="hint">قیمت کل همین ردیف را بنویسید. برای متفرقه، وزن و عیار و محاسبه طلا به کار نمی‌رود.</p></div>
            </div>
            <div class="field"><label>توضیح (اختیاری)</label><div class="input-wrap"><input data-f="description" maxlength="250"></div></div>
            <div class="xs muted" data-breakdown></div>
            <div class="row-total"><span>مبلغ این ردیف</span><span class="num" data-row-total>—</span></div>
        </article>
    </template>
</x-layouts.app>
