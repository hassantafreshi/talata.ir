@php
    $t = $tiles;
    $st = $status;
    $PL = \App\Domain\Admin\TenantDirectory::PLANS_FA;
    $levels = ['high' => ['err', 'فوری'], 'medium' => ['warn', 'مهم'], 'low' => ['info', 'عادی']];
@endphp
<x-layouts.admin title="داشبورد" page="admin-ops" description="وضعیت امروز سرویس. اعداد مالی به تومان.">
    @if ($needsPasskey)
        <div class="notice warn between"><span>برای استفاده از بخش‌های دیگر کنسول، اول یک کلید عبور (اثر انگشت یا قفل دستگاه) اضافه کنید.</span><a class="btn sm btn-dark" href="{{ route('admin.account') }}">افزودن کلید عبور</a></div>
    @endif
    <div class="stat-grid">
        <div class="stat"><span class="small muted">فروشگاه فعال</span><strong>{{ fa($t['tenants']) }}</strong>
            <span class="xs muted">رایگان {{ fa($t['free']) }} · پایه {{ fa($t['plans']['basic'] ?? 0) }} · حرفه‌ای {{ fa($t['plans']['professional'] ?? 0) }}</span></div>
        <div class="stat"><span class="small muted">درآمد این ماه (با مالیات)</span><strong>{{ toman($t['revenue_total'] ?: '0') }}</strong>
            <span class="xs muted">پلن {{ toman((string) ($t['revenue']['PLAN']->base ?? '0')) }} · پیامک {{ toman((string) ($t['revenue']['SMS_CREDIT']->base ?? '0')) }} · مالیات {{ toman($t['revenue_vat'] ?: '0') }}</span></div>
        <div class="stat"><span class="small muted">فاکتور صادرشده امروز</span><strong>{{ fa($t['issued_today']) }}</strong>
            <span class="xs muted">میانگین ۷ روز {{ fa($t['issued_avg']) }} · ابطال امروز {{ fa($t['voids_today']) }}</span></div>
        <div class="stat"><span class="small muted">ثبت‌نام ۷ روز اخیر</span><strong>{{ fa($t['signups_week']) }}</strong>
            <span class="xs muted">پروفایل ناقص {{ fa($t['incomplete']) }}</span></div>
        <div class="stat"><span class="small muted">ورود ۲۴ ساعت / ناموفق</span><strong>{{ fa($t['logins']) }} / {{ fa($t['otp_failed']) }}</strong>
            <a class="xs" href="{{ route('admin.activity', ['service' => 'auth']) }}">سوابق ورود</a></div>
    </div>

    <div class="stat-grid" aria-label="وضعیت سرویس‌ها">
        <a class="stat" href="{{ route('admin.quotes') }}"><span class="small muted">سرویس نرخ</span>
            <span class="status-row">@include('partials.freshness', ['f' => $st['quote']['freshness']])@if($st['quote']['is_emergency'] ?? false)<span class="badge warn">نرخ اعلامی</span>@endif</span>
            <span class="xs muted">{{ $st['quote']['value_toman_fa'] ?? '—' }} تومان · {{ $st['quote']['fetched_at_fa'] ?? '—' }}</span></a>
        <a class="stat" href="{{ route('admin.sms') }}"><span class="small muted">پیامک ۲۴ ساعت</span>
            <span class="status-row"><span class="badge {{ $st['sms_unknown'] ? 'warn' : 'ok' }}">{{ $st['sms_unknown'] ? fa($st['sms_unknown']).' نامعلوم' : 'عادی' }}</span>@if(in_array($st['sms_driver'], ['log', 'fake'], true))<span class="badge warn">آزمایشی</span>@endif</span>
            <span class="xs muted">ارسال {{ fa($st['sms_sent']) }} · ناموفق {{ fa($st['sms_failed']) }}</span></a>
        <a class="stat" href="{{ route('admin.integrations') }}"><span class="small muted">درگاه پرداخت</span>
            <span class="status-row">@if($st['gateway_mock'])<span class="badge warn">آزمایشی</span>@else<span class="badge ok">واقعی</span>@endif</span>
            <span class="xs muted mono">{{ $st['gateway'] }}</span></a>
        <a class="stat" href="{{ route('admin.system') }}"><span class="small muted">صف و زمان‌بند</span>
            <span class="status-row"><span class="badge {{ $st['failed_jobs'] ? 'err' : 'ok' }}">{{ $st['failed_jobs'] ? fa($st['failed_jobs']).' ناموفق' : 'عادی' }}</span></span>
            <span class="xs muted">در صف {{ fa($st['queued']) }} · آخرین دریافت نرخ {{ $st['scheduler_at'] ? jdate(\Carbon\CarbonImmutable::parse($st['scheduler_at']), true) : '—' }}</span></a>
    </div>

    <div class="detail-grid">
        <section class="band stack-sm">
            <h2>هشدارهای باز</h2>
            @if ($alerts)
                <table class="t"><tbody>
                    @foreach ($alerts as $a)
                        <tr><td><span class="badge {{ $levels[$a['level']][0] }}">{{ $levels[$a['level']][1] }}</span></td><td>{{ fa($a['text']) }}</td><td>@if($a['url'])<a href="{{ $a['url'] }}">رسیدگی</a>@endif</td></tr>
                    @endforeach
                </tbody></table>
            @else
                <p class="muted">هشداری باز نیست.</p>
            @endif
            <h2>خطاهای فنی ۲۴ ساعت</h2>
            <table class="t"><tbody>
                @forelse ($techErrors as $row)
                    <tr><td>@if(auth('staff')->user()->allows('logs.tech'))<a href="{{ route('admin.tech', ['service' => $row->service, 'level' => 'warning']) }}">{{ $row->service }}</a>@else{{ $row->service }}@endif</td>
                        <td><span class="badge {{ $row->level === 'warning' ? 'warn' : 'err' }}">{{ $row->level }}</span></td><td class="n">{{ fa($row->c) }}</td></tr>
                @empty
                    <tr><td class="muted">خطایی ثبت نشده است.</td></tr>
                @endforelse
            </tbody></table>
        </section>
        <section class="band stack-sm">
            <h2>کارهای امروز</h2>
            <nav class="list" aria-label="کارهای امروز">
                @foreach ($tasks as $task)
                    <a class="list-item" href="{{ $task['url'] }}"><span class="body">{{ $task['label'] }}</span><span class="badge {{ $task['count'] ? 'warn' : 'off' }}">{{ fa($task['count']) }}</span></a>
                @endforeach
            </nav>
        </section>
    </div>
</x-layouts.admin>
