@php
    $stateFa = ['SENT' => ['منتظر تأیید', 'warn'], 'CONFIRMED' => ['تأیید شد', 'ok'], 'EXPIRED' => ['ابطال شده', 'err'], 'CANCELLED' => ['ابطال شده', 'err']];
@endphp
<x-layouts.app title="پیش‌فاکتورها" page="" :back="route('invoices.index')">
    <p class="small muted">پیش‌فاکتور را از صفحه «مرور و صدور» هر فاکتور بفرستید. مشتری با موبایل و کد پیامکی تأیید می‌کند و فاکتور فروش صادر می‌شود.</p>
    @if ($items->isEmpty())
        <div class="band center stack-sm"><p>هنوز پیش‌فاکتوری نفرستاده‌اید.</p><a class="btn btn-gold" href="{{ route('invoices.new') }}">+ ثبت فاکتور جدید</a></div>
    @else
        <ul class="list">
            @foreach ($items as $p)
                @php $st = $p->state(); @endphp
                <li><a class="list-item" href="{{ route('proformas.show', $p) }}"><span class="body"><strong>پیش‌فاکتور <span class="num ltr">{{ fa($p->number) }}</span> · {{ $p->buyer_name ?: \App\Support\Digits::toPersian(\App\Support\Mobile::mask($p->buyer_mobile)) }}</strong>
                    <span class="sub">{{ toman($p->payable_irr) }} تومان · {{ $st === 'SENT' ? 'مهلت '.\App\Domain\Invoices\ProformaService::until($p, $tz) : jdate($p->created_at) }}</span></span>
                    @if ($st === 'CONFIRMED' && ! $p->issued_at)<span class="badge warn">تأیید شد · منتظر صدور</span>@else<span class="badge {{ $stateFa[$st][1] }}">{{ $st === 'CONFIRMED' ? 'فاکتور صادر شد' : $stateFa[$st][0] }}</span>@endif</a></li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
