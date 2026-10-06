@props(['title' => null, 'page' => '', 'back' => null, 'badge' => null])
@php
    $nav = [
        ['invoices.new', 'فاکتور جدید', '<path d="M12 5v14M5 12h14"/>', ['invoices.new', 'invoices.items', 'invoices.review', 'invoices.issued']],
        ['invoices.index', 'فاکتورها', '<path d="M6 3h9l5 5v13H6z"/><path d="M9 12h7M9 16h7"/>', ['invoices.index', 'invoices.show']],
        ['mazneh', 'مظنه', '<path d="M3 17l5-6 4 4 5-7 4 5"/>', ['mazneh']],
        ['calculator', 'ماشین‌حساب', '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h3M13 12h3M8 16h3M13 16h3"/>', ['calculator']],
        ['settings', 'بیشتر', '<path d="M4 7h16M4 12h16M4 17h16"/>', ['settings*', 'customers*']],
    ];
    $desk = [
        ['invoices.new', 'فاکتور جدید', ['invoices.new', 'invoices.items', 'invoices.review', 'invoices.issued']],
        ['invoices.index', 'فاکتورها', ['invoices.index', 'invoices.show']],
        ['mazneh', 'مظنه', ['mazneh']],
        ['calculator', 'ماشین‌حساب', ['calculator']],
        ['customers.index', 'مشتریان و اقساط', ['customers*']],
        ['settings', 'تنظیمات', ['settings*']],
    ];
    $shopName = app(\App\Tenancy\TenantContext::class)->has() ? (app(\App\Tenancy\TenantContext::class)->tenant()->profile?->name ?: 'فروشگاه من') : '';
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>@include('partials.head')</head>
<body data-page="{{ $page ?? '' }}">
<a class="skip-link" href="#main">رفتن به محتوا</a>
<header class="topbar">
    @if($back)
        <a href="{{ $back }}" class="icon-btn mobile-only" aria-label="بازگشت"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a>
    @endif
    <a href="{{ route('invoices.new') }}" class="brand desktop-only">@include('partials.logo')طلاتا</a>
    <div class="grow">
        <h1 class="mobile-only">{{ $title ?? 'طلاتا' }}</h1>
        <div class="sub desktop-only">{{ $shopName }}</div>
    </div>
    @if($badge)<span class="badge dark mobile-only">{{ $badge }}</span>@endif
    <nav class="topnav" aria-label="منوی اصلی">
        @foreach ($desk as [$route, $label, $patterns])
            <a href="{{ route($route) }}" @if(request()->routeIs(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</header>
<div class="notice off offline-banner" data-offline-banner hidden role="status">اینترنت قطع است. محاسبه و پیش‌نویس کار می‌کند؛ صدور، پیامک و پرداخت پس از اتصال.</div>
@include('partials.flash')
<main id="main" class="main">
    <h1 class="desktop-only">{{ $title ?? '' }}</h1>
    {{ $slot }}
</main>
<nav class="tabs" aria-label="منوی اصلی">
    @foreach ($nav as [$route, $label, $icon, $patterns])
        <a href="{{ route($route) }}" @if(request()->routeIs(...$patterns)) aria-current="page" @endif>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>{{ $label }}
        </a>
    @endforeach
</nav>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
