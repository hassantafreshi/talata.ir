<x-layouts.public :scripts="false" :indexable="true" title="زرلیو · حساب طلا، ساده و دقیق">
    <section class="hero stack-sm center">
        <span class="site-mark">@include('partials.logo', ['size' => 56])</span>
        <h1 class="h2">زرلیو</h1>
        <p class="meta">حساب طلا، ساده و دقیق · فاکتور طلافروشی با گوشی، بدون نصب برنامه</p>
        <a class="btn btn-gold lg block" href="{{ route('login') }}">ورود / ثبت‌نام با شماره موبایل</a>
        <p class="xs">رمز و ایمیل لازم نیست؛ با کد پیامکی وارد می‌شوید.</p>
    </section>

    <section class="band stack-sm" aria-labelledby="f-h">
        <h2 id="f-h">زرلیو چه کار می‌کند؟</h2>
        <ul class="site-list">
            <li><strong>فاکتور فروش طلا در چند لمس</strong> — وزن، عیار، اجرت، سود و مالیات با نرخ روز؛ چند ردیف طلا و متفرقه در یک فاکتور.</li>
            <li><strong>بارکد بررسی اصالت</strong> روی هر فاکتور چاپی؛ مشتری با اسکن، درستی فاکتور را می‌بیند.</li>
            <li><strong>پیامک و لینک فاکتور</strong> برای مشتری، خودکار پس از صدور.</li>
            <li><strong>مظنه و ماشین‌حساب طلا</strong> همیشه در دسترس.</li>
            <li><strong>طلای دریافتی از مشتری</strong>، دفتر مشتریان، اقساط و داشبورد فروش.</li>
        </ul>
    </section>

    <section class="stack-sm" aria-labelledby="p-h">
        <h2 id="p-h">پلن‌ها</h2>
        <div class="site-plans">
            @foreach ($plans as $code => $p)
                <div class="band stack-sm">
                    <strong>{{ $p['label_fa'] }}</strong>
                    @if ((int) ($p['price_toman']['monthly'] ?? 0) > 0)
                        <span class="num">{{ toman((string) ((int) $p['price_toman']['monthly'] * 10)) }} تومان در ماه</span>
                    @endif
                    @foreach (array_slice($p['highlights_fa'] ?? [], 0, 3) as $h)<span class="xs muted">{{ $h }}</span>@endforeach
                </div>
            @endforeach
        </div>
        <p class="xs muted">قیمت‌ها بدون مالیات بر ارزش افزوده است؛ هنگام پرداخت {{ fa($vat) }}٪ مالیات جدا نمایش داده و اضافه می‌شود.</p>
    </section>

    @include('site.footer')
</x-layouts.public>
