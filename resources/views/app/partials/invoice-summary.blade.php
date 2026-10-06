{{-- Read-only invoice body built from the snapshot presenter ($v). --}}
<section class="band stack-sm" aria-label="اقلام فاکتور">
    <ul class="list">
        @foreach ($v['rows'] as $r)
            <li class="list-item"><span class="body"><strong>{{ $r['name'] }}</strong>
                <span class="sub">@if($r['type'] === 'GOLD'){{ $r['weight'] }} گرم · {{ $r['purity'] }} · اجرت {{ $r['wage_percent'] }} · سود {{ $r['profit_percent'] }}@else متفرقه@endif @if($r['description']) · {{ $r['description'] }}@endif</span></span>
                <span class="num strong nowrap">{{ $r['amount'] }}</span></li>
        @endforeach
    </ul>
    <dl class="kv">
        @if ($v['has_gold'])
            <div><dt>وزن کل طلا</dt><dd class="num">{{ $v['weight_total'] }} گرم</dd></div>
            <div><dt>ارزش طلا</dt><dd class="num">{{ $v['metal_fa'] }}</dd></div>
            <div><dt>اجرت</dt><dd class="num">{{ $v['wage_fa'] }}</dd></div>
            <div><dt>سود</dt><dd class="num">{{ $v['profit_fa'] }}</dd></div>
            <div><dt>مالیات ({{ $v['tax_rate_fa'] }}٪ روی اجرت و سود)</dt><dd class="num">{{ $v['vat_fa'] }}</dd></div>
        @endif
        @if ($v['has_misc'])<div><dt>اقلام متفرقه</dt><dd class="num">{{ $v['misc_total_fa'] }}</dd></div>@endif
    </dl>
    <div class="row-total"><span>مبلغ قابل پرداخت</span><strong class="num">{{ $v['payable_fa'] }} تومان</strong></div>
    <p class="xs muted">
        صدور: {{ $v['issued_fa'] }} @if($v['issuer'])· صادرکننده: {{ $v['issuer'] }}@endif
        @if ($v['rate_fa']) · نرخ ۱۸ عیار: {{ $v['rate_fa'] }} تومان @if($v['rate_manual'])(نرخ دستی: {{ $v['rate_reason_fa'] }})@endif @endif
        @if ($v['tax_sample']) · نرخ مالیات نمونه است و باید با مشاور تأیید شود @endif
    </p>
</section>
