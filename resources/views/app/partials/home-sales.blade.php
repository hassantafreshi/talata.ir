{{-- Today's sales on the home screen. Shown only to members with reports.view on a plan with dashboard.view; the
     plan decides which cards are open (DashboardService::access), locked ones open the upgrade sheet. --}}
@php
    $labels = \App\Domain\Reports\DashboardService::LABELS_FA;
    $up = \App\Domain\Plans\UpgradeInfo::for('reports.financial');
@endphp
<section class="band stack-sm home-sales" aria-labelledby="home-sales-title">
    <div class="between">
        <div class="between-start">
            <span class="home-sales-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V10M10 19V5M16 19v-7M21 5l-6 6-4-4-7 7"/></svg></span>
            <h2 id="home-sales-title">فروش امروز</h2>
        </div>
        <a class="small home-sales-link" href="{{ route('dashboard') }}">گزارش کامل<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" transform="rotate(180 12 12)"/></svg></a>
    </div>
    @if ($sales['empty'])
        <p class="small muted home-sales-empty">امروز هنوز فاکتوری صادر نشده است.</p>
    @else
        <p class="xs muted">{{ $sales['invoices_fa'] }} فاکتور صادرشده</p>
        <div class="dash-tiles">
            @foreach ($labels as $key => [$label, $hint])
                @if (isset($sales['metrics'][$key]))
                    @php $m = $sales['metrics'][$key]; @endphp
                    <div class="tile dash-tile m-{{ $key }}">
                        <div class="t-head">@include('app.partials.metric-icon', ['key' => $key])<span class="t-label">{{ $label }}</span></div>
                        <strong class="t-big"><span class="num">{{ $m['toman_fa'] }}</span> <span class="unit">تومان</span></strong>
                        @if (! empty($m['g_fa']) && $key !== 'sales')<span class="t-small num xs">{{ $m['g_fa'] }}</span>@endif
                    </div>
                @else
                    <button type="button" class="tile dash-tile is-locked" data-upgrade="reports.financial" data-upgrade-plans="{{ $up['plans'] }}" data-upgrade-price="{{ $up['price_fa'] }}">
                        <div class="t-head">@include('app.partials.metric-icon', ['key' => $key])<span class="t-label">{{ $label }}</span></div>
                        <span class="xs t-locked-note">@include('app.partials.premium-icon', ['size' => 13])با ارتقا باز می‌شود</span>
                    </button>
                @endif
            @endforeach
        </div>
    @endif
</section>
