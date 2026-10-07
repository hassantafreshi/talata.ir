@props(['title' => null, 'page' => '', 'back' => null, 'badge' => null, 'scripts' => true, 'indexable' => false])
<!doctype html>
<html lang="fa" dir="rtl">
<head>
@include('partials.head', ['cssOnly' => ! $scripts, 'referrer' => $indexable ? 'strict-origin-when-cross-origin' : 'no-referrer'])
@unless ($indexable)<meta name="robots" content="noindex, nofollow">@endunless
</head>
<body data-page="{{ $page ?? '' }}">
<main id="main" class="main">{{ $slot }}</main>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
