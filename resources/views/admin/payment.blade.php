@php
    $S = \App\Http\Controllers\Admin\PaymentsController::STATUS_FA;
    $codes = \App\Domain\Billing\BillingService::BANK_CODES_FA;
    $open = in_array($o->status, ['AWAITING_PAYMENT', 'VERIFYING', 'PENDING_VERIFICATION'], true);
@endphp
<x-layouts.admin :title="'سفارش '.$o->public_ref" page="admin-ops">
    <div class="detail-grid">
        <section class="band stack-sm">
            <div class="status-row"><span class="badge {{ $S[$o->status][1] ?? 'off' }}">{{ $S[$o->status][0] ?? $o->status }}</span>
                @if($o->failure_message)<span class="small">{{ $o->failure_message }}</span>@endif
                @if($o->channel === 'MANUAL')<span class="badge info">ثبت دستی</span>@endif</div>
            <dl class="kv">
                <div><dt>فروشگاه</dt><dd><a href="{{ route('admin.tenant', $o->tenant_id) }}">{{ $shop ?? 'فروشگاه #'.fa($o->tenant_id) }}</a></dd></div>
                <div><dt>محصول</dt><dd>{{ \App\Http\Controllers\Admin\PaymentsController::PRODUCT_FA[$o->product] ?? $o->product }}@if($o->plan_code) · {{ $o->price_snapshot['plan_label_fa'] ?? $o->plan_code }} {{ $o->period === 'yearly' ? 'سالانه' : 'ماهانه' }}@endif</dd></div>
                @if ($o->discount_irr && $o->discount_irr !== '0')<div><dt>قیمت فهرست / تخفیف</dt><dd>{{ toman($o->list_subtotal_irr) }} / {{ toman($o->discount_irr) }} ({{ $o->discount_code }})</dd></div>@endif
                <div><dt>پایه (بدون مالیات)</dt><dd>{{ toman($o->subtotal_irr) }} تومان</dd></div>
                <div><dt>مالیات بر ارزش افزوده ({{ pct($o->vat_rate_percent) }}٪)</dt><dd>{{ toman($o->vat_irr) }} تومان</dd></div>
                <div><dt>جمع قابل پرداخت</dt><dd><strong>{{ toman($o->amount_irr) }} تومان</strong></dd></div>
                <div><dt>ساخت / پرداخت / اعمال</dt><dd class="small">{{ jdate($o->created_at, true) }} / {{ $o->paid_at ? jdate($o->paid_at, true) : '—' }} / {{ $o->fulfilled_at ? jdate($o->fulfilled_at, true) : '—' }}</dd></div>
                @if ($o->staff_id)<div><dt>اقدام مالی</dt><dd class="small">{{ $staffName }} · {{ $o->staff_reason }}@if($o->manual_reference) · پیگیری <span class="mono">{{ $o->manual_reference }}</span>@endif</dd></div>@endif
            </dl>
            <h2>تلاش‌های پرداخت</h2>
            @foreach ($attempts as $a)
                <div class="action-card stack-sm">
                    <dl class="kv">
                        <div><dt>درگاه</dt><dd class="mono">{{ $a->gateway }}</dd></div>
                        <div><dt>Authority</dt><dd class="mono">{{ $a->authority }}</dd></div>
                        <div><dt>وضعیت</dt><dd>{{ $a->status }}@if($a->bank_code) · کد {{ $a->bank_code }} ({{ $codes[$a->bank_code] ?? 'نامشخص' }})@endif</dd></div>
                        <div><dt>کد پیگیری بانک / کارت</dt><dd class="mono">{{ $a->ref_id ?: '—' }} {{ $a->card_mask }}</dd></div>
                        <div><dt>بازگشت از بانک / تأیید</dt><dd class="small">{{ $a->callback_at ? jdate($a->callback_at, true) : '—' }} / {{ $a->verified_at ? jdate($a->verified_at, true) : '—' }}</dd></div>
                        <div><dt>استعلام خودکار</dt><dd class="small">{{ fa($a->reconcile_attempts) }} بار@if($a->next_reconcile_at) · بعدی {{ jdate($a->next_reconcile_at, true) }}@endif</dd></div>
                    </dl>
                    @if ($a->raw_result_redacted)
                        <details><summary class="small">نمایش پاسخ خام (پوشانده)</summary><pre class="raw-json">{{ json_encode($a->raw_result_redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>
                    @endif
                </div>
            @endforeach
        </section>

        <section class="stack-sm" aria-label="اقدام‌ها">
            @if ($staff->allows('payments.inquire') && ($attempts->first()?->gateway ?? 'manual') !== 'manual')
                <div class="action-card stack-sm">
                    <h3>استعلام دوباره از بانک</h3>
                    <p class="small muted">از همان درگاهی که پرداخت را باز کرد می‌پرسد. اگر بانک پرداخت را با همین مبلغ تأیید کند، سفارش اعمال می‌شود؛ وگرنه چیزی عوض نمی‌شود.</p>
                    <button class="btn btn-dark" type="button" data-post="{{ route('admin.payment.inquire', $o->id) }}" data-reload>استعلام از بانک</button>
                </div>
            @endif
            @if ($staff->allows('payments.manage') && $o->status !== 'FULFILLED')
                <form class="action-card stack-sm" data-action="{{ route('admin.payment.confirm', $o->id) }}" data-idem data-reload
                      data-confirm="پرداخت سفارش {{ $o->public_ref }} با شماره پیگیری {bank_reference} تأیید و پلن یا اعتبار اعمال شود؟">
                    <h3>ثبت تأیید دستی…</h3>
                    <p class="small muted">فقط وقتی واریز در صورت‌حساب بانک دیده شده است. همان اعمال عادی سفارش (یک‌بار) اجرا می‌شود و با نام شما ثبت می‌شود.</p>
                    <div class="field"><label for="br">شماره پیگیری بانک</label><input id="br" name="bank_reference" required maxlength="60" class="ltr-input" inputmode="numeric"></div>
                    <div class="field"><label for="cr">دلیل</label><textarea id="cr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                    <button class="btn btn-gold" type="submit">تأیید پرداخت و اعمال</button>
                </form>
            @endif
            @if ($staff->allows('payments.manage') && $open)
                <form class="action-card danger stack-sm" data-action="{{ route('admin.payment.fail', $o->id) }}" data-idem data-reload
                      data-confirm="سفارش {{ $o->public_ref }} ناموفق ثبت شود؟ اگر بعداً بانک پرداخت را تأیید کند، سفارش دوباره اعمال می‌شود.">
                    <h3>علامت ناموفق…</h3>
                    <div class="field"><label for="fr">دلیل</label><textarea id="fr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                    <button class="btn btn-line" type="submit">ثبت ناموفق</button>
                </form>
            @endif
            <p class="xs muted">بازپرداخت بیرون از برنامه انجام می‌شود (حداکثر {{ fa(config('talata.payments.refund_hours_display')) }} ساعت در پیام کاربر).</p>
        </section>
    </div>
</x-layouts.admin>
