<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#121417">
{{-- Public token pages pass no-referrer: a meta tag would otherwise override the stricter response header. --}}
<meta name="referrer" content="{{ $referrer ?? 'strict-origin-when-cross-origin' }}">
<title>{{ isset($title) ? $title.' · ' : '' }}زرلیو</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preload" href="/fonts/Vazirmatn-arabic-subset.woff2" as="font" type="font/woff2" crossorigin>
@if (! empty($cssOnly))
@vite(['resources/css/app.css'])
@else
@vite(['resources/js/app.js'])
{{-- The page's own module starts downloading with the page, not after app.js runs (one round trip less). --}}
@php($pageModule = ! empty($page) ? 'resources/js/pages/'.$page.'.js' : null)
@if ($pageModule && is_file(resource_path('js/pages/'.$page.'.js')))
@php($pageModuleUrl = rescue(fn () => \Illuminate\Support\Facades\Vite::asset($pageModule), null, false))
@if ($pageModuleUrl)<link rel="modulepreload" href="{{ $pageModuleUrl }}">@endif
@endif
@endif
