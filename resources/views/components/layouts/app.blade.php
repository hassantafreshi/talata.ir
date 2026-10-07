@props(['title' => null, 'page' => '', 'back' => null, 'badge' => null])
@php
    // Mobile bottom bar. «فاکتور جدید» is intentionally NOT here (it crowds the bar): on mobile it appears as a
    // button at the top of the invoices list instead. «خانه» takes the member to their landing screen.
    $nav = [
        ['home', 'خانه', '<path d="M3 11l9-8 9 8"/><path d="M6 10v10h12V10"/>', []],
        ['invoices.index', 'فاکتورها', '<path d="M6 3h9l5 5v13H6z"/><path d="M9 12h7M9 16h7"/>', ['invoices.index', 'invoices.show', 'invoices.new', 'invoices.items', 'invoices.review', 'invoices.issued']],
        ['mazneh', 'مظنه', '<path d="M3 17l5-6 4 4 5-7 4 5"/>', ['mazneh']],
        ['calculator', 'ماشین‌حساب', '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h3M13 12h3M8 16h3M13 16h3"/>', ['calculator']],
        ['settings', 'بیشتر', '<path d="M4 7h16M4 12h16M4 17h16"/>', ['settings*', 'customers*', 'dashboard']],
    ];
    $desk = [
        ['invoices.new', 'فاکتور جدید', ['invoices.new', 'invoices.items', 'invoices.review', 'invoices.issued']],
        ['invoices.index', 'فاکتورها', ['invoices.index', 'invoices.show']],
        ['dashboard', 'داشبورد', ['dashboard']],
        ['mazneh', 'مظنه', ['mazneh']],
        ['calculator', 'ماشین‌حساب', ['calculator']],
        ['customers.index', 'مشتریان و اقساط', ['customers*']],
        ['settings', 'تنظیمات', ['settings*']],
    ];
    // Team access: show only the screens this member may open (settings is always reachable).
    $ctx = app(\App\Tenancy\TenantContext::class);
    $member = $ctx->has() ? $ctx->membership() : null;
    $needs = ['invoices.new' => 'invoice.issue', 'invoices.index' => 'invoices.view', 'dashboard' => 'reports.view', 'mazneh' => 'mazneh.view', 'calculator' => 'calculator.use', 'customers.index' => 'customers.view'];
    $allowed = fn ($route) => ! isset($needs[$route]) || ($member && $member->can($needs[$route]));
    $nav = array_values(array_filter($nav, fn ($n) => $allowed($n[0])));
    $desk = array_values(array_filter($desk, fn ($n) => $allowed($n[0])));
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
    <a href="{{ route('home') }}" class="brand desktop-only">@include('partials.logo')زرلیو</a>
    <div class="grow">
        <h1 class="mobile-only">{{ $title ?? 'زرلیو' }}</h1>
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
@if (session()->pull('offer_passkey'))
    {{-- Shown once after an SMS login on a device with no passkey yet; JS reveals it only where the device supports fingerprint/face and the person has not dismissed it before. --}}
    <aside class="notice info passkey-offer hidden" data-passkey-offer hidden>
        <div class="stack-sm">
            <strong>ورود سریع‌تر با اثر انگشت؟</strong>
            <p class="small">دفعه بعد به‌جای کد پیامکی، با اثر انگشت یا چهره همین گوشی وارد شوید. اثر انگشت روی گوشی شما می‌ماند و به زرلیو فرستاده نمی‌شود.</p>
            <div class="cluster">
                <button type="button" class="btn btn-gold sm" data-passkey-offer-add data-busy-text="منتظر اثر انگشت…">فعال‌کردن</button>
                <button type="button" class="btn btn-link sm" data-passkey-offer-dismiss>الان نه</button>
            </div>
        </div>
    </aside>
@endif
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
<div data-install-prompt hidden></div>
</body>
</html>
