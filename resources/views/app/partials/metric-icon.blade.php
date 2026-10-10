{{-- Small round icon badge for a dashboard/home-sales metric. $key: sales|wage|profit|gold_in|vat --}}
@php [$tint, $path] = \App\Domain\Reports\DashboardService::ICONS_FA[$key]; @endphp
<span class="m-icon m-icon-{{ $tint }}" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $path !!}</svg></span>
