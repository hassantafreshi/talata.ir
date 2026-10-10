{{-- Today's sales on the home screen. Shown only to members with reports.view on a plan with dashboard.view; the
     plan decides which cards are open (DashboardService::access), locked ones open the upgrade sheet. --}}
@php
    $labels = \App\Domain\Reports\DashboardService::LABELS_FA;
    $up = \App\Domain\Plans\UpgradeInfo::for('reports.financial');
@endphp
<section class="band stack-sm home-sales" aria-labelledby="home-sales-title">
    <div class="between">
        <h2 id="home-sales-title">فروش امروز</h2>
        <a class="small" href="{{ route('dashboard') }}">گزارش کامل ‹</a>
    </div>
    @if ($sales['empty'])
        <p class="small muted">امروز هنوز فاکتوری صادر نشده است.</p>
    @else
        <p class="xs muted">{{ $sales['invoices_fa'] }} فاکتور صادرشده</p>
        <div class="dash-tiles">
            @foreach ($labels as $key => [$label, $hint])
                @if (isset($sales['metrics'][$key]))
                    @php $m = $sales['metrics'][$key]; @endphp
                    <div class="tile dash-tile m-{{ $key }}">
                        <span class="t-label">{{ $label }}</span>
                        <strong class="t-big"><span class="num">{{ $m['toman_fa'] }}</span> <span class="unit">تومان</span></strong>
                        @if (! empty($m['g_fa']) && $key !== 'sales')<span class="t-small num xs">{{ $m['g_fa'] }}</span>@endif
                    </div>
                @else
                    <button type="button" class="tile dash-tile is-locked" data-upgrade="reports.financial" data-upgrade-plans="{{ $up['plans'] }}" data-upgrade-price="{{ $up['price_fa'] }}">
                        <span class="t-label">{{ $label }}</span>
                        <span class="xs muted">در پلن پایه و حرفه‌ای</span>
                    </button>
                @endif
            @endforeach
        </div>
    @endif
</section>
