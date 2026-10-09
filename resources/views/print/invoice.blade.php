<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>فاکتور {{ $v['number'] }} · {{ $v['shop']['name'] }}</title>
@vite(['resources/js/print.js'])
@php($P = $v['layout']['print'] ?? [])
{{-- Page size/margins for THIS invoice's snapshot. A plain @page (not CSS named pages, which made Chromium push
     the QR and table onto a second landscape page). --}}
<style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">@media print { @page { size: A4 {{ ($P['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait' }}; margin: {{ ($P['margins'] ?? 'normal') === 'narrow' ? '6mm' : '12mm 12mm 10mm' }}; } }</style>
</head>
<body class="print-page">
<div class="print-tools">
    <button type="button" data-print>چاپ یا ذخیره PDF</button>
    @if (! empty($back))<a href="{{ $back }}">بازگشت</a>@endif
</div>
@include('print.invoice-body')
</body>
</html>
