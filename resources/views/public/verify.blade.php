<x-layouts.public :scripts="false" title="بررسی اصالت فاکتور">
    @php($void = $v['status'] === 'void')
    <section class="hero stack-sm" aria-live="polite">
        @if ($void)
            <span class="badge err">باطل‌شده{{ $replaced ? ' و جایگزین‌شده' : '' }}</span>
            <h1 class="h2">این فاکتور در زرلیو ثبت شده بود اما در {{ $v['voided_fa'] }} باطل شده است.</h1>
            @if ($replaced)<p class="meta">فروشنده فاکتور جایگزین صادر کرده است. برای نسخه جدید از فروشنده بپرسید.</p>@endif
        @else
            <span class="badge ok">قطعی و معتبر</span>
            <h1 class="h2">این فاکتور با شماره <span class="num ltr">{{ $v['number'] }}</span> در زرلیو ثبت شده است.</h1>
        @endif
        <p class="meta">شماره، نام فروشگاه و مبلغ برگه کاغذی را با این صفحه مقایسه کنید.</p>
    </section>

    <section class="band stack-sm" aria-label="فروشگاه">
        <strong>{{ $v['shop']['name'] }}</strong>
        <span class="small">{{ $v['shop']['address'] }}</span>
        <span class="small">تلفن: <span class="num ltr">{{ $v['shop']['contact_primary'] }}</span></span>
        <dl class="kv">
            <div><dt>شماره فاکتور</dt><dd class="num ltr">{{ $v['number'] }}</dd></div>
            <div><dt>تاریخ صدور</dt><dd class="num">{{ $v['issued_fa'] }}</dd></div>
            <div><dt>{{ ($v['customer_credit'] ?? false) ? 'مانده به نفع مشتری' : 'مبلغ قابل پرداخت' }}</dt><dd class="num strong">{{ $v['payable_fa'] }} تومان</dd></div>
        </dl>
    </section>

    {{-- Same read model as the customer page: gold received, weight settlement and «مانده سند» shown as issued. --}}
    <h2 class="sr-only">اقلام</h2>
    @include('app.partials.invoice-summary')

    @unless ($void)
    <section class="band stack-sm" aria-label="خریدار">
        <h2 class="h3">خریدار</h2>
        @if (! $buyerOnFile)
            <p class="small muted">برای این فاکتور نام خریدار ثبت نشده است.</p>
        @elseif ($revealed)
            <dl class="kv">
                <div><dt>نام خریدار</dt><dd class="strong">{{ $buyerName }}</dd></div>
                <div><dt>موبایل</dt><dd class="num ltr">{{ $buyerMobileMasked }}</dd></div>
            </dl>
            <p class="xs muted">این اطلاعات پس از تأیید شماره موبایل خریدار نمایش داده شد.</p>
        @elseif ($gate['locked'])
            <p class="notice err small">به‌دلیل چند تلاش ناموفق، نمایش اطلاعات خریدار برای این فاکتور موقتاً بسته شد. بعداً دوباره امتحان کنید یا از فروشنده بپرسید.</p>
        @else
            <p class="small">برای دیدن نام خریدار، شماره موبایل خریدارِ ثبت‌شده روی فاکتور را وارد کنید.</p>
            <form class="stack-sm" method="post" action="{{ route('public.verify.reveal', request()->route('token')) }}" novalidate>
                @csrf
                <div class="field">
                    <label for="rv-mobile" class="sr-only">موبایل خریدار</label>
                    <div class="input-wrap ltr-input"><input id="rv-mobile" name="buyer_mobile" inputmode="tel" maxlength="14" placeholder="۰۹۱۲۳۴۵۶۷۸۹" autocomplete="off" required></div>
                    @if (! empty($gate['error']))<div class="err" role="alert">{{ $gate['error'] }}</div>@endif
                </div>
                <button class="btn btn-gold block" type="submit">نمایش اطلاعات خریدار</button>
                <p class="xs muted">هر فاکتور را فقط با {{ \App\Support\Digits::toPersian((string) config('talata.public.reveal_max_numbers')) }} شماره‌ی متفاوت می‌توان امتحان کرد (باقی‌مانده: {{ \App\Support\Digits::toPersian((string) $gate['remaining']) }}).</p>
            </form>
        @endif
    </section>
    @endunless

    <p class="xs muted">این بررسی فقط تطبیق برگه با رکورد ثبت‌شده در زرلیو است؛ تضمین تحویل کالا، عیار آزمایشگاهی، دریافت پول یا ثبت در سامانه مؤدیان نیست. اطلاعات خریدار فقط برای کسی که شماره‌ی خریدار را بداند نمایش داده می‌شود.</p>
</x-layouts.public>
