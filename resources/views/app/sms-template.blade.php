<x-layouts.app title="پیامک فاکتور" page="sms-template" :back="route('settings')">
    @php($bootData = ['default' => $default, 'max' => config('talata.sms.template_max_chars')])
    <script type="application/json" id="boot">@json($bootData)</script>
    @if ($canSms)
        <section class="band stack-sm" aria-labelledby="auto-h">
            <div class="between">
                <h2 id="auto-h">ارسال خودکار پیامک فاکتور</h2>
                <label class="switch"><input type="checkbox" role="switch" data-sms-auto @checked($autoSend) aria-labelledby="auto-h"><span aria-hidden="true"></span></label>
            </div>
            <p class="small muted" data-sms-auto-text data-on="روشن: پس از صدور هر فاکتوری که موبایل مشتری دارد، پیامک آن خودکار فرستاده می‌شود. در صفحه مرور می‌توانید فقط برای همان فاکتور «صدور بدون پیامک» را بزنید." data-off="خاموش: پیامک فقط وقتی فرستاده می‌شود که در صفحه مرور «صدور و ارسال پیامکی» یا بعداً «ارسال پیامک» را بزنید.">{{ $autoSend ? 'روشن: پس از صدور هر فاکتوری که موبایل مشتری دارد، پیامک آن خودکار فرستاده می‌شود. در صفحه مرور می‌توانید فقط برای همان فاکتور «صدور بدون پیامک» را بزنید.' : 'خاموش: پیامک فقط وقتی فرستاده می‌شود که در صفحه مرور «صدور و ارسال پیامکی» یا بعداً «ارسال پیامک» را بزنید.' }}</p>
            <p class="xs muted">هزینه هر پیامک از پیامک رایگان سالانه یا اعتبار پیامک کم می‌شود.</p>
        </section>
    @endif
    @unless ($canEdit)
        <div class="notice info">در پلن رایگان متن ثابت استفاده می‌شود. ویرایش متن در پلن پایه و حرفه‌ای است.</div>
    @endunless
    <form method="post" class="stack" data-tpl-form novalidate>
        <div class="field"><label for="t-body">متن پیامک</label>
            <div class="input-wrap"><textarea id="t-body" name="template" rows="4" maxlength="{{ config('talata.sms.template_max_chars') }}" @readonly(! $canEdit)>{{ $template }}</textarea></div><div class="err"></div>
            <p class="hint">عبارت‌های مجاز: <span class="ltr">{shop_name}</span> نام فروشگاه، <span class="ltr">{invoice_number}</span> شماره فاکتور، <span class="ltr">{amount}</span> مبلغ، <span class="ltr">{invoice_link}</span> لینک فاکتور (الزامی، یک بار).</p></div>
        <div class="band stack-sm"><span class="small muted">پیش‌نمایش با داده نمونه</span><p class="white-box small" data-preview></p><p class="xs muted" data-segments></p></div>
        <div class="notice off small">برای جلوگیری از پیامک تبلیغاتی و سوءاستفاده، لینک یا شماره تلفن دیگری در متن مجاز نیست و پیامک فقط برای فاکتور صادرشده فرستاده می‌شود؛ به موبایل مشتری همان فاکتور یا شماره‌هایی که خودتان در صفحه فاکتور وارد می‌کنید.</div>
        @if ($canEdit)
            <button class="btn btn-gold block" type="submit" data-busy-text="در حال ذخیره…">ذخیره</button>
            <button class="btn btn-link" type="button" data-reset>بازگشت به متن پیش‌فرض</button>
        @endif
    </form>
</x-layouts.app>
