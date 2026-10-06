<x-layouts.guest title="ورود" page="login">
    <span class="badge info">مرحله ۱ از ۲ · شماره موبایل</span>
    <h1>شماره موبایل خود را وارد کنید</h1>
    <form class="stack" data-login-form novalidate>
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
</x-layouts.guest>
