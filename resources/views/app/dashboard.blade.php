@php
    $rangeLabels = ['day' => 'امروز', 'week' => 'این هفته', 'month' => 'این ماه', 'quarter' => 'سه ماه', 'year' => 'امسال', 'custom' => 'بازه دلخواه'];
    $metricLabels = [
        'sales' => ['مجموع فروش', 'جمع فاکتورهای صادرشده (طلا و متفرقه)'],
        'wage' => ['اجرت دریافتی', 'اجرت ردیف‌های طلا، بعد از تخفیف'],
        'profit' => ['سود فروش', 'سود ردیف‌های طلا، بعد از تخفیف'],
        'gold_in' => ['طلای خریداری‌شده از مشتری', 'طلایی که مشتری به‌جای پول داده'],
        'vat' => ['مالیات بر ارزش افزوده', 'دریافتی از مشتری روی اجرت و سود'],
    ];
    $boot = ['report' => $report, 'api' => route('api.dashboard'), 'labels' => $metricLabels];
    $lock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
@endphp
<x-layouts.app title="داشبورد فروش" page="dashboard" :back="route('settings')">
    <script type="application/json" id="boot">@json($boot)</script>

    <section class="stack-sm" aria-label="انتخاب بازه">
        <div class="chips dash-ranges" role="group" aria-label="بازه زمانی">
            @foreach ($rangeLabels as $key => $label)
                @if (in_array($key, $access['ranges'], true))
                    <button type="button" class="chip" data-range="{{ $key }}" aria-pressed="{{ $report['range'] === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @else
                    <a class="chip chip-locked" href="{{ route('settings.plan') }}" title="در پلن پایه و حرفه‌ای">{!! $lock !!}{{ $label }}</a>
                @endif
            @endforeach
        </div>
        @if (in_array('custom', $access['ranges'], true))
            <form method="post" class="dash-custom band hidden" data-custom novalidate>
                <div class="grid-2">
                    <div class="field" data-jdp data-quick="today"><span class="label">از تاریخ</span><div class="input-wrap"><input type="hidden" name="from"></div><div class="err"></div></div>
                    <div class="field" data-jdp data-quick="today"><span class="label">تا تاریخ</span><div class="input-wrap"><input type="hidden" name="to"></div><div class="err"></div></div>
                </div>
                <button type="submit" class="btn btn-dark block">نمایش این بازه</button>
            </form>
        @endif
        <div class="between dash-bar">
            <p class="small" data-period><strong data-label>{{ $report['label_fa'] }}</strong> · <span data-count>{{ $report['invoices_fa'] }}</span> فاکتور</p>
            <div class="seg seg-sm" role="radiogroup" aria-label="واحد نمایش">
                <label><input type="radio" name="unit" value="toman" checked>تومان</label>
                <label><input type="radio" name="unit" value="g">گرم طلا</label>
            </div>
        </div>
    </section>

    <div class="notice info hidden" data-empty>در این بازه هنوز فاکتوری صادر نشده است. <a href="{{ route('invoices.new') }}">فاکتور جدید</a></div>

    <section class="dash-tiles" aria-label="خلاصه اعداد" aria-live="polite">
        @foreach ($metricLabels as $key => [$label, $hint])
            @if (isset($report['metrics'][$key]))
                @php $m = $report['metrics'][$key]; @endphp
                <article class="tile dash-tile m-{{ $key }}" data-metric="{{ $key }}">
                    <h2 class="t-label">{{ $label }}</h2>
                    <p class="t-big"><span class="num" data-big>{{ $m['toman_fa'] }}</span> <span class="unit" data-unit>تومان</span></p>
                    <p class="t-small num" data-small>{{ $m['g_fa'] ?? '' }}</p>
                    <p class="t-delta xs" data-delta></p>
                    <p class="xs muted t-hint">{{ $hint }}</p>
                </article>
            @else
                <a class="tile dash-tile is-locked" href="{{ route('settings.plan') }}">
                    <h2 class="t-label">{{ $label }}</h2>
                    <p class="t-big muted">{!! $lock !!} <span class="small">در پلن پایه و حرفه‌ای</span></p>
                    <p class="xs muted t-hint">{{ $hint }}</p>
                </a>
            @endif
        @endforeach
    </section>

    <section class="band stack-sm" aria-labelledby="chart-h">
        <div class="between"><h2 id="chart-h">نمودار</h2><span class="xs muted" data-chart-unit>تومان</span></div>
        <div class="seg seg-scroll" role="radiogroup" aria-label="شاخص نمودار" data-metric-tabs>
            @foreach ($report['metrics'] as $key => $m)
                <label><input type="radio" name="metric" value="{{ $key }}" @checked($loop->first)>{{ $metricLabels[$key][0] }}</label>
            @endforeach
        </div>
        <p class="small dash-picked" data-picked aria-live="polite">برای دیدن عدد هر ستون، روی آن بزنید.</p>
        <figure class="dash-chart" data-chart role="img" aria-label="نمودار ستونی"></figure>
        <details>
            <summary class="small">نمایش جدول اعداد</summary>
            <div class="table-scroll"><table class="dash-table" data-table></table></div>
        </details>
    </section>

    <p class="xs muted">فقط فاکتورهای صادرشده و باطل‌نشده حساب می‌شوند. گرم یعنی گرم طلای ۱۸ عیار (معادل ۷۵۰)؛ اجرت و سود به گرم با نرخ همان فاکتور تبدیل شده‌اند. مالیات فقط به تومان است.</p>
</x-layouts.app>
