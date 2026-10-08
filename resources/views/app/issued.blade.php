<x-layouts.app title="فاکتور صادر شد" page="invoice">
    <section class="hero center stack-sm" aria-live="polite">
        <span class="badge ok">صادر شد</span>
        <h2>فاکتور شماره <span class="num ltr">{{ $v['number'] }}</span></h2>
        @if ($v['customer_credit'] ?? false)<p class="meta">مانده به نفع مشتری</p>@endif
        <div><span class="price">{{ $v['payable_fa'] }}</span> <span class="unit">تومان</span></div>
        @if ($v['buyer_name'])<p class="meta">مشتری: {{ $v['buyer_name'] }}</p>@endif
    </section>
    <div class="notice warn hidden" data-sms-note role="alert"></div>

    <div class="grid-2">
        <a class="btn btn-gold block" href="{{ route('invoices.print', $invoice) }}" target="_blank" rel="noopener">چاپ / PDF</a>
        <a class="btn btn-dark block" href="{{ route('invoices.new') }}">فاکتور جدید</a>
    </div>

    @if ($installmentUrl ?? null)
        <a class="list-item em" href="{{ $installmentUrl }}"><span class="body"><strong>ثبت اقساط این فاکتور</strong><span class="sub">روش تسویه «قسطی» انتخاب شده است؛ تعداد و تاریخ قسط‌ها را تعیین کنید.</span></span><span aria-hidden="true">‹</span></a>
    @endif
    @include('app.partials.invoice-actions')

    <a class="list-item" href="{{ route('invoices.show', $invoice) }}"><span class="body"><strong>جزئیات کامل فاکتور</strong><span class="sub">ابطال، صدور جایگزین، تاریخچه پیامک</span></span><span aria-hidden="true">‹</span></a>
</x-layouts.app>
