<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#121417">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>{{ isset($title) ? $title.' · ' : '' }}زرلیو</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preload" href="/fonts/Vazirmatn-arabic-subset.woff2" as="font" type="font/woff2" crossorigin>
@vite(['resources/js/app.js'])
