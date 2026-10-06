@forelse ($page as $inv)
    @php($sms = $inv->smsMessages->first())
    <li>
        <a class="list-item" href="{{ $inv->status === 'draft' ? route('invoices.items', $inv) : route('invoices.show', $inv) }}">
            <span class="body">
                <strong>@if($inv->status === 'draft')پیش‌نویس@else فاکتور <span class="num ltr">{{ fa($inv->number) }}</span>@endif
                    @if($inv->buyer_name) · {{ $inv->buyer_name }}@endif</strong>
                <span class="sub">{{ $inv->status === 'draft' ? 'ذخیره '.jtime($inv->updated_at) : jdate($inv->issued_at) }} · {{ fa($inv->items_count) }} ردیف
                    @if($sms) · پیامک: {{ \App\Http\Controllers\App\InvoiceController::SMS_STATUS_FA[$sms->status][0] }}@endif</span>
            </span>
            <span class="stack-sm center">
                @if ($inv->payable_irr)<span class="num strong nowrap">{{ toman($inv->payable_irr) }}</span>@endif
                @switch($inv->status)
                    @case('draft')<span class="badge info">پیش‌نویس</span>@break
                    @case('void')<span class="badge err">باطل</span>@break
                    @default<span class="badge ok">قطعی</span>
                @endswitch
            </span>
        </a>
    </li>
@empty
    <li class="empty">فاکتوری پیدا نشد.</li>
@endforelse
@if ($page->hasMorePages())
    <li class="center"><button type="button" class="btn btn-line" data-more="{{ $page->nextPageUrl() }}">موارد بیشتر</button></li>
@endif
