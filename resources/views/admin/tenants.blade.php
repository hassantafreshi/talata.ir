@php($D = \App\Domain\Admin\TenantDirectory::class)
<x-layouts.admin title="فروشگاه‌ها" page="admin-ops" description="همه فروشگاه‌ها با پلن، مصرف این ماه و اعتبار پیامک. اطلاعات مشتریان فروشگاه‌ها اینجا نمایش داده نمی‌شود.">
    @if ($canExport)
        <x-slot:actions><a class="btn sm btn-line" href="{{ route('admin.tenants.export', array_filter($f)) }}">خروجی CSV همین فهرست</a></x-slot:actions>
    @endif
    <nav class="seg-links" aria-label="نمای سریع">
        <a href="{{ route('admin.tenants') }}" @if(! array_filter($f)) aria-current="page" @endif>همه <span class="n">{{ fa($counts['all']) }}</span></a>
        <a href="{{ route('admin.tenants', ['quota' => 'at_cap']) }}" @if(($f['quota'] ?? '') === 'at_cap' && count(array_filter($f)) === 1) aria-current="page" @endif>به سقف فاکتور رسیده <span class="n">{{ fa($counts['at_cap']) }}</span></a>
        <a href="{{ route('admin.tenants', ['period' => 'soon']) }}" @if(($f['period'] ?? '') === 'soon' && count(array_filter($f)) === 1) aria-current="page" @endif>پایان پلن تا ۷ روز <span class="n">{{ fa($counts['soon']) }}</span></a>
        <a href="{{ route('admin.tenants', ['status' => 'suspended']) }}" @if(($f['status'] ?? '') === 'suspended' && count(array_filter($f)) === 1) aria-current="page" @endif>تعلیق <span class="n">{{ fa($counts['suspended']) }}</span></a>
    </nav>
    <form class="filters band" method="get">
        <label class="small">جستجو<input name="q" value="{{ $f['q'] ?? '' }}" placeholder="نام، موبایل کسب‌وکار، شماره سفارش، شناسه"></label>
        <label class="small">پلن<select name="plan"><option value="">همه</option>@foreach ($D::PLANS_FA as $k => $l)<option value="{{ $k }}" @selected(($f['plan'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="small">وضعیت<select name="status"><option value="">همه</option>@foreach ($D::STATUS_FA as $k => $l)<option value="{{ $k }}" @selected(($f['status'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="small">سهمیه فاکتور<select name="quota"><option value="">همه</option>@foreach ($D::QUOTA_FA as $k => $l)<option value="{{ $k }}" @selected(($f['quota'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="small">پایان دوره<select name="period"><option value="">همه</option>@foreach ($D::PERIOD_FA as $k => $l)<option value="{{ $k }}" @selected(($f['period'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
        <button class="btn btn-dark" type="submit">نمایش</button>
    </form>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>فروشگاه</th><th>پلن</th><th>پایان دوره</th><th class="n">فاکتور این ماه</th><th class="n">مشتری جدید</th><th class="n">اعتبار پیامک</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        @forelse ($page as $t)
            @php($r = $rows[$t->id])
            <tr>
                <td><strong>{{ $r['name'] ?: 'بدون نام' }}</strong>@if(! $r['complete']) <span class="badge warn">پروفایل ناقص</span>@endif
                    <span class="xs muted d-block mono">{{ $r['business_mobile'] ? \App\Support\Mobile::display($r['business_mobile']) : '—' }} · #{{ $t->id }}</span></td>
                <td>{{ $r['plan_fa'] }}@if($r['period']) · {{ $r['period'] === 'yearly' ? 'سالانه' : 'ماهانه' }}@endif</td>
                <td class="n small">{{ $r['ends_fa'] ?? '—' }}</td>
                <td class="n">{{ fa($r['invoices']) }} / {{ $r['invoice_limit'] === null ? '—' : fa($r['invoice_limit']) }}</td>
                <td class="n">{{ fa($r['customers']) }} / {{ $r['customer_limit'] === null ? '—' : fa($r['customer_limit']) }}</td>
                <td class="n">{{ toman($r['credit_irr']) }}</td>
                <td>@if($t->isActive())<span class="badge ok">فعال</span>@else<span class="badge err">تعلیق</span>@endif</td>
                <td><a class="btn sm btn-line" href="{{ route('admin.tenant', $t->id) }}">مشاهده</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted center">فروشگاهی با این فیلتر نیست.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $page->links('admin.partials.pager') }}
</x-layouts.admin>
