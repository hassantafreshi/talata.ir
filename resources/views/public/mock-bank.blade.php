<x-layouts.public title="درگاه آزمایشی">
    <div class="notice warn">این درگاه آزمایشی است و فقط تا انتخاب درگاه واقعی برای تست جریان پرداخت وجود دارد. هیچ پولی جابه‌جا نمی‌شود.</div>
    <section class="band stack-sm">
        <h2>پرداخت آزمایشی</h2>
        <dl class="kv">
            <div><dt>سفارش</dt><dd class="num ltr">{{ $order->public_ref }}</dd></div>
            <div><dt>مبلغ</dt><dd class="num">{{ toman($attempt->amount_irr) }} تومان</dd></div>
        </dl>
    </section>
    <form method="post" action="{{ route('pay.mock.decide', $authority) }}" class="stack-sm">
        @csrf
        <button class="btn btn-gold block" name="decision" value="success">پرداخت موفق</button>
        <button class="btn btn-line block" name="decision" value="cancel">انصراف کاربر</button>
        <button class="btn btn-line block" name="decision" value="mismatch">مبلغ نادرست از بانک (آزمون امنیت)</button>
        <button class="btn btn-line block" name="decision" value="timeout">بی‌پاسخ ماندن بانک (آزمون بررسی بعدی)</button>
    </form>
</x-layouts.public>
