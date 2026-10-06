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
        @if ($membership->can('reports.view'))
            <a class="list-item" href="{{ route('dashboard') }}"><span class="body"><strong>داشبورد فروش</strong><span class="sub">فروش، اجرت، سود و طلای دریافتی با نمودار</span></span><span aria-hidden="true">‹</span></a>
        @endif
        @if ($membership->can('customers.view'))
            <a class="list-item" href="{{ route('customers.index') }}"><span class="body"><strong>مشتریان و اقساط</strong></span><span aria-hidden="true">‹</span></a>
        @endif
        @if ($affiliate)
            <a class="list-item" href="{{ route('affiliate') }}"><span class="body"><strong>همکاری در فروش</strong><span class="sub">کد شما: <span class="mono">{{ $affiliate->code }}</span></span></span><span class="badge {{ $affiliate->isActive() ? 'ok' : 'warn' }}">{{ $affiliate->isActive() ? 'فعال' : 'متوقف' }}</span><span aria-hidden="true">‹</span></a>
        @endif
        @if ($membership->can('settings.manage'))
            <a class="list-item" href="{{ route('settings.business') }}"><span class="body"><strong>اطلاعات کسب‌وکار</strong><span class="sub">{{ $profile?->isComplete() ? $profile->name : 'ناقص؛ پیش از اولین صدور کامل کنید' }}</span></span>@unless($profile?->isComplete())<span class="badge warn">ناقص</span>@endunless<span aria-hidden="true">‹</span></a>
            <a class="list-item" href="{{ route('settings.appearance') }}"><span class="body"><strong>ظاهر فاکتور</strong><span class="sub">قالب، لوگو، ستون‌ها و پیش‌نمایش چاپ</span></span><span aria-hidden="true">‹</span></a>
            <a class="list-item" href="{{ route('settings.numbering') }}"><span class="body"><strong>شماره‌گذاری فاکتور</strong><span class="sub">شکل شماره، شروع از عدد دلخواه، سالانه/ماهانه/پیوسته</span></span><span aria-hidden="true">‹</span></a>
            <a class="list-item" href="{{ route('settings.sms_template') }}"><span class="body"><strong>متن پیامک فاکتور</strong></span><span aria-hidden="true">‹</span></a>
        @endif
        @if ($isOwner)
            <a class="list-item" href="{{ route('settings.backups') }}"><span class="body"><strong>پشتیبان تنظیمات</strong><span class="sub">۵۰ تغییر آخر تنظیمات؛ بازگرداندن با یک لمس</span></span><span aria-hidden="true">‹</span></a>
            <a class="list-item" href="{{ route('settings.users') }}"><span class="body"><strong>کاربران و دسترسی‌ها</strong></span><span aria-hidden="true">‹</span></a>
        @endif
    </nav>

    <section class="band stack-sm" id="passkeys" aria-labelledby="pk-h" data-passkeys>
        <h2 id="pk-h">ورود با اثر انگشت یا چهره</h2>
        <p class="small muted">به‌جای کد پیامکی، با اثر انگشت، چهره یا قفل صفحه همین گوشی وارد شوید. اثر انگشت روی گوشی شما می‌ماند و به زرلیو فرستاده نمی‌شود؛ ورود با کد پیامکی هم همیشه فعال است.</p>
        <ul class="list" data-passkey-list>
            @foreach ($passkeys as $pk)
                <li class="list-item" data-passkey="{{ $pk->id }}"><span class="body"><strong>{{ $pk->name }}</strong><span class="sub">فعال‌شده {{ jdate($pk->created_at) }}@if($pk->last_used_at) · آخرین ورود {{ jdate($pk->last_used_at, true) }}@endif</span></span>
                    <button type="button" class="btn btn-link sm" data-passkey-remove="{{ $pk->id }}">حذف</button></li>
            @endforeach
        </ul>
        <button type="button" class="btn btn-dark block hidden" data-passkey-add data-busy-text="منتظر اثر انگشت…">{{ $passkeys->isEmpty() ? 'فعال‌کردن ورود با اثر انگشت روی این گوشی' : 'افزودن این دستگاه' }}</button>
        <p class="xs muted hidden" data-passkey-unsupported>این مرورگر یا دستگاه ورود با اثر انگشت را پشتیبانی نمی‌کند.</p>
    </section>

    <section class="band stack-sm" aria-labelledby="mch-h" data-mobile-change>
        <h2 id="mch-h">شماره ورود</h2>
        <p class="small">شماره‌ای که با آن وارد می‌شوید: <strong class="num ltr">{{ \App\Support\Mobile::display($user->mobile) }}</strong></p>
        <p class="xs muted">این شماره فقط برای ورود است و با شماره‌ی روی فاکتور (اطلاعات کسب‌وکار) فرق دارد. برای تغییر، اول شماره فعلی و سپس شماره جدید با کد پیامکی تأیید می‌شود و از همه‌ی دستگاه‌های دیگر خارج می‌شوید.</p>
        <button type="button" class="btn btn-line block" data-mobile-change-start>تغییر شماره ورود</button>
        <p class="xs muted">گوشی گم شده یا جای دیگری وارد شده‌اید؟ از همه دستگاه‌ها به‌جز همین یکی خارج شوید.</p>
        <button type="button" class="btn btn-line block" data-sign-out-others data-busy-text="…">خروج از همه دستگاه‌های دیگر</button>
    </section>

    <template data-mch-tpl>
        <h2 class="h3" data-mch-title>تغییر شماره ورود</h2>
        <ol class="steps xs" aria-hidden="true"><li data-step="old">۱. تأیید شماره فعلی</li><li data-step="new">۲. شماره جدید</li></ol>

        <form data-mch-form="old" class="stack-sm" novalidate hidden>
            <p class="small">کد پیامک‌شده به شماره فعلی (<span class="num ltr" data-mch-masked></span>) را وارد کنید.</p>
            <div class="field"><label for="mch-old-code">کد تأیید</label>
                <div class="input-wrap ltr-input"><input id="mch-old-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="num"></div><div class="err"></div></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="…">تأیید شماره فعلی</button>
            <button type="button" class="btn btn-link sm" data-mch-resend hidden>ارسال دوباره کد</button>
        </form>

        <form data-mch-form="ask-new" class="stack-sm" novalidate hidden>
            <div class="field"><label for="mch-new-mobile">شماره موبایل جدید</label>
                <div class="input-wrap ltr-input"><input id="mch-new-mobile" name="mobile" inputmode="numeric" autocomplete="off" maxlength="20" placeholder="۰۹…"></div><div class="err"></div></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="…">ارسال کد به شماره جدید</button>
        </form>

        <form data-mch-form="new" class="stack-sm" novalidate hidden>
            <p class="small">کد پیامک‌شده به شماره جدید (<span class="num ltr" data-mch-masked></span>) را وارد کنید.</p>
            <div class="field"><label for="mch-new-code">کد تأیید</label>
                <div class="input-wrap ltr-input"><input id="mch-new-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="num"></div><div class="err"></div></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="…">ثبت شماره جدید</button>
            <button type="button" class="btn btn-link sm" data-mch-resend hidden>ارسال دوباره کد</button>
        </form>

        <button type="button" class="btn btn-line block" data-close>انصراف</button>
    </template>

    @if ($invites->isNotEmpty())
        <section class="band stack-sm" aria-labelledby="inv-h">
            <h2 id="inv-h">دعوت به فروشگاه</h2>
            <p class="small muted">فقط دعوت فروشگاه‌هایی را بپذیرید که می‌شناسید. پس از پذیرش، هرچه در آن فروشگاه ثبت کنید متعلق به همان فروشگاه است.</p>
            <ul class="list">
                @foreach ($invites as $i)
                    <li class="list-item"><span class="body"><strong>{{ $i->tenant->profile?->name ?: 'فروشگاه بدون نام' }}</strong></span>
                        <button type="button" class="btn btn-gold sm" data-invite-accept="{{ $i->id }}">پذیرش</button>
                        <button type="button" class="btn btn-link sm" data-invite-decline="{{ $i->id }}">رد</button></li>
                @endforeach
            </ul>
        </section>
    @endif
    @if ($shops->count() > 1)
        <section class="band stack-sm" aria-labelledby="shops-h">
            <h2 id="shops-h">فروشگاه‌های شما</h2>
            <ul class="list">
                @foreach ($shops as $s)
                    <li class="list-item"><span class="body"><strong>{{ $s->tenant->profile?->name ?: 'فروشگاه بدون نام' }}</strong><span class="sub">{{ $s->isOwner() ? 'مالک' : 'همکار' }}</span></span>
                        @if ($s->id === $membership->id)<span class="badge ok">فعلی</span>@else<button type="button" class="btn btn-line sm" data-switch="{{ $s->id }}">ورود به این فروشگاه</button>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="band stack-sm">
        <p class="small">وارد شده با <span class="num ltr">{{ \App\Support\Mobile::display($user->mobile) }}</span></p>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-line block" type="submit">خروج از حساب</button></form>
    </section>
</x-layouts.app>
