@php($boot = ['quote' => $quote, 'vat' => $vat, 'limits' => config('talata.invoices'), 'can_invoice' => $canInvoice])
<x-layouts.app title="ماشین‌حساب طلایی" page="calculator">
    <script type="application/json" id="boot">@json($boot)</script>
    <p class="small muted">برای محاسبه سریع قیمت؛ چیزی صادر یا ذخیره نمی‌شود و سهمیه مصرف نمی‌کند.</p>
    <div class="desk-2">
        <form class="band stack" data-calc novalidate>
            <div class="field"><label for="c-rate">نرخ هر گرم طلای ۱۸ عیار</label>
                <div class="input-wrap ltr-input"><input id="c-rate" name="rate" inputmode="numeric" value="{{ $quote['value_toman_fa'] }}" data-digits><span class="unit">تومان</span></div><div class="err"></div>
                <p class="hint" data-rate-hint>@if($quote['value_irr'])از مظنه فروش · دریافت {{ $quote['fetched_at_fa'] }}@if($quote['is_demo']) · عدد نمونه@endif @if($quote['is_emergency'] ?? false) · نرخ اعلامی زرلیو (دستی)@endif @else نرخ بازار در دسترس نیست؛ نرخ را وارد کنید.@endif</p></div>
            <div class="field"><label for="c-weight">وزن خالص طلا</label><div class="input-wrap ltr-input"><input id="c-weight" name="weight" inputmode="decimal" placeholder="۰" autofocus><span class="unit">گرم</span></div><div class="err"></div></div>
            <fieldset class="field"><legend class="label">عیار</legend>
                <div class="chips purity-chips" data-purity>
                    <button type="button" class="chip" data-p="750" aria-pressed="true">۱۸ عیار</button>
                    <button type="button" class="chip" data-p="875" aria-pressed="false">۲۱ عیار</button>
                    <button type="button" class="chip" data-p="1000" aria-pressed="false">۲۴ عیار</button>
                    <button type="button" class="chip" data-p="custom" aria-pressed="false">عیار دقیق…</button>
                </div>
                <div class="input-wrap ltr-input hidden" data-purity-custom><input name="purity" inputmode="decimal" value="750" aria-label="عیار دقیق (از هزار)"><span class="unit">از ۱۰۰۰</span></div>
                <div class="err"></div>
            </fieldset>
            <div class="grid-2">
                <div class="field"><label for="c-wage">اجرت</label><div class="input-wrap ltr-input"><input id="c-wage" name="wage" inputmode="decimal" value="0"><span class="unit">٪</span></div><div class="err"></div></div>
                <div class="field"><label for="c-profit">سود</label><div class="input-wrap ltr-input"><input id="c-profit" name="profit" inputmode="decimal" value="0"><span class="unit">٪</span></div><div class="err"></div></div>
            </div>
            <div class="field"><label for="c-discount">تخفیف اجرت و سود</label><div class="input-wrap ltr-input"><input id="c-discount" name="discount" inputmode="numeric"><span class="unit">تومان</span></div><div class="err"></div></div>
            <p class="xs muted">مالیات بر ارزش افزوده {{ fa($vat) }}٪ روی اجرت و سود @if($vatSample)(قاعده نمونه؛ تأیید مشاور لازم است)@endif</p>
        </form>
        <section class="stack" aria-live="polite">
            <div class="hero stack-sm">
                <span class="label">مبلغ این قطعه</span>
                <div><span class="price" data-total>—</span> <span class="unit">تومان</span></div>
                <p class="meta" data-state>وزن را وارد کنید.</p>
            </div>
            <dl class="kv band">
                <div><dt>نرخ این عیار (هر گرم)</dt><dd class="num" data-out="eff">—</dd></div>
                <div><dt>ارزش طلا</dt><dd class="num" data-out="M">—</dd></div>
                <div><dt>اجرت</dt><dd class="num" data-out="W">—</dd></div>
                <div><dt>سود</dt><dd class="num" data-out="P">—</dd></div>
                <div><dt>مالیات</dt><dd class="num" data-out="V">—</dd></div>
            </dl>
            @if ($canInvoice)
                <button type="button" class="btn btn-gold block" data-to-invoice disabled data-busy-text="در حال ساخت پیش‌نویس…">ساخت فاکتور با همین اعداد</button>
            @endif
        </section>
    </div>
</x-layouts.app>
