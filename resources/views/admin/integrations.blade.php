@php($yes = fn ($b) => $b ? '<span class="badge ok">تنظیم‌شده</span>' : '<span class="badge err">تنظیم نشده</span>')
<x-layouts.admin title="اتصال‌ها" page="admin-ops" description="درگاه پرداخت، ارائه‌دهنده پیامک و سرویس نرخ. کلیدها فقط در تنظیمات سرور (env) هستند و این صفحه هیچ‌وقت مقدارشان را نشان نمی‌دهد.">
    <div class="pricing-grid">
        <section class="action-card stack-sm">
            <h3>شماره پشتیبانی</h3>
            <p class="small">نمایش به فروشنده‌ها (صفحه تنظیمات، صفحه‌های عمومی و پیام‌هایی که به پشتیبانی ارجاع می‌دهند): <strong class="num ltr">{{ $supportPhone ? fa($supportPhone) : '—' }}</strong></p>
            @if ($canSettings)
                <form class="stack-sm" data-action="{{ route('admin.settings.support') }}" data-idem data-reload data-confirm="شماره پشتیبانی «{phone}» شود؟">
                    <div class="field"><label for="sp">شماره</label><input id="sp" name="phone" class="ltr-input" inputmode="tel" maxlength="20" value="{{ $supportPhone }}"></div>
                    <div class="field"><label for="spr">دلیل</label><textarea id="spr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                    <button class="btn btn-dark sm" type="submit">ذخیره</button>
                </form>
            @endif
        </section>
        <section class="action-card stack-sm">
            <h3>درگاه پرداخت</h3>
            <p class="status-row"><span class="mono">{{ $payment['driver'] }}</span>
                @if($payment['is_mock'])<span class="badge warn">آزمایشی (Mock)</span>@else<span class="badge ok">واقعی</span>@endif
                @if($payment['driver'] === 'zarinpal' && $payment['zarinpal_sandbox'])<span class="badge warn">Sandbox</span>@endif</p>
            <dl class="kv">
                <div><dt>درگاه‌های ثبت‌شده</dt><dd class="mono">{{ implode(', ', $payment['registered']) }}</dd></div>
                <div><dt>کد پذیرنده زرین‌پال</dt><dd>{!! $yes($payment['zarinpal_merchant_set']) !!}</dd></div>
                <div><dt>نشانی بازگشت بانک</dt><dd class="mono">{{ $payment['callback'] }}</dd></div>
                <div><dt>مهلت پرداخت / استعلام تا</dt><dd>{{ fa($payment['expiry']) }} دقیقه / {{ fa($payment['reconcile_hours']) }} ساعت</dd></div>
                <div><dt>ارسال موبایل پرداخت‌کننده به درگاه</dt><dd>{{ $payment['send_mobile'] ? 'بله' : 'خیر (پیش‌فرض)' }}</dd></div>
            </dl>
            <p class="xs muted">تعویض درگاه: TALATA_PAYMENT_DRIVER. پرداخت‌های باز با همان درگاهی که شروع شده‌اند تأیید می‌شوند (docs/PAYMENTS_AND_SMS_CREDIT.md).</p>
        </section>
        <section class="action-card stack-sm">
            <h3>ارائه‌دهنده پیامک</h3>
            <p class="status-row"><span class="mono">{{ $sms['driver'] }}</span>@if(in_array($sms['driver'], ['log', 'fake'], true))<span class="badge warn">آزمایشی (ارسال واقعی ندارد)</span>@else<span class="badge ok">واقعی</span>@endif</p>
            <dl class="kv">
                <div><dt>کلید API</dt><dd>{!! $yes($sms['key_set']) !!}</dd></div>
                <div><dt>خط ارسال</dt><dd>{!! $sms['sender_set'] ? '<span class="badge ok">اختصاصی</span>' : '<span class="badge off">پیش‌فرض حساب</span>' !!}</dd></div>
                <div><dt>قالب کد ورود (Verify)</dt><dd>{!! $yes($sms['otp_template_set']) !!}</dd></div>
                <div><dt>آخرین هشدار</dt><dd class="small">{{ $sms['last_error'] ? jdate(\Carbon\CarbonImmutable::parse($sms['last_error']->created_at), true).' · '.$sms['last_error']->message : '—' }}</dd></div>
            </dl>
            @if ($canTestSms)<button class="btn sm btn-line" type="button" data-post="{{ route('admin.sms.test') }}" data-confirm="یک پیامک آزمایشی به شماره خودتان فرستاده شود؟ (هزینه از بودجه سرویس)">ارسال آزمایشی به شماره من</button>@endif
        </section>
        <section class="action-card stack-sm">
            <h3>سرویس نرخ</h3>
            <p class="status-row"><span class="mono">{{ $quotes['driver'] }}</span>@if($quotes['is_demo'])<span class="badge warn">عدد نمونه (نه نرخ واقعی)</span>@else<span class="badge ok">واقعی</span>@endif</p>
            <dl class="kv">
                <div><dt>نام</dt><dd>{{ $quotes['name'] }}</dd></div>
                <div><dt>دارایی‌های دریافت‌شده</dt><dd>{{ fa($quotes['assets']) }} از {{ fa($quotes['total']) }}</dd></div>
                <div><dt>آخرین خطا</dt><dd class="small">{{ $quotes['last_error'] ? jdate(\Carbon\CarbonImmutable::parse($quotes['last_error']), true) : '—' }}</dd></div>
            </dl>
            <a class="btn sm btn-line" href="{{ route('admin.quotes') }}">وضعیت نرخ‌ها</a>
        </section>
    </div>
    <section class="band stack-sm">
        <h2>لینک‌های عمومی فاکتور</h2>
        <p>دامنه صفحه فاکتور (<span class="mono">/i</span>) و تأیید QR (<span class="mono">/v</span>): <span class="mono">{{ $publicUrl }}</span></p>
        <p class="xs muted">تغییر دامنه باید دامنه قبلی را با redirect نگه دارد تا QRهای چاپ‌شده هرگز از کار نیفتند. محیط: <span class="mono">{{ $env }}</span>.</p>
    </section>
</x-layouts.admin>
