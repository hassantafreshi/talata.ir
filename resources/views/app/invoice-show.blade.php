<x-layouts.app :title="'فاکتور '.$v['number']" page="invoice" :back="route('invoices.index')">
    <div class="between">
        <div><strong>فاکتور <span class="num ltr">{{ $v['number'] }}</span></strong> <span class="small muted">{{ $v['issued_fa'] }}</span></div>
        @if ($invoice->status === 'void')<span class="badge err">باطل شده</span>@else<span class="badge ok">قطعی</span>@endif
    </div>
    @if ($invoice->status === 'void')
        <div class="notice err">این فاکتور در {{ $v['voided_fa'] }} باطل شد. دلیل: {{ $v['void_reason_fa'] }}. صفحه بررسی اصالت هم «باطل شده» نشان می‌دهد.
            @if ($replacement)<a href="{{ route($replacement->isDraft() ? 'invoices.items' : 'invoices.show', $replacement) }}">فاکتور جایگزین {{ $replacement->number ? invno($replacement->number) : '(پیش‌نویس)' }}</a>@endif
        </div>
    @endif

    <div class="desk-2">
        <div class="stack">
            <section class="band stack-sm" aria-label="خریدار">
                <dl class="kv">
                    <div><dt>مشتری</dt><dd>{{ $v['buyer_name'] ?: '—' }}</dd></div>
                    <div><dt>موبایل</dt><dd class="num ltr">{{ $v['buyer_mobile'] ?: '—' }}</dd></div>
                </dl>
            </section>
            @include('app.partials.invoice-summary')
        </div>
        <div class="stack">
            <a class="btn btn-gold block" href="{{ route('invoices.print', $invoice) }}" target="_blank" rel="noopener">چاپ / PDF</a>
            @include('app.partials.invoice-actions')
            @if ($invoice->status === 'issued' && $canVoid)
                <section class="band stack-sm" aria-labelledby="void-h">
                    <h2 id="void-h">اشتباه شده؟</h2>
                    <p class="small muted">فاکتور صادرشده ویرایش نمی‌شود. آن را باطل کنید و در صورت نیاز فاکتور جایگزین بسازید.</p>
                    <button type="button" class="btn btn-danger block" data-void>ابطال فاکتور</button>
                </section>
            @elseif ($invoice->status === 'void' && ! $replacement && $canVoid)
                <button type="button" class="btn btn-dark block" data-replace data-busy-text="در حال ساخت…">ساخت فاکتور جایگزین</button>
            @endif
        </div>
    </div>

    <template data-void-tpl>
        <div class="between"><h2>ابطال فاکتور <span class="num ltr">{{ $v['number'] }}</span></h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>
        <form method="post" class="stack" data-void-form novalidate>
            <fieldset class="field"><legend class="label">دلیل ابطال</legend>
                <div class="stack-sm">
                    @foreach (\App\Domain\Invoices\InvoicePresenter::VOID_REASONS as $code => $label)
                        <label class="check"><input type="radio" name="reason" value="{{ $code }}" @checked($loop->first)> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>
            <div class="field"><label for="void-note">توضیح (اختیاری)</label><div class="input-wrap"><input id="void-note" name="note" maxlength="200"></div><div class="err"></div></div>
            <div class="notice warn">ابطال برگشت‌پذیر نیست. شماره فاکتور حفظ می‌شود و سهمیه ماه برنمی‌گردد.</div>
            <button type="submit" class="btn btn-danger block" data-busy-text="در حال ابطال…">ابطال شود</button>
            <button type="button" class="btn btn-line block" data-close>انصراف</button>
        </form>
    </template>
</x-layouts.app>
