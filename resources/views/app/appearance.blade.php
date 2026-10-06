@php
    $blockLabels = ['shop_name' => 'نام فروشگاه', 'address' => 'نشانی', 'contact_primary' => 'تلفن اصلی', 'contact_mobile_extra' => 'موبایل (کنار تلفن ثابت)', 'website' => 'وب‌سایت', 'social' => 'شبکه‌های اجتماعی', 'license_union' => 'شماره پروانه کسب', 'license_online' => 'نماد اعتماد'];
    $colLabels = ['row_no' => 'ردیف', 'name' => 'شرح کالا', 'description' => 'توضیح', 'weight_g' => 'وزن', 'purity' => 'عیار', 'weight_750' => 'وزن ۷۵۰', 'unit_rate' => 'نرخ هر گرم', 'wage' => 'اجرت', 'profit' => 'سود', 'vat' => 'مالیات', 'amount' => 'مبلغ'];
    $boot = ['settings' => $settings, 'version' => $version, 'can' => $canCustomize, 'required' => \App\Domain\Invoices\LayoutSettings::REQUIRED_BLOCKS, 'blockLabels' => $blockLabels, 'colLabels' => $colLabels, 'canLogo' => $canLogo, 'hasLogo' => (bool) $profile?->logo_path];
@endphp
<x-layouts.app title="ظاهر فاکتور" page="appearance" :back="route('settings')">
    <script type="application/json" id="boot">@json($boot)</script>
    @unless ($canCustomize)
        <div class="notice info">در پلن رایگان قالب ثابت «ساده و خوانا» استفاده می‌شود. ویرایش ظاهر در پلن پایه و حرفه‌ای است. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></div>
    @endunless
    <div class="desk-2">
        <form class="stack" data-layout novalidate>
            <fieldset class="band stack-sm" @disabled(! $canCustomize)>
                <legend class="label">قالب</legend>
                <div class="seg" role="radiogroup">
                    <label><input type="radio" name="template_id" value="simple_readable">ساده و خوانا</label>
                    <label><input type="radio" name="template_id" value="shop">فروشگاهی</label>
                    <label><input type="radio" name="template_id" value="ledger">حساب طلا و ریال (بد/بس)</label>
                </div>
                <p class="xs muted">با تغییر قالب، تنظیمات پیش‌فرض همان قالب بارگذاری می‌شود.</p>
            </fieldset>
            <fieldset class="band stack-sm" @disabled(! $canCustomize)>
                <legend class="label">اطلاعات سربرگ و پاورقی</legend>
                <div class="stack-sm" data-blocks></div>
                <p class="xs muted">نام فروشگاه، تلفن و نشانی همیشه روی فاکتور می‌آیند. بارکد بررسی اصالت همیشه بالا سمت چپ است.</p>
            </fieldset>
            <fieldset class="band stack-sm" @disabled(! $canCustomize || ! $canLogo)>
                <legend class="label">لوگو</legend>
                <label class="check"><input type="checkbox" name="logo_visible"> نمایش لوگو</label>
                <div class="seg"><label><input type="radio" name="logo_size" value="small">کوچک</label><label><input type="radio" name="logo_size" value="medium">متوسط</label><label><input type="radio" name="logo_size" value="large">بزرگ</label></div>
                @if (! $profile?->logo_path)<p class="xs muted">هنوز لوگویی بارگذاری نشده. <a href="{{ route('settings.business') }}">بارگذاری لوگو</a></p>@endif
            </fieldset>
            <fieldset class="band stack-sm" @disabled(! $canCustomize)>
                <legend class="label">ستون‌های جدول اقلام</legend>
                <div class="chips" data-cols></div>
                <p class="xs muted">«شرح کالا» و «مبلغ» همیشه هست. برای ردیف طلا، وزن و عیار همیشه چاپ می‌شود.</p>
            </fieldset>
            <fieldset class="band stack-sm" @disabled(! $canCustomize)>
                <legend class="label">جمع‌بندی و متن</legend>
                <label class="check"><input type="checkbox" name="show_component_breakdown"> نمایش ریز مبلغ (ارزش طلا، اجرت، سود، مالیات)</label>
                <label class="check"><input type="checkbox" name="signature_box"> جای امضا</label>
                <label class="check"><input type="checkbox" name="note_visible"> متن پایین فاکتور</label>
                <div class="field"><label for="ap-note" class="sr-only">متن پایین فاکتور</label><div class="input-wrap"><textarea id="ap-note" name="note_text" rows="2" maxlength="300" placeholder="مثلاً: از خرید شما سپاسگزاریم."></textarea></div></div>
            </fieldset>
            <fieldset class="band stack-sm" @disabled(! $canCustomize)>
                <legend class="label">خوانایی</legend>
                <div class="seg"><label><input type="radio" name="text_size" value="normal">متن معمولی</label><label><input type="radio" name="text_size" value="large">متن درشت</label></div>
                <div class="seg"><label><input type="radio" name="density" value="comfortable">جادار</label><label><input type="radio" name="density" value="compact">فشرده</label></div>
                <div class="seg"><label><input type="radio" name="accent" value="ink">سیاه</label><label><input type="radio" name="accent" value="gold_deep">طلایی تیره</label></div>
            </fieldset>
            @if ($canCustomize)
                <div class="sticky-bar"><button class="btn btn-gold block" type="submit" data-busy-text="در حال ذخیره…">ذخیره ظاهر فاکتور</button>
                    <p class="xs muted center">فقط فاکتورهای بعدی با ظاهر جدید صادر می‌شوند.</p></div>
            @endif
        </form>
        <section class="stack-sm desk-side" aria-label="پیش‌نمایش">
            <div class="seg" role="radiogroup" aria-label="نوع پیش‌نمایش">
                <label><input type="radio" name="pv" value="print" checked>چاپ A4</label>
                <label><input type="radio" name="pv" value="mobile">موبایل</label>
            </div>
            <div class="inv-frame print" data-preview aria-live="polite"><p class="small muted">در حال ساخت پیش‌نمایش…</p></div>
        </section>
    </div>
</x-layouts.app>
