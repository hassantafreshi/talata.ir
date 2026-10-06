<div class="seg" role="tablist" aria-label="سرویس">
    <a href="{{ request()->fullUrlWithQuery(['service' => null, 'page' => null]) }}" @if(empty($filters['service'])) aria-current="page" @endif>همه</a>
    @foreach ($services as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['service' => $key, 'page' => null]) }}" @if(($filters['service'] ?? '') === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</div>
<form class="filters band" method="get">
    @if (! empty($filters['service']))<input type="hidden" name="service" value="{{ $filters['service'] }}">@endif
    <label class="small">رویداد<input name="event" value="{{ $filters['event'] ?? '' }}" placeholder="invoice. یا auth.login" dir="ltr"></label>
    @isset($withMobile)<label class="small">موبایل کاربر<input name="mobile" value="{{ $filters['mobile'] ?? '' }}" inputmode="tel" dir="ltr"></label>@endisset
    <label class="small">انجام‌دهنده<select name="actor"><option value="">همه</option>@foreach (['user' => 'کاربر', 'staff' => 'مدیر', 'system' => 'سیستم'] as $k => $l)<option value="{{ $k }}" @selected(($filters['actor'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
    <label class="small">از تاریخ<input name="from" value="{{ $filters['from'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۰۱" dir="ltr"></label>
    <label class="small">تا تاریخ<input name="to" value="{{ $filters['to'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۳۰" dir="ltr"></label>
    <label class="small">شناسه درخواست<input name="request" value="{{ $filters['request'] ?? '' }}" dir="ltr"></label>
    <button class="btn btn-dark" type="submit">اعمال</button>
</form>
