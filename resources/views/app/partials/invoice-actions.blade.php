@php
    $smsBoot = $sms ? ['status' => $sms->status, 'label_fa' => $smsStatus[0], 'kind' => $smsStatus[1], 'final' => in_array($sms->status, \App\Models\SmsMessage::FINAL, true)] : null;
    $boot = [
        'id' => $invoice->public_id, 'status' => $invoice->status, 'sms' => $smsBoot,
        'share_url' => $shareUrl, 'status_url' => route('api.invoices.status', $invoice),
        'share_api' => route('api.invoices.share', $invoice), 'revoke_api' => route('api.invoices.share.revoke', $invoice),
        'sms_api' => route('api.invoices.sms', $invoice), 'void_api' => route('api.invoices.void', $invoice),
        'replace_api' => route('api.invoices.replace', $invoice), 'verify_revoke_api' => route('api.invoices.verification.revoke', $invoice), 'number' => $v['number'], 'shop' => $v['shop']['name'] ?? '',
    ];
@endphp
<script type="application/json" id="boot">@json($boot)</script>

@if ($invoice->buyer_mobile)
    <section class="band stack-sm" aria-labelledby="sms-h">
        <div class="between"><h2 id="sms-h">پیامک به مشتری</h2>
            <span class="badge {{ $smsStatus[1] ?? 'off' }}" data-sms-badge role="status">{{ $smsStatus[0] ?? 'ارسال نشده' }}</span></div>
        <p class="small muted">به <bdi class="num ltr" dir="ltr">{{ $v['buyer_mobile'] }}</bdi></p>
        <div class="notice warn hidden" data-sms-credit>اعتبار پیامک کافی نیست. پس از خرید اعتبار، پیامک همین فاکتور خودکار ارسال می‌شود. موجودی: {{ $balanceFa }} تومان.
            <a class="btn sm btn-dark" href="{{ route('settings.sms', ['return' => $invoice->public_id]) }}">خرید اعتبار پیامک</a></div>
        @if ($invoice->status === 'issued')
            <button type="button" class="btn btn-line block {{ !$sms || in_array($sms->status, ['FAILED', 'UNKNOWN', 'AWAITING_CREDIT'], true) ? '' : 'hidden' }}" data-sms-send data-busy-text="در حال ارسال…">{{ $sms ? 'ارسال دوباره پیامک' : 'ارسال پیامک فاکتور' }}</button>
            <p class="xs muted">برای جلوگیری از پیامک تکراری، ارسال دوباره فقط پس از ناموفق‌بودن و با فاصله ممکن است.</p>
        @endif
    </section>
@endif

<section class="band stack-sm qr-panel" aria-labelledby="qr-h">
    <h2 id="qr-h">بارکد بررسی اصالت</h2>
    <div class="qr-screen" role="img" aria-label="بارکد بررسی اصالت فاکتور {{ $v['number'] }}">{!! $qr !!}</div>
    <a class="btn btn-line block" href="{{ $verifyUrl }}" target="_blank" rel="noopener">بررسی این فاکتور</a>
    <p class="xs muted">همین بارکد بالا-چپ چاپ فاکتور است. مشتری با اسکن آن، بدون ورود، اصالت و وضعیت فاکتور (قطعی یا باطل) را می‌بیند.</p>
    @if ($canVoid)
        <button type="button" class="btn btn-link sm" data-verify-revoke>بارکد سوءاستفاده شده؟ لغو امنیتی بارکد</button>
        <template data-verify-revoke-tpl>
            <div class="between"><h2>لغو امنیتی بارکد</h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>
            <form method="post" class="stack" data-verify-revoke-form novalidate>
                <div class="notice warn stack-sm">
                    <p>فقط وقتی لازم است که از برگه چاپی یا عکس این فاکتور سوءاستفاده شده باشد.</p>
                    <p><strong>اثر روی برگه‌های چاپ‌شده:</strong> بارکد همه برگه‌هایی که تا امروز چاپ یا عکس گرفته شده از این به بعد «لغوشده» نشان می‌دهد، حتی برگه اصلی دست مشتری. برای مشتری یک برگه تازه چاپ کنید.</p>
                    <p>خود فاکتور، مبلغ و وضعیت آن عوض نمی‌شود و این کار برگشت‌پذیر نیست.</p>
                </div>
                <div class="field"><label for="vr-reason">دلیل</label><div class="input-wrap"><input id="vr-reason" name="reason" maxlength="200" required placeholder="مثلاً: عکس فاکتور در فضای مجازی پخش شده"></div><div class="err"></div></div>
                <button type="submit" class="btn btn-danger block" data-busy-text="در حال لغو…">بارکد قبلی لغو شود</button>
                <button type="button" class="btn btn-line block" data-close>انصراف</button>
            </form>
        </template>
    @endif
</section>

<section class="band stack-sm" aria-labelledby="share-h">
    <h2 id="share-h">لینک فاکتور برای مشتری</h2>
    <div class="{{ $shareUrl ? '' : 'hidden' }} stack-sm" data-share-box>
        <div class="input-wrap ltr-input"><input readonly value="{{ $shareUrl }}" data-share-url aria-label="لینک فاکتور"></div>
        <div class="cluster">
            <button type="button" class="btn sm btn-dark" data-copy>کپی لینک</button>
            <button type="button" class="btn sm btn-line" data-native-share>اشتراک‌گذاری</button>
            <button type="button" class="btn sm btn-link" data-revoke>غیرفعال‌کردن لینک</button>
        </div>
    </div>
    @if ($invoice->status === 'issued')
        <button type="button" class="btn btn-line block {{ $shareUrl ? 'hidden' : '' }}" data-share-create data-busy-text="در حال ساخت…">ساخت لینک فاکتور</button>
        @if ($links['limit'] !== null)<p class="xs muted">لینک‌های این ماه: {{ fa($links['used']) }} از {{ fa($links['limit']) }}</p>@endif
    @endif
    <p class="xs muted">بارکد بررسی اصالت روی چاپ همیشه کار می‌کند و به سهمیه لینک وابسته نیست.</p>
</section>
