<x-layouts.admin :title="'فعالیت کاربر '.\App\Support\Mobile::display($user->mobile)">
    <div class="desk-2">
        <section class="band stack-sm">
            <dl class="kv">
                <div><dt>موبایل</dt><dd class="mono">{{ \App\Support\Mobile::display($user->mobile) }}</dd></div>
                <div><dt>عضویت از</dt><dd>{{ jdate($user->created_at) }}</dd></div>
                <div><dt>آخرین ورود</dt><dd>{{ $user->last_login_at ? jdate($user->last_login_at, true) : '—' }}</dd></div>
                <div><dt>اثر انگشت فعال</dt><dd>{{ fa($passkeys->count()) }} دستگاه</dd></div>
            </dl>
            <h2>فروشگاه‌ها</h2>
            <ul class="list">
                @foreach ($memberships as $m)
                    <li class="list-item"><span class="body"><a href="{{ route('admin.tenant', $m->tenant_id) }}"><strong>{{ $m->tenant?->profile?->name ?: '#'.$m->tenant_id }}</strong></a><span class="sub">{{ $m->isOwner() ? 'مالک' : 'همکار' }} · {{ $m->status }}</span></span></li>
                @endforeach
            </ul>
        </section>
        <section class="band stack-sm">
            <h2>فعالیت به تفکیک سرویس</h2>
            <table class="t"><tbody>
                @foreach ($byService as $svc => $c)
                    <tr><td><a href="{{ route('admin.user', ['user' => $user->id, 'service' => $svc]) }}">{{ $services[$svc] ?? $svc }}</a></td><td class="n">{{ fa($c) }}</td></tr>
                @endforeach
            </tbody></table>
            <h2>آخرین کدهای ورود</h2>
            <table class="t"><tbody>
                @forelse ($otp as $o)
                    <tr><td class="n">{{ jdate($o->created_at, true) }}</td><td>{{ \App\Http\Controllers\App\InvoiceController::SMS_STATUS_FA[$o->status][0] ?? $o->status }}</td><td class="mono">{{ $o->provider }}</td></tr>
                @empty
                    <tr><td class="muted">—</td></tr>
                @endforelse
            </tbody></table>
        </section>
    </div>
    @include('admin.partials.activity-filters')
    @include('admin.partials.activity-table')
</x-layouts.admin>
