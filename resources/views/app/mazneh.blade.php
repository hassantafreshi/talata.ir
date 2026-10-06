@php($r = $board['rows'])
@php($dir = fn ($row) => $row['direction'] > 0 ? '+ ' : ($row['direction'] < 0 ? '− ' : ''))
@php($chg = fn ($row) => $row['change_fa'] ? $dir($row).$row['change_fa'].' از دریافت قبلی' : 'بدون تغییر')
<x-layouts.app title="مظنه" page="mazneh">
    <div class="between small" data-board-meta>
        <span>آخرین دریافت <span class="num" data-time>{{ $board['fetched_at_fa'] ?? '—' }}</span> · به‌روزرسانی هر ۳ دقیقه</span>
        @include('partials.freshness', ['f' => $board['freshness']])
    </div>

    <section class="hero stack-sm" aria-labelledby="g18">
        <div class="between"><span class="label" id="g18">طلای ۱۸ عیار (هر گرم)</span>@if($board['is_demo'])<span class="badge dark">عدد نمونه</span>@endif</div>
        <div class="grid-2">
            <div><span class="small">خرید از شما</span><div class="price sm num" data-asset="GOLD_18_BUY">{{ $r['GOLD_18_BUY']['display_fa'] ?? '—' }}</div><span class="xs" data-chg="GOLD_18_BUY">{{ $chg($r['GOLD_18_BUY']) }}</span></div>
            <div><span class="small">فروش به مشتری · مبنای فاکتور</span> <span class="badge warn" data-emergency @if(! $r['GOLD_18_SELL']['is_emergency']) hidden @endif>نرخ اعلامی زرلیو (دستی)</span><div class="price sm num" data-asset="GOLD_18_SELL">{{ $r['GOLD_18_SELL']['display_fa'] ?? '—' }}</div><span class="xs" data-chg="GOLD_18_SELL">{{ $chg($r['GOLD_18_SELL']) }}</span></div>
        </div>
        <div class="meta">اختلاف خرید و فروش: <span class="num" data-spread>{{ $board['spread_fa'] ?? '—' }}</span> تومان · واحد: تومان / گرم</div>
        <a class="btn btn-gold block" href="{{ route('invoices.new') }}">شروع فاکتور با نرخ فروش</a>
    </section>

    <div class="list">
        <div class="list-item"><span class="body"><strong>طلای ۲۴ عیار</strong><span class="sub">هر گرم · <span data-chg="GOLD_24">{{ $chg($r['GOLD_24']) }}</span></span></span><span class="num strong" data-asset="GOLD_24">{{ $r['GOLD_24']['display_fa'] ?? '—' }}</span><span class="small muted">تومان</span></div>
        <div class="list-item"><span class="body"><strong>دلار بازار آزاد</strong><span class="sub"><span data-chg="USD_IRR">{{ $chg($r['USD_IRR']) }}</span></span></span><span class="num strong" data-asset="USD_IRR">{{ $r['USD_IRR']['display_fa'] ?? '—' }}</span><span class="small muted">تومان</span></div>
        <div class="list-item"><span class="body"><strong>انس جهانی طلا</strong><span class="sub">هر اونس · <span data-chg="XAU_USD">{{ $chg($r['XAU_USD']) }}</span></span></span><span class="num strong" data-asset="XAU_USD">{{ $r['XAU_USD']['display_fa'] ?? '—' }}</span><span class="small muted">دلار</span></div>
    </div>

    <div class="grid-2">
        <a class="btn btn-dark block" href="{{ route('calculator') }}">ماشین‌حساب طلایی</a>
        <button type="button" class="btn btn-line block" data-refresh data-busy-text="در حال دریافت…">تلاش دوباره الان</button>
    </div>
    <p class="xs muted">منبع: {{ $board['source_fa'] ?? '—' }} · نرخ‌ها هر ۱۸۰ ثانیه از سرویس مرکزی دریافت می‌شوند، نه لحظه‌ای. تغییر درصدها نسبت به دریافت قبلی است. در قطع اینترنت، آخرین نرخ با زمان دریافت و برچسب «آفلاین» می‌ماند. نرخ مبنای فاکتور «فروش ۱۸ عیار» است و در لحظه «شروع» ثبت می‌شود.</p>
</x-layouts.app>
