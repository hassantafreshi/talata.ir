@php
    $blockLabels = ['shop_name' => 'نام فروشگاه', 'address' => 'نشانی', 'contact_primary' => 'تلفن اصلی', 'contact_mobile_extra' => 'موبایل (کنار تلفن ثابت)', 'website' => 'وب‌سایت', 'social' => 'شبکه‌های اجتماعی', 'license_union' => 'شماره پروانه کسب', 'license_online' => 'نماد اعتماد'];
    $colLabels = ['row_no' => 'ردیف', 'name' => 'شرح کالا', 'description' => 'توضیح', 'weight_g' => 'وزن', 'purity' => 'عیار', 'weight_750' => 'وزن ۷۵۰', 'unit_rate' => 'نرخ هر گرم', 'wage' => 'اجرت', 'profit' => 'سود', 'vat' => 'مالیات', 'amount' => 'مبلغ'];
    $templates = ['simple_readable' => ['ساده و خوانا', 'سیاه‌وسفید و کم‌جوهر'], 'shop' => ['فروشگاهی', 'جای لوگو و اطلاعات تماس'], 'ledger' => ['حساب طلا و ریال', 'بدهکار/بستانکار وزنی']];
    $accents = ['ink' => 'مشکی', 'gold_deep' => 'طلایی تیره', 'navy' => 'سرمه‌ای', 'emerald' => 'سبز زمردی', 'burgundy' => 'زرشکی', 'brown' => 'قهوه‌ای'];
    $boot = ['settings' => $settings, 'version' => $version, 'can' => $canCustomize, 'required' => \App\Domain\Invoices\LayoutSettings::REQUIRED_BLOCKS, 'blockLabels' => $blockLabels, 'colLabels' => $colLabels, 'canLogo' => $canLogo, 'hasLogo' => (bool) $profile?->logo_path,
        'templates' => collect($templates)->map(fn ($t) => $t[0]), 'accents' => $accents, 'accentHex' => \App\Domain\Invoices\LayoutSettings::ACCENTS, 'minContrast' => \App\Domain\Invoices\LayoutSettings::MIN_ACCENT_CONTRAST];
