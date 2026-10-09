<x-layouts.admin title="لاگ فنی سرویس‌ها">
    <p class="small muted">درخواست‌های ارائه‌دهنده پیامک (کاوه‌نگار)، درگاه پرداخت، مظنه، صف کارها، هشدارها و خطاها. کلیدها، کدها و شماره‌ها پیش از ذخیره پوشانده می‌شوند. نگهداری: {{ fa(config('talata.logs.tech_retention_days')) }} روز.</p>
    <div class="seg" role="tablist" aria-label="سرویس">
        <a href="{{ request()->fullUrlWithQuery(['service' => null, 'page' => null]) }}" @if(empty($filters['service'])) aria-current="page" @endif>همه</a>
        @foreach ($services as $svc)
            <a href="{{ request()->fullUrlWithQuery(['service' => $svc, 'page' => null]) }}" @if(($filters['service'] ?? '') === $svc) aria-current="page" @endif>{{ $svc }}</a>
        @endforeach
    </div>
    <form class="filters band" method="get">
        @if (! empty($filters['service']))<input type="hidden" name="service" value="{{ $filters['service'] }}">@endif
        <label class="small">حداقل سطح<select name="level"><option value="">همه</option>@foreach ($levels as $l)<option value="{{ $l }}" @selected(($filters['level'] ?? '') === $l)>{{ $l }}</option>@endforeach</select></label>
        <label class="small">متن<input name="q" value="{{ $filters['q'] ?? '' }}" dir="ltr"></label>
        <label class="small">شناسه درخواست<input name="request" value="{{ $filters['request'] ?? '' }}" dir="ltr"></label>
        <label class="small">از تاریخ<input name="from" value="{{ $filters['from'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۰۱" dir="ltr"></label>
        <label class="small">تا تاریخ<input name="to" value="{{ $filters['to'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۳۰" dir="ltr"></label>
        <button class="btn btn-dark" type="submit">اعمال</button>
    </form>
    <div class="table-wrap">
        <table class="t">
            <thead><tr><th>زمان</th><th>سطح</th><th>سرویس</th><th>پیام</th><th>درخواست</th></tr></thead>
            <tbody>
            @forelse ($page as $log)
                <tr class="lvl-{{ $log->level }}">
                    <td class="n">{{ jdate(\Carbon\CarbonImmutable::parse($log->created_at), true) }}</td>
                    <td><span class="badge {{ in_array($log->level, ['error', 'critical', 'alert', 'emergency'], true) ? 'err' : ($log->level === 'warning' ? 'warn' : 'off') }}">{{ $log->level }}</span></td>
                    <td><a href="{{ request()->fullUrlWithQuery(['service' => $log->service, 'page' => null]) }}" class="mono">{{ $log->service }}</a></td>
                    <td class="mono">{{ $log->message }}
                        @if ($log->context && $log->context !== '{}' && $log->context !== '[]')<details><summary class="xs">context</summary><pre class="log-ctx">{{ json_encode(json_decode($log->context), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif</td>
                    <td>@if ($log->request_id)<a class="mono" href="{{ route('admin.activity', ['request' => $log->request_id]) }}" title="فعالیت همین درخواست">{{ substr($log->request_id, -8) }}</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted center">لاگی با این فیلترها پیدا نشد.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $page->links('admin.partials.pager') }}
</x-layouts.admin>
