<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>رسید پرداخت {{ $order->public_ref }}</title>
@vite(['resources/js/print.js'])
</head>
<body class="print-page">
<div class="print-tools"><button type="button" data-print>چاپ یا ذخیره PDF</button><a href="{{ $order->product === 'PLAN' ? route('settings.plan') : route('settings.sms') }}">بازگشت</a></div>
<article class="inv receipt">
    @if ($mock)<span class="sample-stamp">پرداخت آزمایشی — بدون جابه‌جایی پول</span>@endif
    <header class="inv-head receipt-head">
        <div class="blocks">
            <div class="shop-name">رسید پرداخت زرلیو</div>
            <div>خریدار: {{ $shop?->name ?: '—' }}</div>
            <div>شماره سفارش: <span class="num ltr">{{ $order->public_ref }}</span></div>
            <div>تاریخ پرداخت: <span class="num">{{ jdate($order->paid_at, true) }}</span></div>
        </div>
    </header>
    <table>
        <thead><tr><th scope="col">شرح</th><th class="n" scope="col">مبلغ (تومان)</th></tr></thead>
        <tbody>
            <tr><td>{{ $order->product === 'PLAN' ? 'پلن '.($order->price_snapshot['plan_label_fa'] ?? $order->plan_code).' '.($order->period === 'yearly' ? 'سالانه' : 'ماهانه') : 'اعتبار پیامک' }}</td><td class="n">{{ toman($order->subtotal_irr) }}</td></tr>
            <tr><td>مالیات بر ارزش افزوده {{ pct($order->vat_rate_percent) }}٪</td><td class="n">{{ toman($order->vat_irr) }}</td></tr>
            <tr><td><strong>جمع پرداختی</strong></td><td class="n"><strong>{{ toman($order->amount_irr) }}</strong></td></tr>
        </tbody>
    </table>
    <dl class="inv-sum receipt-meta">
        @if ($attempt?->ref_id)<div><dt>کد پیگیری بانک</dt><dd class="ltr">{{ $attempt->ref_id }}</dd></div>@endif
        @if ($attempt?->card_mask)<div><dt>کارت</dt><dd class="ltr">{{ $attempt->card_mask }}</dd></div>@endif
    </dl>
    <p class="inv-notes">این رسید تأیید پرداخت در زرلیو است و جایگزین فاکتور رسمی مالیاتی نیست.</p>
</article>
</body>
</html>
