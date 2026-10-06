{{-- $page of AuditEvent; $users, $staff, $tenants lookups; $services labels --}}
@php $labels = \App\Http\Controllers\Admin\ActivityController::EVENT_LABELS; @endphp
<div class="table-wrap">
<table class="t">
    <thead><tr><th>زمان</th><th>سرویس</th><th>رویداد</th><th>انجام‌دهنده</th><th>فروشگاه</th><th>موضوع</th><th>IP</th><th>درخواست</th></tr></thead>
    <tbody>
    @forelse ($page as $e)
        <tr>
            <td class="n">{{ jdate($e->created_at, true) }}</td>
            <td><a href="{{ request()->fullUrlWithQuery(['service' => $e->service, 'page' => null]) }}"><span class="badge info">{{ $services[$e->service] ?? $e->service }}</span></a></td>
            <td>{{ $labels[$e->event] ?? $e->event }}<div class="xs muted mono">{{ $e->event }}</div>
                @if (! empty($e->data))<details><summary class="xs">جزئیات</summary><pre class="log-ctx">{{ json_encode($e->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre></details>@endif</td>
            <td>
                @if ($e->actor_user_id)<a href="{{ route('admin.user', $e->actor_user_id) }}" class="mono">{{ \App\Support\Mobile::display($users[$e->actor_user_id] ?? '') }}</a>
                @elseif ($e->staff_id)<span class="badge warn">مدیر: {{ $staff[$e->staff_id] ?? $e->staff_id }}</span>
                @else<span class="muted small">{{ ['system' => 'سیستم', 'user' => 'کاربر (پیش از ورود)', 'provider' => 'ارائه‌دهنده'][$e->actor_type] ?? $e->actor_type }}</span>@endif
            </td>
            <td>@if ($e->tenant_id)<a href="{{ route('admin.tenant', $e->tenant_id) }}">{{ $tenants[$e->tenant_id]?->profile?->name ?: '#'.$e->tenant_id }}</a>@else — @endif</td>
            <td class="xs">{{ $e->subject_type }} <span class="mono">{{ \Illuminate\Support\Str::limit((string) $e->subject_id, 12) }}</span></td>
            <td class="mono">{{ $e->ip }}</td>
            <td>@if ($e->request_id)@if(auth('staff')->user()->isAdmin())<a class="mono" href="{{ route('admin.tech', ['request' => $e->request_id]) }}" title="لاگ فنی همین درخواست">{{ substr($e->request_id, -8) }}</a>@else<span class="mono">{{ substr($e->request_id, -8) }}</span>@endif @endif</td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted center">رویدادی با این فیلترها پیدا نشد.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
{{ $page->links('admin.partials.pager') }}
