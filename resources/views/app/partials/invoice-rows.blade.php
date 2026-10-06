@forelse ($page as $inv)
    @php($sms = $inv->smsMessages->first())
    <li>
        <a class="list-item" href="{{ $inv->status === 'draft' ? route('invoices.items', $inv) : route('invoices.show', $inv) }}">
            <span class="body">
                <strong>@if($inv->status === 'draft')پیش‌نویس@else فاکتور <span class="num ltr">{{ invno($inv->number) }}</span>@endif
                    @if($inv->buyer_name) · {{ $inv->buyer_name }}@endif</strong>
                <span class="sub">{{ $inv->status === 'draft' ? 'ذخیره '.jtime($inv->updated_at) : jdate($inv->issued_at) }} · {{ fa($inv->items_count) }} ردیف
                    @if($sms) · پیامک: {{ \App\Http\Controllers\App\InvoiceController::SMS_STATUS_FA[$sms->status][0] }}@endif</span>
            </span>
            <span class="stack-sm center">
                @if ($inv->payable_irr)<span class="num strong nowrap">{{ toman(ltrim((string) $inv->payable_irr, '-')) }} <span class="unit">تومان</span></span>@if(str_starts_with((string) $inv->payable_irr, '-'))<span class="xs muted">به نفع مشتری</span>@endif @endif
                @switch($inv->status)
                    @case('draft')<span class="badge info">پیش‌نویس</span>@break
                    @case('void')<span class="badge err">باطل</span>@break
                    @default<span class="badge ok">قطعی</span>
                @endswitch
            </span>
        </a>
    </li>
@empty
    @if (trim((string) request('q')) === '' && in_array(request('filter'), [null, '', 'all'], true))
        <li class="empty stack-sm center">
            <strong>هنوز فاکتوری ثبت نشده است.</strong>
            <span class="small muted">اولین فاکتور را با نرخ روز طلای ۱۸ عیار بسازید؛ پس از صدور، اینجا دیده می‌شود.</span>
            @if ($tenantContext->membership()?->can('invoice.issue'))<a class="btn btn-gold" href="{{ route('invoices.new') }}">فاکتور جدید</a>@endif
        </li>
    @else
        <li class="empty stack-sm center">
            <strong>با این جستجو فاکتوری پیدا نشد.</strong>
            <a class="btn btn-line" href="{{ route('invoices.index') }}">پاک‌کردن جستجو و نمایش همه</a>
        </li>
    @endif
@endforelse
@if ($page->hasMorePages())
    <li class="center"><button type="button" class="btn btn-line" data-more="{{ $page->nextPageUrl() }}">موارد بیشتر</button></li>
@endif
