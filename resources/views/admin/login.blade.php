<x-layouts.admin title="ورود مدیر سامانه" page="admin-login">
    <div class="band stack narrow">
        <p class="small muted">فقط برای کارکنان طلاتا. ورود و همه کارها در لاگ فعالیت ثبت می‌شود.</p>
        <form class="stack" data-admin-mobile @if($step === 'code') hidden @endif novalidate>
            <div class="field"><label for="am">شماره موبایل</label><div class="input-wrap ltr-input"><input id="am" name="mobile" inputmode="tel" autocomplete="username" required></div><div class="err"></div></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="در حال بررسی امنیتی…">دریافت کد</button>
        </form>
        <form class="stack" data-admin-code @if($step !== 'code') hidden @endif novalidate>
            <p class="small">اگر این شماره مدیر سامانه باشد، کد ورود پیامک شد.</p>
            <div class="field"><label for="ac">کد ۶ رقمی</label><div class="input-wrap ltr-input"><input id="ac" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></div><div class="err"></div></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="در حال ورود…">ورود</button>
        </form>
        <div class="or-divider" role="separator"><span>یا</span></div>
        <button class="btn btn-dark block hidden" type="button" data-admin-passkey data-busy-text="منتظر کلید امنیتی…">ورود با کلید عبور (اثر انگشت)</button>
    </div>
</x-layouts.admin>
