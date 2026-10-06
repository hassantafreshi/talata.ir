@props(['title' => null, 'page' => '', 'back' => null, 'badge' => null, 'scripts' => true])
<!doctype html>
<html lang="fa" dir="rtl">
<head>
@include('partials.head', ['cssOnly' => ! $scripts, 'referrer' => 'no-referrer'])
<meta name="robots" content="noindex, nofollow">
</head>
<body data-page="{{ $page ?? '' }}">
<main id="main" class="main">{{ $slot }}</main>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
