@php
    $smsBoot = $sms ? ['status' => $sms->status, 'label_fa' => $smsStatus[0], 'kind' => $smsStatus[1], 'final' => in_array($sms->status, \App\Models\SmsMessage::FINAL, true)] : null;
    $issued = $invoice->status === 'issued';
    $boot = [
        'id' => $invoice->public_id, 'status' => $invoice->status, 'sms' => $smsBoot,
        'share_url' => $shareUrl, 'status_url' => route('api.invoices.status', $invoice),
        'share_api' => route('api.invoices.share', $invoice), 'revoke_api' => route('api.invoices.share.revoke', $invoice),
        'sms_api' => route('api.invoices.sms', $invoice), 'copies_api' => route('api.invoices.sms.copies', $invoice),
        'void_api' => route('api.invoices.void', $invoice), 'verify_revoke_api' => route('api.invoices.verification.revoke', $invoice),
        'replace_api' => route('api.invoices.replace', $invoice), 'number' => $v['number'], 'shop' => $v['shop']['name'] ?? '',
        'has_buyer_mobile' => (bool) $invoice->buyer_mobile, 'buy_url' => route('settings.sms', ['return' => $invoice->public_id]),
        'max_numbers' => (int) config('talata.sms.max_copy_recipients_per_send'),
    ];
@endphp
<script type="application/json" id="boot">@json($boot)</script>

