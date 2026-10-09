<x-layouts.public :scripts="false" :title="'فاکتور '.$v['number'].' · '.$v['shop']['name']" :og="$og ?? null">
    <section class="band stack-sm">
        <div class="between"><strong>{{ $v['shop']['name'] }}</strong>@if($v['status'] === 'void')<span class="badge err">باطل‌شده</span>@else<span class="badge ok">قطعی</span>@endif</div>
        <span class="small">{{ $v['shop']['address'] }}</span>
        <span class="small">تلفن: <a class="num ltr" href="tel:{{ \App\Support\Digits::toLatin($v['shop']['contact_primary']) }}">{{ $v['shop']['contact_primary'] }}</a></span>
        <dl class="kv">
            <div><dt>شماره فاکتور</dt><dd class="num ltr">{{ $v['number'] }}</dd></div>
            <div><dt>تاریخ</dt><dd class="num">{{ $v['issued_fa'] }}</dd></div>
            @if ($v['buyer_name'])<div><dt>خریدار</dt><dd>{{ $v['buyer_name'] }}</dd></div>@endif
            @if ($v['rate_fa'])<div><dt>نرخ ۱۸ عیار</dt><dd class="num">{{ $v['rate_fa'] }} تومان @if($v['rate_manual'])(دستی)@elseif($v['rate_emergency'] ?? false)(نرخ اعلامی زرلیو)@endif</dd></div>@endif
        </dl>
    </section>
    @if ($v['status'] === 'void')<div class="notice err">این فاکتور در {{ $v['voided_fa'] }} باطل شده است.</div>@endif

    @include('app.partials.invoice-summary')

    <div class="grid-2">
        <a class="btn btn-gold block" href="{{ route('public.invoice.print', $token) }}" rel="noopener">نسخه چاپی / PDF</a>
        <a class="btn btn-line block" href="{{ $verifyUrl }}" rel="noopener">بررسی این فاکتور</a>
    </div>
    @if ($v['show_talata_mark'])<p class="xs muted center">صادرشده با زرلیو</p>@endif
    @include('site.enamad')
</x-layouts.public>
