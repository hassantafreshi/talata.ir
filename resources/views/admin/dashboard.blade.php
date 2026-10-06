@php
    $st = $stats;
    $smsBy = $sms->groupBy('purpose');
    $purposes = ['OTP' => 'کد ورود', 'INVOICE' => 'فاکتور', 'REMINDER' => 'یادآوری قسط'];
@endphp
<x-layouts.admin title="داشبورد ۲۴ ساعت اخیر">
    <div class="stat-grid">
        <div class="stat"><span class="small muted">فروشگاه‌ها</span><strong>{{ fa($st['tenants']) }}</strong><span class="xs muted">{{ fa($st['tenants_new']) }} جدید</span></div>
        <div class="stat"><span class="small muted">کاربران</span><strong>{{ fa($st['users']) }}</strong><span class="xs muted">{{ fa($st['logins']) }} ورود</span></div>
        <div class="stat"><span class="small muted">ورود ناموفق</span><strong>{{ fa($st['otp_failed']) }}</strong><a class="xs" href="{{ route('admin.activity', ['service' => 'auth']) }}">مشاهده</a></div>
        <div class="stat"><span class="small muted">فاکتور صادرشده</span><strong>{{ fa($st['invoices']) }}</strong></div>
        <div class="stat"><span class="small muted">پرداخت موفق / ناموفق</span><strong>{{ fa($st['payments_ok']) }} / {{ fa($st['payments_failed']) }}</strong><span class="xs muted">{{ fa($st['payments_pending']) }} در انتظار بررسی</span></div>
        <div class="stat"><span class="small muted">صف / کار ناموفق</span><strong>{{ fa($st['queued_jobs']) }} / {{ fa($st['failed_jobs']) }}</strong></div>
    </div>

    <div class="desk-2">
        <section class="band stack-sm">
            <h2>پیامک‌ها</h2>
            <p class="xs muted">ارائه‌دهنده: <span class="mono">{{ $smsDriver }}</span>@if(in_array($smsDriver, ['log', 'fake'], true)) <span class="badge warn">آزمایشی</span>@endif</p>
            <table class="t"><thead><tr><th>نوع</th><th>وضعیت</th><th class="n">تعداد</th></tr></thead><tbody>
                @forelse ($sms as $row)
                    <tr><td>{{ $purposes[$row->purpose] ?? $row->purpose }}</td><td>{{ \App\Http\Controllers\App\InvoiceController::SMS_STATUS_FA[$row->status][0] ?? $row->status }}</td><td class="n">{{ fa($row->c) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="muted">پیامکی ارسال نشده است.</td></tr>
                @endforelse
            </tbody></table>
        </section>
        <section class="band stack-sm">
            <h2>هشدار و خطای فنی</h2>
            <table class="t"><thead><tr><th>سرویس</th><th>سطح</th><th class="n">تعداد</th></tr></thead><tbody>
                @forelse ($techErrors as $row)
                    <tr><td><a href="{{ auth('staff')->user()->allows('logs.tech') ? route('admin.tech', ['service' => $row->service, 'level' => 'warning']) : '#' }}">{{ $row->service }}</a></td><td><span class="badge {{ $row->level === 'warning' ? 'warn' : 'err' }}">{{ $row->level }}</span></td><td class="n">{{ fa($row->c) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="muted">خطایی ثبت نشده است.</td></tr>
                @endforelse
            </tbody></table>
            <p class="xs muted">مظنه: {{ $quote['value_toman_fa'] ?? '—' }} تومان · {{ $quote['fetched_at_fa'] ?? '—' }} · @include('partials.freshness', ['f' => $quote['freshness']]) · درگاه: <span class="mono">{{ $paymentDriver }}</span></p>
        </section>
    </div>
</x-layouts.admin>
