<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>فاکتور {{ $v['number'] }} · {{ $v['shop']['name'] }}</title>
@vite(['resources/js/print.js'])
</head>
<body class="print-page">
<div class="print-tools">
    <button type="button" data-print>چاپ یا ذخیره PDF</button>
    @if (! empty($back))<a href="{{ $back }}">بازگشت</a>@endif
</div>
@include('print.invoice-body')
</body>
</html>
