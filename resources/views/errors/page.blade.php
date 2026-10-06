@php
    $status = $status ?? 500;
    $titles = [403 => 'دسترسی ندارید', 404 => 'پیدا نشد', 419 => 'نشست منقضی شد', 429 => 'درخواست‌ها زیاد شد', 500 => 'خطای سرور', 503 => 'موقتاً در دسترس نیست'];
    $defaults = [
        403 => 'دسترسی این صفحه را ندارید. از مالک فروشگاه بخواهید.',
        404 => 'صفحه‌ای که دنبالش هستید پیدا نشد.',
        419 => 'برای امنیت، نشست شما منقضی شد. صفحه را دوباره باز کنید.',
        429 => 'تعداد درخواست‌ها زیاد شد. کمی صبر کنید و دوباره امتحان کنید.',
        500 => 'مشکلی پیش آمد. دوباره امتحان کنید؛ اطلاعات شما حفظ شده است.',
        503 => 'زرلیو در حال به‌روزرسانی است. چند دقیقه دیگر دوباره امتحان کنید.',
    ];
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $titles[$status] ?? 'خطا' }} · زرلیو</title>
@if (file_exists(public_path('build/manifest.json')))@vite(['resources/css/app.css'])@endif
</head>
<body>
<main class="main stack">
    <section class="hero stack-sm">
        <span class="badge {{ $status >= 500 ? 'err' : 'warn' }}">{{ fa($status) }}</span>
        <h2>{{ $titles[$status] ?? 'خطا' }}</h2>
        <p class="meta">{{ $message ?? ($defaults[$status] ?? $defaults[500]) }}</p>
    </section>
    <a class="btn btn-gold block" href="{{ url('/') }}">بازگشت به صفحه اصلی</a>
</main>
</body>
</html>
