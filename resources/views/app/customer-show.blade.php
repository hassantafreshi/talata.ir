@php
    $methods = ['cash' => 'نقد', 'pos' => 'کارت‌خوان', 'card_transfer' => 'کارت به کارت', 'other' => 'سایر'];
    $todayJ = jymd();
@endphp
<x-layouts.app :title="$customer->name" page="customer" :back="route('customers.index')">
    @php $bootData = ['id' => $customer->public_id, 'name' => $customer->name, 'mobile' => $customer->mobile ? \App\Support\Mobile::display($customer->mobile) : '', 'note' => $customer->note]; @endphp
    <script type="application/json" id="boot">@json($bootData)</script>
    <section class="band stack-sm">
        <div class="between"><h2>{{ $customer->name }}</h2>@if($canManage)<button type="button" class="btn btn-link sm" data-edit>ویرایش</button>@endif</div>
        <dl class="kv">
            <div><dt>موبایل</dt><dd class="num ltr">{{ $customer->mobile ? \App\Support\Mobile::display($customer->mobile) : '—' }}</dd></div>
            @if ($customer->note)<div><dt>یادداشت داخلی</dt><dd>{{ $customer->note }}</dd></div>@endif
            <div><dt>ثبت</dt><dd class="num">{{ jdate($customer->created_at) }}</dd></div>
        </dl>
    </section>

    <section class="stack" aria-labelledby="ag-h">
        <div class="between"><h2 id="ag-h">اقساط</h2>
            @if ($canInstallments && $canManage)<a class="btn btn-gold sm" href="{{ route('agreements.create', $customer) }}">+ قرارداد اقساط</a>@endif</div>
        @unless ($canInstallments)
            <div class="notice info">اقساط و یادآوری پیامکی در پلن حرفه‌ای است. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></div>
        @endunless
        @forelse ($agreements as $a)
            @php
                $paid = $a->lines->sum(fn ($l) => (int) $l->paid_irr);
                $total = $a->lines->sum(fn ($l) => (int) $l->amount_irr);
            @endphp
            <article class="band stack-sm" data-agreement="{{ $a->public_id }}">
                <div class="between">
                    <strong>@if($a->invoice)فاکتور <span class="num ltr">{{ invno($a->invoice->number) }}</span>@else بدون فاکتور@endif · {{ fa($a->count) }} قسط {{ $a->frequency === 'weekly' ? 'هفتگی' : 'ماهانه' }}</strong>
                    @switch($a->status)
                        @case('completed')<span class="badge ok">تسویه شد</span>@break
                        @case('cancelled')<span class="badge off">لغو شد</span>@break
                        @default<span class="badge info">فعال</span>
                    @endswitch
                </div>
                <div class="between small"><span>پرداخت‌شده: <span class="num">{{ toman((string) $paid) }}</span> از <span class="num">{{ toman((string) $total) }}</span> تومان</span></div>
                <progress class="meter" max="{{ max($total, 1) }}" value="{{ $paid }}" aria-label="پیشرفت پرداخت"></progress>
                <div class="table-wrap">
                    <table class="t">
                        <thead><tr><th scope="col">قسط</th><th scope="col">سررسید</th><th scope="col">مبلغ</th><th scope="col">وضعیت</th></tr></thead>
                        <tbody>
                            @foreach ($a->lines as $l)
                                @php $rem = (int) $l->amount_irr - (int) $l->paid_irr; @endphp
                                @php $overdue = $rem > 0 && $l->due_date->lt(now($tz)->startOfDay()); @endphp
                                <tr><td class="num">{{ fa($l->number) }}</td><td class="num">{{ jdate($l->due_date) }}</td><td class="num">{{ toman($l->amount_irr) }}</td>
                                    <td>@if($rem <= 0)<span class="badge ok">پرداخت شد</span>@elseif($overdue)<span class="badge err">معوق</span>@elseif((int) $l->paid_irr > 0)<span class="badge warn">ناقص</span>@else<span class="badge off">در انتظار</span>@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($a->payments->isNotEmpty())
                    <details><summary class="small">پرداخت‌های ثبت‌شده ({{ fa($a->payments->count()) }})</summary>
                        <ul class="list">
                            @foreach ($a->payments->sortByDesc('id') as $p)
                                <li class="list-item"><span class="body"><strong class="num">{{ toman($p->amount_irr) }} تومان</strong><span class="sub">{{ jdate($p->paid_on) }} · {{ $methods[$p->method] ?? $p->method }}@if($p->reference) · {{ $p->reference }}@endif @if($p->reversed_at) · برگشت‌خورده: {{ $p->reversal_reason }}@endif</span></span>
                                    @if (! $p->reversed_at && $canManage && $canInstallments)<button type="button" class="btn btn-link sm" data-reverse="{{ $p->public_id }}">برگشت</button>@endif</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if ($a->status === 'active' && $canInstallments && $canManage)
                    <div class="cluster">
                        <button type="button" class="btn btn-dark sm" data-pay="{{ $a->public_id }}">ثبت دریافت</button>
                        <label class="check small"><input type="checkbox" data-reminders="{{ $a->public_id }}" @checked($a->reminders_enabled) @disabled(! $a->reminder_mobile || $customer->sms_opt_out)> یادآوری پیامکی سررسید</label>
                    </div>
                @endif
            </article>
        @empty
            <p class="empty">قرارداد اقساطی ثبت نشده است.</p>
        @endforelse
    </section>

    <section class="stack-sm" aria-labelledby="inv-h">
        <h2 id="inv-h">فاکتورها</h2>
        <ul class="list">
            @forelse ($invoices as $inv)
                <li><a class="list-item" href="{{ route('invoices.show', $inv) }}"><span class="body"><strong>فاکتور <span class="num ltr">{{ invno($inv->number) }}</span></strong><span class="sub">{{ jdate($inv->issued_at) }}</span></span><span class="num">{{ toman($inv->payable_irr) }}</span>@if($inv->status === 'void')<span class="badge err">باطل</span>@endif</a></li>
            @empty
                <li class="empty">فاکتوری برای این مشتری ثبت نشده است.</li>
            @endforelse
        </ul>
    </section>

    <template data-customer-tpl>
        <div class="between"><h2 data-title>ویرایش مشتری</h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>
        <form method="post" class="stack" data-customer-form novalidate>
            <div class="field"><label for="cu-name">نام</label><div class="input-wrap"><input id="cu-name" name="name" maxlength="80" required></div><div class="err"></div></div>
            <div class="field"><label for="cu-mobile">موبایل (اختیاری)</label><div class="input-wrap ltr-input"><input id="cu-mobile" name="mobile" inputmode="tel" maxlength="14" data-digits></div><div class="err"></div></div>
            <div class="field"><label for="cu-note">یادداشت داخلی (اختیاری)</label><div class="input-wrap"><input id="cu-note" name="note" maxlength="250"></div><div class="err"></div></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="در حال ذخیره…">ذخیره</button>
        </form>
    </template>

    <template data-pay-tpl>
        <div class="between"><h2>ثبت دریافت قسط</h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>
        <form method="post" class="stack" data-pay-form novalidate>
            <div class="field"><label for="p-amount">مبلغ دریافتی</label><div class="input-wrap ltr-input"><input id="p-amount" name="amount_toman" inputmode="numeric" required data-digits><span class="unit">تومان</span></div><div class="err"></div><p class="hint">از قدیمی‌ترین قسط پرداخت‌نشده کم می‌شود.</p></div>
            <div class="field" data-jdp data-max="{{ $todayJ }}" data-quick="today" data-required><span class="label" id="p-date-l">تاریخ دریافت</span><div class="input-wrap"><input type="hidden" name="paid_on" value="{{ $todayJ }}"></div><div class="err"></div></div>
            <fieldset class="field"><legend class="label">روش</legend>
                <div class="chips">@foreach ($methods as $k => $label)<label class="chip"><input type="radio" name="method" value="{{ $k }}" @checked($loop->first)>{{ $label }}</label>@endforeach</div><div class="err"></div>
            </fieldset>
            <div class="field"><label for="p-ref">شماره پیگیری (اختیاری)</label><div class="input-wrap"><input id="p-ref" name="reference" maxlength="60"></div></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="در حال ثبت…">ثبت دریافت</button>
        </form>
    </template>
</x-layouts.app>
