@props(['title' => null, 'page' => '', 'back' => null, 'badge' => null])
<!doctype html>
<html lang="fa" dir="rtl">
<head>@include('partials.head')</head>
<body data-page="{{ $page ?? '' }}">
<header class="topbar"><span class="brand">@include('partials.logo')زرلیو</span><span class="sub grow">فاکتور طلا؛ ساده، سریع، قابل بررسی</span></header>
@include('partials.flash')
<main id="main" class="main">{{ $slot }}</main>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
