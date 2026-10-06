<x-layouts.guest title="ورود" page="login">
    <span class="badge info">مرحله ۱ از ۲ · شماره موبایل</span>
    <h1>شماره موبایل خود را وارد کنید</h1>
    <form class="stack" method="post" action="{{ route('auth.otp.request') }}" data-login-form novalidate>
        @csrf
        <div class="field">
            <label for="mobile">شماره موبایل</label>
            <div class="input-wrap ltr-input"><input id="mobile" name="mobile" type="tel" inputmode="numeric" autocomplete="tel" placeholder="۰۹۱۲ ۳۴۵ ۶۷۸۹" required autofocus></div>
            <div class="err"></div>
        </div>
        <div class="honeypot" aria-hidden="true"><label for="website">وب‌سایت</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <p class="hint">کد تأیید به همین شماره پیامک می‌شود. اگر بار اول است، همین کد شما را ثبت‌نام هم می‌کند؛ رمز یا ایمیل لازم نیست.</p>
        <button class="btn btn-gold block lg" type="submit" data-busy-text="در حال بررسی امنیتی…">دریافت کد پیامکی</button>
        <p class="hint center">ارقام فارسی یا انگلیسی، هر دو قبول است. شماره شما فقط برای ورود استفاده می‌شود.</p>
    </form>
    <section class="stack-sm hidden" data-passkey-login aria-labelledby="pk-h">
        <div class="or-divider" role="separator"><span>یا</span></div>
        <button class="btn btn-dark block lg" type="button" data-passkey-btn data-busy-text="منتظر اثر انگشت…" id="pk-h">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 11v3a6 6 0 0 1-1.5 4M8 10a4 4 0 0 1 8 0v2M5 12V10a7 7 0 0 1 13.5-2.5M19 12v1a10 10 0 0 1-.8 4M9 21a9 9 0 0 0 2-4"/></svg>
            ورود با اثر انگشت یا چهره
        </button>
        <p class="hint center">اگر قبلاً روی همین گوشی فعال کرده‌اید. اثر انگشت روی گوشی شما می‌ماند و به زرلیو فرستاده نمی‌شود.</p>
    </section>
</x-layouts.guest>
