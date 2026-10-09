@props(['title' => null, 'page' => '', 'back' => null, 'badge' => null, 'scripts' => true, 'indexable' => false, 'og' => null])
<!doctype html>
<html lang="fa" dir="rtl">
<head>
@include('partials.head', ['cssOnly' => ! $scripts, 'referrer' => $indexable ? 'strict-origin-when-cross-origin' : 'no-referrer'])
@unless ($indexable)<meta name="robots" content="noindex, nofollow">@endunless
@if ($og)
<meta property="og:type" content="website">
<meta property="og:site_name" content="زرلیو">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="{{ $og['title'] }}">
<meta property="og:description" content="{{ $og['description'] }}">
<meta property="og:url" content="{{ $og['url'] }}">
<meta property="og:image" content="{{ $og['image'] }}">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="description" content="{{ $og['description'] }}">
@endif
</head>
<body data-page="{{ $page ?? '' }}">
<main id="main" class="main">{{ $slot }}</main>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
