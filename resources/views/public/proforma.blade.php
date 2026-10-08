{{-- The customer's پیش‌فاکتور (docs/PROFORMA.md). Plain forms, no script: works on any phone and weak internet. --}}
@php
    $state = $v['state'];
    $closed = in_array($state, ['EXPIRED', 'CANCELLED'], true);
    $max = \App\Support\Digits::toPersian((string) config('talata.otp.length', 6));
@endphp
<x-layouts.public :scripts="false" :title="'پیش‌فاکتور '.$v['shop']['name']" :og="$og ?? null">
    {{-- 1. What this is and what to do: status, amount, deadline. --}}
    @if ($state === 'SENT')
        <section class="hero pf-hero stack-sm" aria-live="polite">
            <span class="badge warn pf-pulse">منتظر تأیید شما</span>
            <h1 class="h2">پیش‌فاکتور خرید از {{ $v['shop']['name'] }}</h1>
            <div><span class="price">{{ $v['payable_fa'] }}</span> <span class="unit">تومان</span></div>
            <p class="pf-deadline"><strong>مهلت تأیید: {{ $v['until_fa'] }}</strong><br><span class="xs">({{ $v['hours_fa'] }} از زمان ارسال؛ پس از آن، این پیش‌فاکتور ابطال می‌شود)</span></p>
            <a class="btn btn-gold block lg" href="#confirm">بررسی و تأیید خرید</a>
        </section>
    @elseif ($state === 'CONFIRMED')
        <section class="hero pf-hero pf-ok stack-sm" aria-live="polite">
            <span class="badge ok">تأیید شد</span>
            <h1 class="h2">خرید شما تأیید شد ✓</h1>
            <p class="meta">پیش‌فاکتور شماره <span class="num ltr">{{ $v['number'] }}</span> در {{ $v['confirmed_fa'] }} با شماره <bdi class="num" dir="ltr">{{ \App\Support\Digits::toPersian($mobileMasked) }}</bdi> تأیید شد.</p>
            @if ($invoiceNumber)
                <p><strong>فاکتور فروش شماره <span class="num ltr">{{ \App\Support\Digits::invoiceNumber($invoiceNumber) }}</span> صادر شد.</strong></p>
                @if ($verifyUrl)<a class="btn btn-gold block" href="{{ $verifyUrl }}" rel="noopener">دیدن و بررسی فاکتور فروش</a>@endif
            @else
                <p class="meta">فروشنده فاکتور فروش را صادر می‌کند. برای دریافت آن با فروشگاه تماس بگیرید.</p>
            @endif
        </section>
    @else
        <section class="hero pf-hero pf-void stack-sm" aria-live="polite">
            <span class="badge err">ابطال شده</span>
            <h1 class="h2">این پیش‌فاکتور ابطال شده و فاقد اعتبار است</h1>
            <p class="meta">
                @if ($state === 'EXPIRED')
                    مهلت تأیید ({{ $v['until_fa'] }}) تمام شده است.
                @else
                    فروشنده این پیش‌فاکتور را ابطال کرده است.
                @endif
                برای خرید، پیش‌فاکتور تازه را از فروشنده بخواهید.
            </p>
            <a class="btn btn-line block" href="tel:{{ \App\Support\Digits::toLatin($v['shop']['contact_primary']) }}">تماس با فروشگاه</a>
        </section>
    @endif

    {{-- 2. The document. Closed ones carry a large «ابطال شده» stamp over everything. --}}
    <div class="pf-doc {{ $closed ? 'is-void' : '' }}">
        @if ($closed)<div class="pf-stamp" aria-hidden="true">ابطال شده</div>@endif
        <section class="band stack-sm" aria-label="فروشگاه و مشخصات">
            <div class="between"><strong>{{ $v['shop']['name'] }}</strong><span class="badge {{ $state === 'CONFIRMED' ? 'ok' : ($closed ? 'err' : 'warn') }}">پیش‌فاکتور</span></div>
            <span class="small">{{ $v['shop']['address'] }}</span>
            <span class="small">تلفن: <a class="num ltr" href="tel:{{ \App\Support\Digits::toLatin($v['shop']['contact_primary']) }}">{{ $v['shop']['contact_primary'] }}</a></span>
            <dl class="kv">
                <div><dt>شماره پیش‌فاکتور</dt><dd class="num ltr">{{ $v['number'] }}</dd></div>
                <div><dt>تاریخ</dt><dd class="num">{{ $v['issued_fa'] }}</dd></div>
                <div><dt>مدت اعتبار</dt><dd>{{ $v['hours_fa'] }} ({{ $v['until_fa'] }})</dd></div>
                @if ($v['buyer_name'])<div><dt>خریدار</dt><dd>{{ $v['buyer_name'] }}</dd></div>@endif
                @if ($v['rate_fa'])<div><dt>نرخ هر گرم طلای ۱۸ عیار</dt><dd class="num">{{ $v['rate_fa'] }} تومان</dd></div>@endif
            </dl>
        </section>
        <h2 class="sr-only">اقلام</h2>
        @include('app.partials.invoice-summary')
    </div>

    {{-- 3. Confirmation: mobile → SMS code. --}}
    @if ($state === 'SENT')
        <section class="band pf-confirm stack-sm" id="confirm" aria-labelledby="confirm-h">
            <h2 id="confirm-h">تأیید پیش‌فاکتور</h2>
            <ol class="pf-steps" aria-label="مراحل">
                <li class="done">بررسی اقلام و مبلغ</li>
                <li class="{{ in_array($step, ['code'], true) ? 'done' : 'now' }}">شماره موبایل</li>
                <li class="{{ $step === 'code' ? 'now' : '' }}">کد پیامک</li>
            </ol>
            <p class="small">برای نهایی کردن خرید، این پیش‌فاکتور را تأیید کنید. با تأیید شما، فاکتور فروش با همین اقلام و همین مبلغ (<strong class="num">{{ $v['payable_fa'] }}</strong> تومان) صادر می‌شود.</p>

            @if ($error)<div class="notice err" role="alert">{{ $error }}</div>@endif

            @if ($step === 'code')
                <form class="stack-sm" method="post" action="{{ route('public.proforma.confirm', $token) }}#confirm" novalidate>
                    @csrf
                    <input type="hidden" name="challenge" value="{{ $challenge }}">
                    <div class="field">
                        <label for="pf-code">کد {{ $max }} رقمی که به <bdi class="num" dir="ltr">{{ \App\Support\Digits::toPersian($mobileMasked) }}</bdi> پیامک شد</label>
                        <div class="input-wrap ltr-input"><input id="pf-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="------" required autofocus></div>
                    </div>
                    <button class="btn btn-gold block lg" type="submit">تأیید پیش‌فاکتور و نهایی کردن خرید</button>
                </form>
                <form method="post" action="{{ route('public.proforma.code', $token) }}#confirm" class="center">
                    @csrf
                    <input type="hidden" name="mobile" value="{{ $mobileTyped ?? '' }}">
                    <button class="btn btn-link sm" type="submit">کد نرسید؟ ارسال دوباره</button>
                </form>
            @else
                <form class="stack-sm" method="post" action="{{ route('public.proforma.code', $token) }}#confirm" novalidate>
                    @csrf
                    <div class="field">
                        <label for="pf-mobile">شماره موبایل شما</label>
                        <div class="input-wrap ltr-input"><input id="pf-mobile" name="mobile" inputmode="tel" autocomplete="tel" maxlength="14" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="{{ $mobileTyped ?? '' }}" required></div>
                        <p class="hint">همان شماره‌ای که پیامک پیش‌فاکتور را گرفت (<bdi class="num" dir="ltr">{{ \App\Support\Digits::toPersian($mobileMasked) }}</bdi>). یک کد تأیید برایتان پیامک می‌شود.</p>
                    </div>
                    <button class="btn btn-gold block lg" type="submit">دریافت کد تأیید</button>
                </form>
            @endif
            <p class="xs muted">اگر این خرید را انجام نداده‌اید، کاری نکنید؛ پیش‌فاکتور پس از پایان مهلت خودکار ابطال می‌شود.</p>
        </section>
    @endif

    <p class="xs muted">پیش‌فاکتور، فاکتور فروش نیست؛ پس از تأیید خریدار، فاکتور فروش صادر می‌شود. قیمت‌ها تا پایان مدت اعتبار همین است که می‌بینید.</p>
    @if ($v['show_talata_mark'])<p class="xs muted center">صادرشده با زرلیو</p>@endif
</x-layouts.public>
