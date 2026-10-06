@props(['title' => null, 'page' => '', 'back' => null, 'badge' => null])
<!doctype html>
<html lang="fa" dir="rtl">
<head>
@include('partials.head')
<meta name="robots" content="noindex, nofollow">
</head>
<body data-page="{{ $page ?? '' }}">
<main id="main" class="main">{{ $slot }}</main>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
