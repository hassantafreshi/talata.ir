@php
    $socials = collect($profile->socials ?? [])->keyBy('network');
    $boot = ['return' => $return, 'logo_url' => $profile->logo_path ? route('public.logo', [app(\App\Tenancy\TenantContext::class)->tenant()->public_id, $profile->logo_version]) : null];
@endphp
<x-layouts.app title="اطلاعات کسب‌وکار" page="business" :back="route('settings')">
    <script type="application/json" id="boot">@json($boot)</script>
    @if ($welcome || $return)
        <div class="notice info">پیش از اولین صدور، نام فروشگاه، موبایل کسب‌وکار و نشانی لازم است. این اطلاعات روی فاکتور و صفحه بررسی اصالت می‌آید.</div>
    @endif
    <form class="stack" data-business novalidate>
        <section class="band stack-sm">
            <h2>الزامی</h2>
            <div class="field"><label for="b-name">نام فروشگاه</label><div class="input-wrap"><input id="b-name" name="name" maxlength="60" value="{{ $profile->name }}" required></div><div class="err"></div></div>
            <div class="field"><label for="b-mobile">موبایل کسب‌وکار</label><div class="input-wrap ltr-input"><input id="b-mobile" name="business_mobile" inputmode="tel" maxlength="14" value="{{ $profile->business_mobile ? \App\Support\Mobile::display($profile->business_mobile) : '' }}" data-digits required></div><div class="err"></div>
                <p class="hint">اگر تلفن ثابت ندارید، همین شماره روی فاکتور می‌آید. با شماره ورود شما می‌تواند فرق کند.</p></div>
            <div class="field"><label for="b-address">نشانی</label><div class="input-wrap"><textarea id="b-address" name="address" rows="2" maxlength="250" required>{{ $profile->address }}</textarea></div><div class="err"></div></div>
        </section>
        <section class="band stack-sm">
            <h2>اختیاری</h2>
            <div class="field"><label for="b-landline">تلفن ثابت</label><div class="input-wrap ltr-input"><input id="b-landline" name="landline" inputmode="tel" maxlength="16" value="{{ $profile->landline }}" placeholder="۰۲۱-۱۲۳۴۵۶۷۸" data-digits></div><div class="err"></div></div>
            <div class="field"><label for="b-web">وب‌سایت</label><div class="input-wrap ltr-input"><input id="b-web" name="website" maxlength="120" value="{{ $profile->website }}" inputmode="url"></div><div class="err"></div></div>
            <div class="grid-2">
                <div class="field"><label for="b-ig">اینستاگرام</label><div class="input-wrap ltr-input"><input id="b-ig" data-social="instagram" maxlength="60" value="{{ $socials['instagram']['handle'] ?? '' }}" placeholder="@shop"></div></div>
                <div class="field"><label for="b-tg">تلگرام</label><div class="input-wrap ltr-input"><input id="b-tg" data-social="telegram" maxlength="60" value="{{ $socials['telegram']['handle'] ?? '' }}" placeholder="@shop"></div></div>
            </div>
            <div class="grid-2">
                <div class="field"><label for="b-lu">شماره پروانه کسب</label><div class="input-wrap"><input id="b-lu" name="license_union" maxlength="40" value="{{ $profile->license_union }}"></div></div>
                <div class="field"><label for="b-lo">نماد اعتماد</label><div class="input-wrap"><input id="b-lo" name="license_online" maxlength="40" value="{{ $profile->license_online }}"></div></div>
            </div>
        </section>
        <button class="btn btn-gold block" type="submit" data-busy-text="در حال ذخیره…">{{ $return ? 'ذخیره و بازگشت به صدور' : 'ذخیره' }}</button>
    </form>

    <section class="band stack-sm" aria-labelledby="logo-h">
        <h2 id="logo-h">لوگو</h2>
        @if ($canLogo)
            <img class="logo-preview {{ $profile->logo_path ? '' : 'hidden' }}" data-logo-img src="{{ $boot['logo_url'] }}" alt="لوگوی فعلی">
            <label class="btn btn-line block" for="logo-file">انتخاب تصویر لوگو</label>
            <input id="logo-file" class="sr-only" type="file" accept="image/png,image/jpeg,image/webp" data-logo-file>
            <button type="button" class="btn btn-link sm {{ $profile->logo_path ? '' : 'hidden' }}" data-logo-delete>حذف لوگو</button>
            <p class="xs muted">PNG، JPG یا WebP، حداکثر ۱ مگابایت. تصویر روی سرور دوباره ساخته می‌شود و اطلاعات پنهان آن حذف می‌شود. فاکتورهای قبلی لوگوی زمان صدور را نگه می‌دارند.</p>
        @else
            <p class="small muted">نمایش لوگو روی فاکتور در پلن پایه و حرفه‌ای است. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></p>
        @endif
    </section>
</x-layouts.app>
