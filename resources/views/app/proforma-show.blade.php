@php
    $state = $v['state'];
    $closed = in_array($state, ['EXPIRED', 'CANCELLED'], true);
    $boot = [
        'link' => $link, 'text' => $shareText, 'number' => $v['number'],
        'sms_api' => route('api.proformas.sms', $p), 'cancel_api' => route('api.proformas.cancel', $p), 'issue_api' => route('api.proformas.issue', $p),
    ];
    $smsFa = ['QUEUED' => ['در صف ارسال', 'info'], 'SENDING' => ['در حال ارسال', 'info'], 'SENT' => ['ارسال شد', 'ok'], 'DELIVERED' => ['رسید', 'ok'], 'FAILED' => ['ناموفق', 'err'], 'UNKNOWN' => ['نامشخص', 'warn'], 'CANCELLED' => ['لغو شد', 'off'], 'AWAITING_CREDIT' => ['منتظر اعتبار', 'warn']];
    $stateFa = ['SENT' => ['منتظر تأیید مشتری', 'warn'], 'CONFIRMED' => ['تأیید شد', 'ok'], 'EXPIRED' => ['ابطال شده (مهلت تمام شد)', 'err'], 'CANCELLED' => ['ابطال شده', 'err']];
@endphp
<x-layouts.app title="پیش‌فاکتور" page="proforma" :back="route('proformas.index')">
    <script type="application/json" id="boot">@json($boot)</script>
    <section class="hero pf-hero stack-sm {{ $closed ? 'pf-void' : ($state === 'CONFIRMED' ? 'pf-ok' : '') }}" aria-live="polite">
        <span class="badge {{ $stateFa[$state][1] }}">{{ $stateFa[$state][0] }}</span>
        <h2>پیش‌فاکتور شماره <span class="num ltr">{{ $v['number'] }}</span></h2>
        <div><span class="price">{{ $v['payable_fa'] }}</span> <span class="unit">تومان</span></div>
        <p class="meta">مشتری: {{ $v['buyer_name'] ?: '—' }} · <span class="num ltr">{{ $v['buyer_mobile'] }}</span></p>
        <p class="meta">مدت اعتبار {{ $v['hours_fa'] }} · مهلت تأیید {{ $v['until_fa'] }}</p>
        @if ($state === 'SENT')
            <span class="pf-lock"><span aria-hidden="true">🔒</span> قیمت با نرخ {{ $v['rate_fa'] ?? '—' }} تومان قفل است</span>
            <p class="xs">پس از تأیید مشتری: <strong>{{ $p->auto_issue ? 'فاکتور فروش خودکار صادر می‌شود' : 'شما «صدور فاکتور فروش» را می‌زنید' }}</strong></p>
        @endif
        @if ($state === 'CONFIRMED')<p class="meta">تأیید مشتری: {{ $v['confirmed_fa'] }}</p>@endif
    </section>

    @if ($state === 'SENT')
        <section class="action-pair" aria-label="فرستادن پیش‌فاکتور برای مشتری">
            <button type="button" class="action-tile" data-pf-share>
                <svg aria-hidden="true" viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
                <span class="t">اشتراک‌گذاری</span><span class="s">واتساپ، تلگرام، ایتا، بله…</span>
            </button>
            @if ($canSms)
                <button type="button" class="action-tile" data-pf-sms data-busy-text="در حال ارسال…">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M8.5 11h7M8.5 14h4"/></svg>
                    <span class="t">{{ $sms ? 'ارسال دوباره پیامک' : 'ارسال پیامک' }}</span>
                    <span class="s">@if($sms)<span class="badge {{ $smsFa[$sms->status][1] ?? 'off' }}" data-pf-sms-badge>{{ $smsFa[$sms->status][0] ?? $sms->status }}</span>@else<span data-pf-sms-badge>ارسال نشده</span>@endif</span>
                </button>
            @endif
        </section>
        <div class="field"><label for="pf-link">لینک پیش‌فاکتور</label><div class="input-wrap ltr-input"><input id="pf-link" value="{{ $link }}" readonly data-pf-link></div>
            <p class="hint">مشتری با باز کردن لینک، شماره موبایل خود و کد پیامکی را وارد می‌کند تا خرید تأیید شود. مهلت تأیید در پیامک و لینک نوشته شده است.</p></div>
    @elseif ($state === 'CONFIRMED' && ! $p->issued_at)
        <section class="band pf-ready stack-sm" role="status">
            <h2>مشتری پیش‌فاکتور را تأیید کرد ✓</h2>
            @if ($p->issue_error)
                <p class="notice warn small">صدور خودکار انجام نشد. دلیل: {{ $p->issue_error }}</p>
            @else
                <p class="small">صدور فاکتور با شماست (روش «دستی»). پس از بررسی، فاکتور فروش با همین اقلام و مبلغ صادر می‌شود.</p>
            @endif
            <button type="button" class="btn btn-gold block lg" data-pf-issue data-busy-text="در حال صدور…">صدور فاکتور فروش</button>
        </section>
    @elseif ($state === 'CONFIRMED')
        <a class="btn btn-gold block" href="{{ route('invoices.issued', $invoice) }}">فاکتور فروش صادرشده (شماره <span class="num ltr">{{ invno($invoice->number) }}</span>)</a>
    @else
        <div class="notice err">این پیش‌فاکتور {{ $state === 'EXPIRED' ? 'به‌دلیل پایان مهلت تأیید' : '' }} ابطال شده است و مشتری دیگر نمی‌تواند آن را تأیید کند؛ روی لینک مشتری «ابطال شده» نوشته شده است.</div>
    @endif

    <div class="grid-2">
        <a class="btn btn-line block" href="{{ route('proformas.print', $p) }}" target="_blank" rel="noopener">چاپ / PDF پیش‌فاکتور</a>
        @if (! $p->issued_at && ($state !== 'CANCELLED' || $invoice?->status === 'proforma'))
            <button type="button" class="btn btn-dark block" data-pf-cancel="EDIT" data-confirm="{{ $state === 'SENT' ? 'برای ویرایش، این پیش‌فاکتور ابطال می‌شود و لینک قبلی «ابطال شده» نشان می‌دهد. ادامه می‌دهید؟' : '' }}">{{ $closed ? 'ویرایش و ارسال دوباره' : 'ویرایش پیش‌فاکتور' }}</button>
        @elseif ($state === 'CANCELLED' && $invoice?->status === 'draft')
            <a class="btn btn-dark block" href="{{ route('invoices.items', $invoice) }}">باز کردن پیش‌نویس</a>
        @endif
    </div>
    @if (in_array($state, ['SENT', 'CONFIRMED'], true) && ! $p->issued_at)
        <button type="button" class="btn btn-link" data-pf-cancel="OTHER" data-confirm="پیش‌فاکتور ابطال شود؟ لینک مشتری «ابطال شده» نشان می‌دهد و پیش‌نویس برای ویرایش یا صدور باز می‌شود.">ابطال پیش‌فاکتور</button>
    @endif

    <div class="pf-doc {{ $closed ? 'is-void' : '' }}">
        @if ($closed)<div class="pf-stamp" aria-hidden="true">ابطال شده</div>@endif
        @include('app.partials.invoice-summary')
    </div>
</x-layouts.app>
