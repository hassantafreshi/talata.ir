<x-layouts.public title="بررسی اصالت فاکتور">
    @php($void = $v['status'] === 'void')
    <section class="hero stack-sm" aria-live="polite">
        @if ($void)
            <span class="badge err">باطل‌شده{{ $replaced ? ' و جایگزین‌شده' : '' }}</span>
            <h2>این فاکتور در زرلیو ثبت شده بود اما در {{ $v['voided_fa'] }} باطل شده است.</h2>
            @if ($replaced)<p class="meta">فروشنده فاکتور جایگزین صادر کرده است. برای نسخه جدید از فروشنده بپرسید.</p>@endif
        @else
            <span class="badge ok">قطعی و معتبر</span>
            <h2>این فاکتور با شماره <span class="num ltr">{{ $v['number'] }}</span> در زرلیو ثبت شده است.</h2>
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

    <section class="band stack-sm" aria-label="اقلام">
        <h2>اقلام</h2>
        <ul class="list">
            @foreach ($v['rows'] as $r)
                <li class="list-item"><span class="body"><strong>{{ $r['name'] }}</strong>
                    <span class="sub">@if($r['type'] === 'GOLD'){{ $r['weight'] }} گرم · {{ $r['purity'] }}@else متفرقه@endif @if($r['description']) · {{ $r['description'] }}@endif</span></span>
                    <span class="num nowrap">{{ $r['amount'] }}</span></li>
            @endforeach
        </ul>
        @if ($v['has_gold'])
            <dl class="kv small">
                <div><dt>ارزش طلا</dt><dd class="num">{{ $v['metal_fa'] }}</dd></div>
                <div><dt>اجرت</dt><dd class="num">{{ $v['wage_fa'] }}</dd></div>
                <div><dt>سود</dt><dd class="num">{{ $v['profit_fa'] }}</dd></div>
                <div><dt>مالیات</dt><dd class="num">{{ $v['vat_fa'] }}</dd></div>
            </dl>
        @endif
    </section>

    <p class="xs muted">این بررسی فقط تطبیق برگه با رکورد ثبت‌شده در زرلیو است؛ تضمین تحویل کالا، عیار آزمایشگاهی، دریافت پول یا ثبت در سامانه مؤدیان نیست. اطلاعات خریدار برای حفظ حریم خصوصی نمایش داده نمی‌شود.</p>
</x-layouts.public>
