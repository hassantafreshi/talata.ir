@props(['title' => null, 'page' => 'admin'])
@php
    $staff = auth('staff')->user();
    $nav = [['admin.dashboard', 'داشبورد'], ['admin.activity', 'لاگ فعالیت'], ['admin.tenants', 'فروشگاه‌ها']];
    if ($staff?->isAdmin()) { $nav[] = ['admin.tech', 'لاگ فنی']; }
    $nav[] = ['admin.account', 'حساب من'];
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
@include('partials.head')
<meta name="robots" content="noindex, nofollow">
</head>
<body data-page="{{ $page }}" class="admin">
<header class="topbar">
    <a href="{{ route('admin.dashboard') }}" class="brand">@include('partials.logo')طلاتا <span class="badge warn">مدیریت سامانه</span></a>
    <div class="grow"></div>
    @if ($staff)
        <nav class="topnav admin-nav" aria-label="منوی مدیریت">
            @foreach ($nav as [$route, $label])
                <a href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn sm btn-line" type="submit">خروج {{ $staff->name }}</button></form>
    @endif
</header>
@include('partials.flash')
<main id="main" class="main admin-main">
    @if ($title)<h1>{{ $title }}</h1>@endif
    {{ $slot }}
</main>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