@if ($issued)
    <section class="action-pair" aria-label="فرستادن فاکتور برای مشتری">
        <button type="button" class="action-tile" data-share-now data-busy-text="در حال آماده‌سازی…">
            <svg aria-hidden="true" viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
            <span class="t">اشتراک‌گذاری</span>
            <span class="s">واتساپ، تلگرام، ایتا، بله…</span>
        </button>
        @if ($canSms)
            <button type="button" class="action-tile" data-sms-open aria-haspopup="dialog">
                <svg aria-hidden="true" viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M8.5 11h7M8.5 14h4"/></svg>
                <span class="t">ارسال پیامک</span>
                <span class="s"><span class="badge {{ $smsStatus[1] ?? 'off' }}" data-sms-badge role="status">{{ $smsStatus[0] ?? 'ارسال نشده' }}</span></span>
            </button>
        @else
            <a class="action-tile" href="{{ route('settings.plan') }}">
                <svg aria-hidden="true" viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
                <span class="t">ارسال پیامک</span><span class="s">در این پلن فعال نیست</span>
            </a>
        @endif
    </section>
    <div class="notice warn {{ $sms?->status === 'AWAITING_CREDIT' ? '' : 'hidden' }}" data-sms-credit>اعتبار پیامک کافی نیست. پس از خرید اعتبار، پیامک همین فاکتور خودکار ارسال می‌شود. موجودی: {{ $balanceFa }} تومان.
        <a class="btn sm btn-dark" href="{{ route('settings.sms', ['return' => $invoice->public_id]) }}">خرید اعتبار پیامک</a></div>

    @if ($canSms && $smsPreview)
        <template data-sms-tpl>
            <div class="between"><h2>ارسال پیامک فاکتور</h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>
            <div class="sms-bubble" dir="rtl">{{ $smsPreview['body'] }}</div>
            <p class="xs muted center">{{ fa($smsPreview['segments']) }} بخش ·
                @if ($smsPreview['free_remaining'] > 0) از پیامک رایگان سالانه ({{ fa($smsPreview['free_remaining']) }} مانده)
                @else هر پیامک {{ toman($smsPreview['cost_irr']) }} تومان · موجودی {{ toman($smsPreview['balance_irr']) }} تومان @endif</p>

            <div class="sms-option stack-sm">
                <div class="opt-head">
                    <span class="opt-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
                    <span class="grow"><strong>مشتری</strong>
                        @if ($invoice->buyer_mobile)<bdi class="num ltr" dir="ltr">{{ $v['buyer_mobile'] }}</bdi>@endif</span>
                    <span class="badge {{ $smsStatus[1] ?? 'off' }}" data-sms-badge>{{ $smsStatus[0] ?? 'ارسال نشده' }}</span>
                </div>
                @if ($invoice->buyer_mobile)
                    <button type="button" class="btn btn-gold block" data-sms-customer data-busy-text="در حال ارسال…">{{ $sms ? 'ارسال دوباره به مشتری' : 'ارسال پیامک به مشتری' }}</button>
                    <p class="xs muted" data-sms-customer-note>برای جلوگیری از پیامک تکراری، تا وقتی پیامک قبلی در راه است یا وضعیتش روشن نیست، ارسال دوباره ممکن نیست.</p>
                @else
                    <p class="small muted">موبایل مشتری روی این فاکتور ثبت نشده است. از «ارسال به شماره دیگر» استفاده کنید.</p>
                @endif
            </div>

            <form class="sms-option stack-sm" data-sms-others novalidate>
                <div class="opt-head">
                    <span class="opt-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 8v6M14 11h6"/></svg></span>
                    <span class="grow"><strong>ارسال به شماره دیگر</strong></span>
                </div>
                <div class="field"><label for="so-m">شماره موبایل (یک یا چند شماره)</label>
                    <div class="input-wrap ltr-input"><textarea id="so-m" name="mobiles" rows="2" inputmode="tel" autocomplete="off" maxlength="300" placeholder="۰۹۱۲ ۳۴۵ ۶۷۸۹"></textarea></div><div class="err"></div>
                    <p class="hint">با <bdi dir="ltr">۰۹</bdi>، <bdi dir="ltr">+۹۸</bdi> یا <bdi dir="ltr">۰۰۹۸</bdi>. چند شماره را با فاصله، ویرگول، خط جدید یا حتی پشت سر هم بنویسید؛ هر بار حداکثر {{ fa(config('talata.sms.max_copy_recipients_per_send')) }} شماره.</p></div>
                <div class="chips" data-mobile-chips aria-live="polite"></div>
                <button class="btn btn-dark block" type="submit" disabled data-busy-text="در حال ارسال…" data-others-submit>ارسال</button>
                @if ($copies->isNotEmpty())
                    <ul class="copies" aria-label="ارسال‌های قبلی به شماره‌های دیگر">
                        @foreach ($copies as $c)<li><bdi class="num ltr" dir="ltr">{{ \App\Support\Mobile::display($c->recipient) }}</bdi> <span class="badge {{ \App\Http\Controllers\App\InvoiceController::SMS_STATUS_FA[$c->status][1] }}">{{ \App\Http\Controllers\App\InvoiceController::SMS_STATUS_FA[$c->status][0] }}</span></li>@endforeach
                    </ul>
                @endif
            </form>
            <a class="small center" href="{{ route('settings.sms', ['return' => $invoice->public_id]) }}">خرید اعتبار پیامک</a>
        </template>
    @endif
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

<details class="band stack-sm" {{ $shareUrl ? '' : 'hidden' }} data-share-box>
    <summary><strong>لینک فاکتور</strong> <span class="xs muted">کپی یا غیرفعال‌کردن</span></summary>
    <div class="input-wrap ltr-input"><input readonly value="{{ $shareUrl }}" data-share-url aria-label="لینک فاکتور"></div>
    <div class="cluster">
        <button type="button" class="btn sm btn-dark" data-copy>کپی لینک</button>
        @if ($canVoid)<button type="button" class="btn sm btn-link" data-revoke>غیرفعال‌کردن لینک</button>@endif
    </div>
    @if ($links['limit'] !== null)<p class="xs muted">لینک‌های این ماه: {{ fa($links['used']) }} از {{ fa($links['limit']) }}</p>@endif
    <p class="xs muted">بارکد بررسی اصالت روی چاپ همیشه کار می‌کند و به سهمیه لینک وابسته نیست.</p>
</details>
