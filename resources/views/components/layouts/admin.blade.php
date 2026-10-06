@props(['title' => null, 'page' => 'admin', 'description' => null])
@php
    $staff = auth('staff')->user();
    $nav = $staff ? \App\Support\AdminNav::items($staff) : [];
    $sample = ! app()->environment('production');
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
@include('partials.head')
<meta name="robots" content="noindex, nofollow">
</head>
<body data-page="{{ $page }}" class="admin">
<a class="skip-link" href="#main">رفتن به محتوا</a>
<div class="admin-shell">
    @if ($staff)
        <aside class="admin-side" aria-label="منوی مدیریت">
            <a href="{{ route('admin.dashboard') }}" class="brand">@include('partials.logo')زرلیو</a>
            <p class="side-sub">مدیریت سرویس · نسخه ۱</p>
            <nav class="side-nav">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}" @if(request()->routeIs(...$item['match'])) aria-current="page" @endif>
                        <span>{{ $item['label'] }}</span>
                        @if ($item['count'] !== null)<span class="side-count {{ $item['alert'] ? 'alert' : '' }}">{{ fa($item['count']) }}</span>@endif
                    </a>
                @endforeach
            </nav>
            <div class="side-foot">
                <span><strong>{{ $staff->name }}</strong><span class="side-sub">{{ \App\Models\StaffUser::ROLES[$staff->role] ?? $staff->role }}</span></span>
                <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn sm btn-line-light" type="submit">خروج</button></form>
            </div>
        </aside>
    @endif
    <div class="admin-body">
        @include('partials.flash')
        <main id="main" class="main admin-main">
            @if ($title)
                <header class="page-head">
                    <div>
                        <h1>{{ $title }} @if($sample)<span class="badge warn">محیط آزمایشی · داده نمونه</span>@endif</h1>
                        @if ($description)<p class="small muted">{{ $description }}</p>@endif
                    </div>
                    @isset($actions)<div class="page-actions">{{ $actions }}</div>@endisset
                </header>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
