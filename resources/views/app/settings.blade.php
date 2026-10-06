@php
    $q = $summary['quotas'];
    $isOwner = $membership->isOwner();
    $meter = function ($key, $label) use ($q) {
        $x = $q[$key];
        return ['label' => $label, 'used' => $x['used'], 'limit' => $x['limit'], 'resets' => $x['resets_at_fa']];
    };
    $meters = [$meter('invoices_per_month', 'فاکتور این ماه'), $meter('new_customers_per_month', 'مشتری جدید این ماه'), $meter('links_per_month', 'لینک فاکتور این ماه')];
@endphp
<x-layouts.app title="تنظیمات" page="settings">
    <section class="band stack-sm" aria-labelledby="plan-h">
        <div class="between"><h2 id="plan-h">پلن {{ $summary['plan']['label_fa'] }}</h2>
            @if ($membership->can('billing.manage'))<a class="btn btn-gold sm" href="{{ route('settings.plan') }}">{{ $summary['plan']['code'] === 'professional' ? 'تمدید' : 'ارتقا' }}</a>@endif</div>
        @if ($summary['plan']['ends_at_fa'])<p class="small muted">اعتبار تا {{ $summary['plan']['ends_at_fa'] }}</p>@endif
        @foreach ($meters as $m)
            <div class="stack-sm">
                <div class="between small"><span>{{ $m['label'] }}</span><span class="num">{{ fa($m['used']) }} {{ $m['limit'] !== null ? 'از '.fa($m['limit']) : '(نامحدود)' }}</span></div>
                @if ($m['limit'] !== null)<progress class="meter" max="{{ max(1, $m['limit']) }}" value="{{ min($m['used'], $m['limit']) }}" aria-label="{{ $m['label'] }}"></progress>@endif
            </div>
        @endforeach
        <p class="xs muted">سهمیه‌ها از {{ $q['invoices_per_month']['resets_at_fa'] }} دوباره پر می‌شوند. چاپ فاکتورهای صادرشده، مظنه و ماشین‌حساب همیشه آزاد است.</p>
    </section>

    <section class="band stack-sm" aria-labelledby="sms-h">
        <div class="between"><h2 id="sms-h">اعتبار پیامک</h2><strong class="num">{{ $balanceFa }} تومان</strong></div>
        <p class="small muted">هر بخش پیامک {{ $perSegmentFa }} تومان · @if($summary['free_sms_per_year'])پیامک رایگان سالانه: {{ fa($summary['free_sms_remaining']) }} از {{ fa($summary['free_sms_per_year']) }}@endif</p>
        @if ($membership->can('billing.manage'))<a class="btn btn-dark block" href="{{ route('settings.sms') }}">خرید اعتبار پیامک</a>@endif
    </section>

    <nav class="list" aria-label="تنظیمات">
        <a class="list-item" href="{{ route('customers.index') }}"><span class="body"><strong>مشتریان و اقساط</strong></span><span aria-hidden="true">‹</span></a>
        @if ($membership->can('settings.manage'))
            <a class="list-item" href="{{ route('settings.business') }}"><span class="body"><strong>اطلاعات کسب‌وکار</strong><span class="sub">{{ $profile?->isComplete() ? $profile->name : 'ناقص؛ پیش از اولین صدور کامل کنید' }}</span></span>@unless($profile?->isComplete())<span class="badge warn">ناقص</span>@endunless<span aria-hidden="true">‹</span></a>
            <a class="list-item" href="{{ route('settings.appearance') }}"><span class="body"><strong>ظاهر فاکتور</strong><span class="sub">قالب، لوگو، ستون‌ها و پیش‌نمایش چاپ</span></span><span aria-hidden="true">‹</span></a>
            <a class="list-item" href="{{ route('settings.sms_template') }}"><span class="body"><strong>متن پیامک فاکتور</strong></span><span aria-hidden="true">‹</span></a>
        @endif
        @if ($isOwner)
            <a class="list-item" href="{{ route('settings.users') }}"><span class="body"><strong>کاربران و دسترسی‌ها</strong></span><span aria-hidden="true">‹</span></a>
        @endif
    </nav>

    <section class="band stack-sm">
        <p class="small">وارد شده با <span class="num ltr">{{ \App\Support\Mobile::display($user->mobile) }}</span></p>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-line block" type="submit">خروج از حساب</button></form>
    </section>
</x-layouts.app>
