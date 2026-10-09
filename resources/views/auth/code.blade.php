<x-layouts.guest title="کد ورود" page="login-code">
    <span class="badge info">مرحله ۲ از ۲</span>
    <h1>کد پیامک‌شده را وارد کنید</h1>
    <p class="between"><span>پیامک به <span class="ltr num">{{ $masked }}</span></span><a href="{{ route('login') }}">ویرایش شماره</a></p>
    <form class="stack" method="post" action="{{ route('auth.otp.verify') }}" data-code-form data-resend-at="{{ $resendAt }}" data-mobile="{{ $masked }}" novalidate>
        @csrf
        <div class="field">
            <span class="label" id="code-label">کد ۶ رقمی</span>
            <div class="otp-boxes" role="group" aria-labelledby="code-label">
                @for ($i = 0; $i < 6; $i++)
                    <input inputmode="numeric" autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}" maxlength="6" aria-label="رقم {{ $i + 1 }}" @if($i === 0) autofocus @endif>
                @endfor
            </div>
            <div class="err" role="alert"></div>
        </div>
        <p class="hint">با کامل‌شدن کد، ورود خودکار انجام می‌شود. کد را می‌توانید از پیامک کپی و جای‌گذاری کنید.</p>
        <button class="btn btn-gold block lg" type="submit" data-busy-text="در حال ورود…">ورود</button>
        <p class="notice err hidden" data-otp-failed role="alert">ارسال پیامک به این شماره ناموفق بود. شماره را بررسی کنید و پس از پایان شمارنده «ارسال دوباره کد» را بزنید.</p>
        <button class="btn btn-line block" type="button" data-resend disabled>ارسال دوباره کد</button>
        <details class="band"><summary>کد نرسید؟</summary><p class="hint">آنتن گوشی و فضای خالی صندوق پیامک را بررسی کنید. بعد از پایان شمارنده، «ارسال دوباره کد» را بزنید. اگر شماره اشتباه است، «ویرایش شماره» را بزنید.</p></details>
    </form>
</x-layouts.guest>
