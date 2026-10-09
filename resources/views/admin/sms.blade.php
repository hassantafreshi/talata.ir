@php
    $P = \App\Http\Controllers\Admin\SmsController::PURPOSE_FA;
    $C = \App\Http\Controllers\Admin\SmsController::CHARGE_FA;
@endphp
<x-layouts.admin title="پیامک" page="admin-ops" description="وضعیت ارسال پیامک‌ها. شماره گیرنده پوشانده است و متن پیامک‌ها نمایش داده نمی‌شود؛ ارسال دوباره فقط از پنل فروشگاه است.">
    @if ($canManage)
        <x-slot:actions>
            <button class="btn sm btn-line" type="button" data-post="{{ route('admin.sms.test') }}" data-confirm="یک پیامک آزمایشی به شماره خودتان ({{ $myMobile }}) فرستاده شود؟">ارسال آزمایشی به شماره من</button>
        </x-slot:actions>
    @endif
    <div class="stat-grid">
        <div class="stat"><span class="small muted">ارسال‌شده ۲۴ ساعت</span><strong>{{ fa($tiles['sent']) }}</strong>
            <span class="xs muted">@foreach ($tiles['by_purpose'] as $p => $c){{ $P[$p] ?? $p }} {{ fa($c) }}@if(! $loop->last) · @endif @endforeach</span></div>
        <div class="stat"><span class="small muted">تحویل‌شده ۲۴ ساعت</span><strong>{{ fa($tiles['delivered']) }}</strong></div>
        <div class="stat"><span class="small muted">نامعلوم بیش از ۳۰ دقیقه</span><strong>{{ fa($tiles['unknown_old']) }}</strong><span class="xs muted">بررسی خودکار هر دقیقه</span></div>
        <div class="stat"><span class="small muted">کد ورود امروز / سقف روزانه</span><strong>{{ fa($tiles['otp_today']) }} / {{ fa($tiles['otp_budget']) }}</strong>
            @if ($tiles['otp_budget'] && $tiles['otp_today'] >= 0.8 * $tiles['otp_budget'])<span class="badge warn">بیش از ۸۰٪</span>@endif</div>
        <div class="stat"><span class="small muted">در صف ارسال</span><strong>{{ fa($tiles['queued']) }}</strong><span class="xs muted">ارائه‌دهنده: <span class="mono">{{ $driver }}</span></span></div>
    </div>

    <nav class="seg-links" aria-label="فیلتر">
        @foreach (\App\Http\Controllers\Admin\SmsController::FILTERS as $key => $label)
            <a href="{{ route('admin.sms', ['f' => $key]) }}" @if($filter === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>وضعیت</th><th>فروشگاه</th><th>نوع</th><th>گیرنده</th><th class="n">بخش</th><th class="n">هزینه</th><th class="n">تلاش</th><th>زمان</th><th></th></tr></thead>
        <tbody>
        @forelse ($page as $m)
            <tr>
                <td><span class="badge {{ $statusFa[$m->status][1] ?? 'off' }}">{{ $statusFa[$m->status][0] ?? $m->status }}</span>@if($m->last_error)<span class="xs muted d-block">{{ \Illuminate\Support\Str::limit($m->last_error, 60) }}</span>@endif</td>
                <td>@if($m->tenant_id)<a href="{{ route('admin.tenant', $m->tenant_id) }}">{{ $shops[$m->tenant_id] ?? 'فروشگاه #'.fa($m->tenant_id) }}</a>@else<span class="muted">سرویس</span>@endif</td>
                <td>{{ $P[$m->purpose] ?? $m->purpose }}</td>
                <td class="num">{{ fa(\App\Support\Mobile::mask($m->recipient)) }}</td>
                <td class="n">{{ fa($m->segments) }}</td>
                <td class="n">@if($m->charge_source === 'CREDIT'){{ toman($m->cost_irr) }} تومان@else{{ $C[$m->charge_source] ?? $m->charge_source }}@endif</td>
                <td class="n">{{ fa($m->attempts) }}</td>
                <td class="n">{{ jdate($m->created_at, true) }}</td>
                <td>@if($canManage && $m->provider_message_id && ! in_array($m->status, ['DELIVERED', 'CANCELLED'], true))<button class="btn sm btn-line" type="button" data-post="{{ route('admin.sms.inquire', $m->id) }}" data-reload>استعلام</button>@endif</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted center">موردی نیست.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $page->links('admin.partials.pager') }}

    <div class="desk-2">
        <section class="band stack-sm">
            <h2>قاعده بخش‌بندی (هر بخش جدا هزینه دارد)</h2>
            <dl class="kv">
                <div><dt>فارسی (یونیکد) یک‌بخشی / هر بخش چندبخشی</dt><dd>{{ fa($rules['unicode_single']) }} / {{ fa($rules['unicode_multi']) }} نویسه</dd></div>
                <div><dt>لاتین یک‌بخشی / هر بخش چندبخشی</dt><dd>{{ fa($rules['gsm_single']) }} / {{ fa($rules['gsm_multi']) }} نویسه</dd></div>
                <div><dt>سقف روزانه هر فروشگاه</dt><dd>رایگان {{ fa($rules['tenant_daily_cap']['free']) }} · پایه {{ fa($rules['tenant_daily_cap']['basic']) }} · حرفه‌ای {{ fa($rules['tenant_daily_cap']['professional']) }}</dd></div>
                <div><dt>هر گیرنده در روز (هر فروشگاه)</dt><dd>{{ fa($rules['per_recipient_per_tenant_daily']) }}</dd></div>
            </dl>
            <p class="xs muted">با قواعد ارائه‌دهنده پیامک تطبیق داده شود. تغییر این اعداد از تنظیمات سرور انجام می‌شود.</p>
        </section>
        <section class="band stack-sm">
            <h2>کد ورود و متن پیش‌فرض</h2>
            <dl class="kv">
                <div><dt>سقف روزانه شماره‌های جدید / کاربران ثبت‌شده</dt><dd>{{ fa($otp['global_daily_budget']) }} / {{ fa($otp['existing_users_daily_budget']) }}</dd></div>
                <div><dt>هر شماره در ساعت / روز</dt><dd>{{ fa($otp['per_mobile_hour']) }} / {{ fa($otp['per_mobile_day']) }}</dd></div>
                <div><dt>هشدار</dt><dd>در ۸۰٪ سقف روزانه</dd></div>
            </dl>
            <p class="small">متن پیش‌فرض پیامک فاکتور (رایگان ثابت؛ پایه و حرفه‌ای قابل ویرایش در پنل فروشگاه):</p>
            <p class="band white small">{{ $defaultTemplate }}</p>
        </section>
    </div>
</x-layouts.admin>
