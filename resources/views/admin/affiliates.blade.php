<x-layouts.admin title="همکاری در فروش (افیلیت)" page="admin-affiliates">
    <p class="small muted">به شماره کاربری که پنل فروشگاه دارد همکاری در فروش بدهید؛ یک کد تخفیف و لینک معرفی برایش ساخته می‌شود. فروشگاه‌های جدیدی که با این کد یا لینک بیایند به او تعلق می‌گیرند و از هر پرداختشان کمیسیون ثبت می‌شود.</p>
    @if (auth('staff')->user()->allows('affiliates.manage'))
        <h2>همکار جدید</h2>
        @include('admin.partials.affiliate-form', ['a' => null])
    @endif
    <form class="filters band" method="get"><label class="small">جستجو: کد یا موبایل<input name="q" value="{{ $search }}" dir="ltr"></label><button class="btn btn-dark" type="submit">جستجو</button></form>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>همکار</th><th>کد</th><th>کمیسیون</th><th>تخفیف خریدار</th><th class="n">مشتری</th><th class="n">در انتظار</th><th class="n">قابل پرداخت</th><th class="n">پرداخت‌شده</th><th>وضعیت</th></tr></thead>
        <tbody>
        @forelse ($page as $a)
            @php $t = $totals[$a->id]; @endphp
            <tr><td><a class="mono" href="{{ route('admin.affiliate', $a->id) }}">{{ \App\Support\Mobile::display($a->user->mobile) }}</a></td><td class="mono">{{ $a->code }}</td>
                <td>{{ pct($a->commission_percent) }}٪ · {{ $a->commission_mode === 'LIFETIME' ? 'مادام‌العمر' : 'پرداخت اول' }}</td>
                <td>{{ pct($a->discount_percent) }}٪</td><td class="n">{{ fa($a->referrals_count) }}</td>
                <td class="n">{{ toman($t['PENDING']) }}</td><td class="n"><strong>{{ toman($t['APPROVED']) }}</strong></td><td class="n">{{ toman($t['PAID']) }}</td>
                <td><span class="badge {{ $a->isActive() ? 'ok' : 'warn' }}">{{ $a->isActive() ? 'فعال' : 'متوقف' }}</span></td></tr>
        @empty
            <tr><td colspan="9" class="muted center">هنوز همکار فروشی ثبت نشده است.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $page->links('admin.partials.pager') }}
</x-layouts.admin>
