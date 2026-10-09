<x-layouts.public :scripts="false" title="پرداخت پیدا نشد">
    <section class="hero stack-sm">
        <span class="badge err">پیدا نشد</span>
        <h2>اطلاعات این پرداخت پیدا نشد یا لینک معتبر نیست.</h2>
        <p class="meta">اگر مبلغی از حساب شما کم شده، وارد زرلیو شوید و از «تنظیمات ← خرید اعتبار پیامک» یا «پلن‌ها» وضعیت سفارش را ببینید. مبالغ تأییدنشده طبق قوانین بانک حداکثر تا ۷۲ ساعت برمی‌گردد.</p>
    </section>
    <a class="btn btn-gold block" href="{{ route('login') }}">ورود به زرلیو</a>
</x-layouts.public>
