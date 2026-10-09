@php
    $ago = fn (?int $ts) => $ts ? jdate(\Carbon\CarbonImmutable::createFromTimestamp($ts), true) : 'ثبت نشده';
    $stale = fn (?int $ts, int $hours) => ! $ts || now()->getTimestamp() - $ts > $hours * 3600;
@endphp
<x-layouts.admin title="سلامت سیستم" page="admin-ops" description="صف کارها، زمان‌بند، پشتیبان پایگاه داده و نسخه.">
    <div class="stat-grid">
        <div class="stat"><span class="small muted">تأخیر صف</span><strong>{{ fa($lag) }} ثانیه</strong>@if($lag > 120)<span class="badge warn">کند</span>@endif</div>
        <div class="stat"><span class="small muted">کار ناموفق</span><strong>{{ fa($failedCount) }}</strong></div>
        <div class="stat"><span class="small muted">پیامک در صف</span><strong>{{ fa($outbox) }}</strong><span class="xs muted">{{ $outboxOldest ? 'قدیمی‌ترین '.jdate(\Carbon\CarbonImmutable::parse($outboxOldest), true) : '—' }}</span></div>
        <div class="stat"><span class="small muted">آخرین پشتیبان پایگاه داده</span><strong class="small">{{ $ago($backup) }}</strong>@if($stale($backup, 26))<span class="badge err">بیش از یک روز</span>@endif</div>
        <div class="stat"><span class="small muted">آخرین تمرین بازیابی</span><strong class="small">{{ $ago($drill) }}</strong>@if($stale($drill, 24 * 30))<span class="badge warn">بیش از ۳۰ روز</span>@endif</div>
    </div>

    <section class="band stack-sm">
        <h2>زمان‌بند</h2>
        <div class="table-wrap"><table class="t">
            <thead><tr><th>کار</th><th>تناوب</th><th>آخرین اجرا</th><th class="n">مدت</th><th>نتیجه</th></tr></thead>
            <tbody>
            @foreach ($schedule as $key => $s)
                <tr><td>{{ $s['label'] }} <span class="mono">{{ $key }}</span></td><td class="small">{{ $s['cadence'] }}</td>
                    <td class="n small">{{ $s['at'] ? jdate(\Carbon\CarbonImmutable::parse($s['at']), true) : 'هنوز اجرا نشده' }}</td>
                    <td class="n">{{ $s['duration_ms'] !== null ? fa($s['duration_ms']).' ms' : '—' }}</td>
                    <td>@if($s['ok'] === null)<span class="badge off">—</span>@elseif($s['ok'])<span class="badge ok">موفق</span>@else<span class="badge err">خطا</span>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
        <p class="xs muted">زمان‌بند با <span class="mono">php artisan schedule:run</span> هر دقیقه (cron) اجرا می‌شود؛ صف با <span class="mono">queue:work --queue=otp,default</span>.</p>
    </section>

    <div class="detail-grid">
        <section class="band stack-sm">
            <h2>کارهای ناموفق صف</h2>
            <div class="table-wrap"><table class="t">
                <thead><tr><th>زمان</th><th>صف</th><th>خطا</th><th></th></tr></thead>
                <tbody>
                @forelse ($failed as $j)
                    <tr><td class="n small">{{ jdate(\Carbon\CarbonImmutable::parse($j->failed_at), true) }}</td><td class="mono">{{ $j->queue }}</td>
                        <td class="xs">{{ \Illuminate\Support\Str::limit(strtok($j->exception, "\n"), 140) }}</td>
                        <td>@if($canManage)<button class="btn sm btn-line" type="button" data-post="{{ route('admin.system.retry', $j->uuid) }}" data-reload>اجرای دوباره</button>@endif</td></tr>
                @empty
                    <tr><td colspan="4" class="muted">کار ناموفقی نیست.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <section class="band stack-sm">
            <h2>نسخه و محیط</h2>
            <dl class="kv">
                <div><dt>نسخه انتشار</dt><dd class="mono">{{ $version }}</dd></div>
                <div><dt>محیط</dt><dd>@if($env === 'production')<span class="badge ok">production</span>@else<span class="badge warn">{{ $env }}</span>@endif @if($debug)<span class="badge err">debug روشن</span>@endif</dd></div>
                <div><dt>PHP / Laravel</dt><dd class="mono">{{ $php }} / {{ $laravel }}</dd></div>
                <div><dt>صف‌ها</dt><dd class="small">@forelse($jobs as $q){{ $q->queue }}: {{ fa($q->c) }}@if(! $loop->last) · @endif @empty خالی @endforelse</dd></div>
                <div><dt>migration اجرانشده</dt><dd>@if($pending)<span class="badge err">{{ fa(count($pending)) }}</span>@else<span class="badge ok">ندارد</span>@endif</dd></div>
            </dl>
        </section>
    </div>
</x-layouts.admin>
