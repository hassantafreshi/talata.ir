@php($S = \App\Http\Controllers\Admin\PaymentsController::STATUS_FA)
<x-layouts.admin title="پرداخت‌ها" page="admin-ops" description="خرید پلن و اعتبار پیامک از درگاه یا ثبت دستی مالی. مبالغ به تومان؛ «پایه» بدون مالیات.">
    @if ($canExport)
        <x-slot:actions>
            <form method="get" action="{{ route('admin.payments.export') }}" class="status-row">
                <label class="small">ماه (شمسی)<input name="month" placeholder="۱۴۰۵-۰۷" inputmode="numeric" size="8" class="ltr-input"></label>
                <button class="btn sm btn-line" type="submit">خروجی مالی ماه (CSV)</button>
            </form>
        </x-slot:actions>
    @endif

    <div class="stat-grid">
        <div class="stat"><span class="small muted">انجام‌شده امروز</span><strong>{{ fa($tiles['done_count']) }}</strong><span class="xs muted">{{ toman($tiles['done_sum']) }} تومان</span></div>
        <div class="stat"><span class="small muted">منتظر استعلام بانک</span><strong>{{ fa($tiles['pending']) }}</strong><span class="xs muted">استعلام خودکار هر دقیقه</span></div>
        <div class="stat"><span class="small muted">ناموفق امروز</span><strong>{{ fa($tiles['failed']) }}</strong><span class="xs muted">{{ $tiles['top_reason'] ? 'بیشترین دلیل: '.$tiles['top_reason'] : '—' }}</span></div>
        <div class="stat"><span class="small muted">نیازمند بررسی</span><strong>{{ fa($tiles['action']) }}</strong><a class="xs" href="{{ route('admin.payments', ['f' => 'action']) }}">مشاهده</a></div>
    </div>

    <nav class="seg-links" aria-label="فیلتر وضعیت">
        @foreach (\App\Http\Controllers\Admin\PaymentsController::FILTERS as $key => $label)
            <a href="{{ route('admin.payments', array_filter(['f' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}" @if($filter === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <form class="filters band" method="get">
        <input type="hidden" name="f" value="{{ $filter }}">
        <label class="small">شماره سفارش، کد پیگیری بانک یا Authority<input name="q" value="{{ $search }}" class="ltr-input"></label>
        <button class="btn btn-dark" type="submit">جستجو</button>
    </form>

    <div class="table-wrap"><table class="t">
        <thead><tr><th>وضعیت</th><th>شماره سفارش</th><th>فروشگاه</th><th>محصول</th><th class="n">مبلغ سفارش</th><th>روش</th><th>زمان</th></tr></thead>
        <tbody>
        @forelse ($page as $o)
            <tr>
                <td><span class="badge {{ $S[$o->status][1] ?? 'off' }}">{{ $S[$o->status][0] ?? $o->status }}</span></td>
                <td><a class="mono" href="{{ route('admin.payment', $o->id) }}">{{ $o->public_ref }}</a></td>
                <td><a href="{{ route('admin.tenant', $o->tenant_id) }}">{{ $shops[$o->tenant_id] ?? 'فروشگاه #'.fa($o->tenant_id) }}</a></td>
                <td>{{ \App\Http\Controllers\Admin\PaymentsController::PRODUCT_FA[$o->product] ?? $o->product }}@if($o->plan_code) · {{ \App\Domain\Admin\TenantDirectory::PLANS_FA[$o->plan_code] ?? $o->plan_code }} {{ $o->period === 'yearly' ? 'سالانه' : 'ماهانه' }}@endif</td>
                <td class="n">{{ toman($o->amount_irr) }}</td>
                <td>{{ $o->channel === 'MANUAL' ? 'ثبت دستی' : 'درگاه' }}</td>
                <td class="n">{{ jdate($o->created_at, true) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted center">سفارشی با این فیلتر نیست.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $page->links('admin.partials.pager') }}
    <p class="xs muted">بازپرداخت خارج از برنامه انجام می‌شود؛ پس از انجام، در صفحه سفارش با «علامت ناموفق» یا یادداشت مالی ثبت کنید.</p>
</x-layouts.admin>