@endphp
<x-layouts.app title="ظاهر فاکتور" page="appearance" :back="route('settings')">
    <script type="application/json" id="boot">@json($boot)</script>
    @unless ($canCustomize)
        <div class="notice info">در پلن رایگان قالب ثابت «ساده و خوانا» استفاده می‌شود. ویرایش ظاهر در پلن پایه و حرفه‌ای است. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></div>
    @endunless

    {{-- Phones: one panel at a time. Desktop: settings and a large live preview side by side. --}}
    <div class="seg ap-switch" role="tablist" aria-label="نمایش">
        <label><input type="radio" name="ap-view" value="settings" checked>تنظیمات</label>
        <label><input type="radio" name="ap-view" value="preview">پیش‌نمایش</label>
    </div>

    <div class="ap-layout" data-ap data-view="settings">
        <form method="post" id="ap-form" class="ap-settings stack-sm" data-layout novalidate>
            <details class="ap-sec" open>
                <summary><span class="ap-title">قالب</span><span class="ap-val" data-val="template"></span></summary>
                <fieldset class="ap-body" @disabled(! $canCustomize)>
                    <legend class="sr-only">قالب</legend>
                    <div class="tpl-grid">
                        @foreach ($templates as $id => [$name, $hint])
                            <label class="tpl-card"><input type="radio" name="template_id" value="{{ $id }}">
                                <span class="tpl-thumb tpl-{{ $id }}" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                                <strong>{{ $name }}</strong><span class="xs muted">{{ $hint }}</span></label>
                        @endforeach
                    </div>
                    <p class="xs muted">با تغییر قالب، تنظیمات پیش‌فرض همان قالب بارگذاری می‌شود.</p>
                </fieldset>
            </details>

            <details class="ap-sec">
                <summary><span class="ap-title">رنگ</span><span class="ap-val" data-val="accent"></span></summary>
                <fieldset class="ap-body" @disabled(! $canCustomize)>
                    <legend class="sr-only">رنگ فاکتور</legend>
                    <div class="swatches" role="radiogroup" aria-label="رنگ نام فروشگاه و سرستون‌ها">
                        @foreach ($accents as $key => $label)
                            <label class="swatch"><input type="radio" name="accent" value="{{ $key }}"><span class="sw sw-{{ $key }}" aria-hidden="true"></span><span class="xs">{{ $label }}</span></label>
                        @endforeach
                        <label class="swatch"><input type="radio" name="accent" value="custom"><span class="sw sw-custom" aria-hidden="true"></span><span class="xs">رنگ دلخواه</span></label>
                    </div>
                    <div class="field ap-custom" data-custom-color hidden>
                        <label for="ap-hex">رنگ دلخواه</label>
                        <div class="cluster"><input id="ap-hex" type="color" name="accent_hex" value="#1f3a5f" class="color-input"><span class="small num ltr" data-hex-label></span></div>
                        <div class="err" data-hex-err></div>
                        <p class="hint">برای خوانایی روی کاغذ، رنگ‌های خیلی روشن پذیرفته نمی‌شوند.</p>
                    </div>
                    <p class="xs muted">رنگ روی نام فروشگاه، خط زیر سربرگ و زمینه سرستون‌های جدول می‌آید.</p>
                </fieldset>
            </details>

            <details class="ap-sec">
                <summary><span class="ap-title">اطلاعات سربرگ و پاورقی</span><span class="ap-val" data-val="blocks"></span></summary>
                <fieldset class="ap-body" @disabled(! $canCustomize)>
                    <legend class="sr-only">اطلاعات سربرگ و پاورقی</legend>
                    <div class="stack-sm" data-blocks></div>
                    <p class="xs muted">با ▲ و ▼ ترتیب را عوض کنید. نام فروشگاه، تلفن و نشانی همیشه روی فاکتور می‌آیند. بارکد بررسی اصالت همیشه بالا سمت چپ است.</p>
                </fieldset>
            </details>

            <details class="ap-sec">
                <summary><span class="ap-title">لوگو</span><span class="ap-val" data-val="logo"></span></summary>
                <fieldset class="ap-body" @disabled(! $canCustomize || ! $canLogo)>
                    <legend class="sr-only">لوگو</legend>
                    <label class="check"><input type="checkbox" name="logo_visible"> نمایش لوگو</label>
                    <div class="seg"><label><input type="radio" name="logo_size" value="small">کوچک</label><label><input type="radio" name="logo_size" value="medium">متوسط</label><label><input type="radio" name="logo_size" value="large">بزرگ</label></div>
                    @if (! $profile?->logo_path)<p class="xs muted">هنوز لوگویی بارگذاری نشده. <a href="{{ route('settings.business') }}">بارگذاری لوگو</a></p>@endif
                </fieldset>
            </details>

            <details class="ap-sec">
                <summary><span class="ap-title">ستون‌های جدول اقلام</span><span class="ap-val" data-val="cols"></span></summary>
                <fieldset class="ap-body" @disabled(! $canCustomize)>
                    <legend class="sr-only">ستون‌های جدول</legend>
                    <div class="chips" data-cols></div>
                    <p class="xs muted">«شرح کالا» و «مبلغ» همیشه هست. برای ردیف طلا، وزن و عیار همیشه چاپ می‌شود.</p>
                </fieldset>
            </details>

            <details class="ap-sec">
                <summary><span class="ap-title">جمع‌بندی و متن پایین</span><span class="ap-val" data-val="summary"></span></summary>
                <fieldset class="ap-body" @disabled(! $canCustomize)>
                    <legend class="sr-only">جمع‌بندی و متن</legend>
                    <label class="check"><input type="checkbox" name="show_component_breakdown"> نمایش ریز مبلغ (ارزش طلا، اجرت، سود، مالیات)</label>
                    <label class="check"><input type="checkbox" name="signature_box"> جای امضا</label>
                    <label class="check"><input type="checkbox" name="note_visible"> متن پایین فاکتور</label>
                    <div class="field"><label for="ap-note" class="sr-only">متن پایین فاکتور</label><div class="input-wrap"><textarea id="ap-note" name="note_text" rows="2" maxlength="300" placeholder="مثلاً: از خرید شما سپاسگزاریم."></textarea></div></div>
                </fieldset>
            </details>

            <details class="ap-sec">
                <summary><span class="ap-title">اندازه متن و چاپ</span><span class="ap-val" data-val="print"></span></summary>
                <fieldset class="ap-body" @disabled(! $canCustomize)>
                    <legend class="sr-only">اندازه متن و چاپ</legend>
                    <span class="small">اندازه متن</span>
                    <div class="seg"><label><input type="radio" name="text_size" value="normal">معمولی</label><label><input type="radio" name="text_size" value="large">درشت</label></div>
                    <span class="small">فاصله ردیف‌ها</span>
                    <div class="seg"><label><input type="radio" name="density" value="comfortable">جادار</label><label><input type="radio" name="density" value="compact">فشرده</label></div>
                    <span class="small">کاغذ A4</span>
                    <div class="seg" role="radiogroup" aria-label="جهت کاغذ"><label><input type="radio" name="orientation" value="landscape">خوابیده (پیش‌فرض)</label><label><input type="radio" name="orientation" value="portrait">ایستاده</label></div>
                    <div class="seg" role="radiogroup" aria-label="حاشیه کاغذ"><label><input type="radio" name="margins" value="normal">حاشیه معمولی</label><label><input type="radio" name="margins" value="narrow">حاشیه کم</label></div>
                    <p class="xs muted">اگر چاپگر لبه‌های کاغذ را نمی‌گیرد، «حاشیه معمولی» را نگه دارید.</p>
                </fieldset>
            </details>

            @if ($canCustomize)
                <div class="cluster ap-tools">
                    <button type="button" class="btn btn-line sm" data-undo disabled>برگرداندن آخرین تغییر</button>
                    <button type="button" class="btn btn-line sm" data-cancel disabled>لغو تغییرات</button>
                    <button type="button" class="btn btn-link sm" data-reset>پیش‌فرض قالب</button>
                </div>
            @endif
        </form>

        <section class="ap-preview stack-sm" aria-label="پیش‌نمایش">
            <div class="between ap-preview-bar">
                <div class="seg seg-sm" role="radiogroup" aria-label="نوع پیش‌نمایش">
                    <label><input type="radio" name="pv" value="print" checked>چاپ A4</label>
                    <label><input type="radio" name="pv" value="mobile">موبایل مشتری</label>
                </div>
                <span class="xs muted" data-zoom></span>
            </div>
            <div class="inv-frame print" data-preview aria-label="پیش‌نمایش فاکتور" aria-live="polite"><p class="small muted">در حال ساخت پیش‌نمایش…</p></div>
        </section>
    </div>

    @if ($canCustomize)
        <div class="sticky-bar ap-save">
            <button class="btn btn-gold block" type="submit" form="ap-form" data-busy-text="در حال ذخیره…">ذخیره ظاهر فاکتور</button>
            <p class="xs muted center" data-dirty-note>فقط فاکتورهای بعدی با ظاهر جدید صادر می‌شوند.</p>
        </div>
    @endif
</x-layouts.app>
