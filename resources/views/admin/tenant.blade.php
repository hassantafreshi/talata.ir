<x-layouts.admin :title="$profile?->name ?: 'فروشگاه #'.$tenant->id">
    <div class="desk-2">
        <section class="band stack-sm">
            <dl class="kv">
                <div><dt>پلن</dt><dd>{{ $summary['plan']['label_fa'] }}@if($summary['plan']['ends_at_fa']) تا {{ $summary['plan']['ends_at_fa'] }}@endif</dd></div>
                <div><dt>فاکتور این ماه</dt><dd>{{ fa($summary['quotas']['invoices_per_month']['used']) }}</dd></div>
                <div><dt>پیامک رایگان مانده</dt><dd>{{ fa($summary['free_sms_remaining']) }}</dd></div>
                <div><dt>موبایل کسب‌وکار</dt><dd class="mono">{{ $profile?->business_mobile ? \App\Support\Mobile::display($profile->business_mobile) : '—' }}</dd></div>
                <div><dt>نشانی</dt><dd>{{ $profile?->address ?: '—' }}</dd></div>
            </dl>
        </section>
        <section class="band stack-sm">
            <h2>کاربران</h2>
            <ul class="list">
                @foreach ($members as $m)
                    <li class="list-item"><span class="body">@if($m->user)<a class="mono" href="{{ route('admin.user', $m->user_id) }}">{{ \App\Support\Mobile::display($m->user->mobile) }}</a>@else<span class="mono">{{ \App\Support\Mobile::display($m->invited_mobile) }}</span>@endif
                        <span class="sub">{{ $m->isOwner() ? 'مالک' : 'همکار' }} · {{ $m->status }}</span></span></li>
                @endforeach
            </ul>
            <h2>فعالیت به تفکیک سرویس</h2>
            <table class="t"><tbody>
                @foreach ($byService as $svc => $c)
                    <tr><td><a href="{{ route('admin.activity', ['tenant' => $tenant->id, 'service' => $svc]) }}">{{ \App\Domain\Audit\Audit::SERVICE_LABELS[$svc] ?? $svc }}</a></td><td class="n">{{ fa($c) }}</td></tr>
                @endforeach
            </tbody></table>
            <a class="btn btn-line" href="{{ route('admin.activity', ['tenant' => $tenant->id]) }}">همه فعالیت‌های این فروشگاه</a>
        </section>
    </div>
</x-layouts.admin>
