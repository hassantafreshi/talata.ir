<x-layouts.app title="فاکتور صادر شد" page="invoice">
    <section class="hero center stack-sm" aria-live="polite">
        <span class="badge ok">صادر شد</span>
        <h2>فاکتور شماره <span class="num ltr">{{ $v['number'] }}</span></h2>
        <div><span class="price">{{ $v['payable_fa'] }}</span> <span class="unit">تومان</span></div>
        @if ($v['buyer_name'])<p class="meta">مشتری: {{ $v['buyer_name'] }}</p>@endif
    </section>
    <div class="notice warn hidden" data-sms-note role="alert"></div>

    <div class="grid-2">
        <a class="btn btn-gold block" href="{{ route('invoices.print', $invoice) }}" target="_blank" rel="noopener">چاپ / PDF</a>
        <a class="btn btn-dark block" href="{{ route('invoices.new') }}">فاکتور جدید</a>
    </div>

    @include('app.partials.invoice-actions')

    <a class="list-item" href="{{ route('invoices.show', $invoice) }}"><span class="body"><strong>جزئیات کامل فاکتور</strong><span class="sub">ابطال، صدور جایگزین، تاریخچه پیامک</span></span><span aria-hidden="true">‹</span></a>
</x-layouts.app>
