<x-layouts.app title="قرارداد اقساط" page="agreement" :back="route('customers.show', $customer)">
    @php($bootData = ['url' => route('api.agreements.store', $customer)])
    <script type="application/json" id="boot">@json($bootData)</script>
    <p class="small">مشتری: <strong>{{ $customer->name }}</strong></p>
    <form class="stack" data-agreement-form novalidate>
        <section class="band stack-sm">
            <fieldset class="field"><legend class="label">مبلغ اقساط از کجا بیاید؟</legend>
                <div class="seg" role="radiogroup">
                    <label><input type="radio" name="source" value="invoice" @checked($invoices->isNotEmpty()) @disabled($invoices->isEmpty())>از فاکتور</label>
                    <label><input type="radio" name="source" value="manual" @checked($invoices->isEmpty())>مبلغ دستی</label>
                </div>
            </fieldset>
            <div class="field" data-src="invoice"><label for="a-inv">فاکتور</label>
                <div class="input-wrap"><select id="a-inv" name="invoice_id">
                    @foreach ($invoices as $inv)<option value="{{ $inv->public_id }}" data-remind="{{ $customer->mobile && ! $customer->sms_opt_out && $inv->buyer_mobile === $customer->mobile ? 1 : 0 }}">فاکتور {{ "\u{2066}".fa($inv->number)."\u{2069}" }} · {{ toman($inv->payable_irr) }} تومان · {{ jdate($inv->issued_at) }}</option>@endforeach
                </select></div><div class="err"></div></div>
            <div class="field hidden" data-src="manual"><label for="a-principal">مبلغ کل</label><div class="input-wrap ltr-input"><input id="a-principal" name="principal_toman" inputmode="numeric" data-digits><span class="unit">تومان</span></div><div class="err"></div></div>
            <div class="field"><label for="a-down">پیش‌پرداخت (اختیاری)</label><div class="input-wrap ltr-input"><input id="a-down" name="down_payment_toman" inputmode="numeric" data-digits placeholder="۰"><span class="unit">تومان</span></div><div class="err"></div></div>
        </section>
        <section class="band stack-sm">
            <div class="grid-2">
                <div class="field"><label for="a-count">تعداد قسط</label><div class="input-wrap ltr-input"><input id="a-count" name="count" inputmode="numeric" value="6" data-digits></div><div class="err"></div></div>
                <fieldset class="field"><legend class="label">فاصله</legend>
                    <div class="seg"><label><input type="radio" name="frequency" value="monthly" checked>ماهانه</label><label><input type="radio" name="frequency" value="weekly">هفتگی</label></div></fieldset>
            </div>
            <div class="field" data-jdp data-min="{{ $minDate }}" data-quick="today,+1w,+1m" data-required>
                <span class="label" id="a-first-l">سررسید اولین قسط</span>
                <div class="input-wrap"><input type="hidden" name="first_due" value=""></div><div class="err"></div>
                <p class="hint">اقساط ماهانه همان روز ماه شمسی تکرار می‌شوند (در ماه کوتاه‌تر، روز آخر ماه).</p>
            </div>
            <div class="field"><label class="check"><input type="checkbox" name="reminders" value="1" checked> یادآوری پیامکی: روز قبل از سررسید و یک بار اگر پرداخت دیر شد (ساعت ۹ تا ۲۱)</label><div class="err"></div>
                <p class="xs muted" data-remind-hint>یادآوری پیامکی فقط برای اقساط فاکتوری ممکن است که با موبایل همین مشتری صادر شده باشد.</p></div>
        </section>

        <section class="band stack-sm" aria-live="polite">
            <h2>جدول اقساط</h2>
            <p class="small muted" data-preview-empty>مبلغ، تعداد و تاریخ اولین قسط را وارد کنید.</p>
            <div class="table-wrap hidden" data-preview><table class="t"><thead><tr><th scope="col">قسط</th><th scope="col">سررسید</th><th scope="col">مبلغ (تومان)</th></tr></thead><tbody></tbody></table>
                <p class="xs muted">اقساط به هزار تومان گرد می‌شود و باقیمانده در قسط آخر می‌آید. مانده اقساط: <span class="num" data-principal></span> تومان</p></div>
        </section>
        <button class="btn btn-gold block" type="submit" data-busy-text="در حال ثبت…">ثبت قرارداد</button>
    </form>
</x-layouts.app>
