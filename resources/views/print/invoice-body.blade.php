{{-- A4 invoice document from the presenter DTO ($v). Layout comes from the invoice snapshot, never from current settings. --}}
@php
    $L = $v['layout'];
    $shop = $v['shop'];
    $blocks = collect($L['blocks'])->where('visible', true);
    $socialLabels = ['instagram' => 'اینستاگرام', 'telegram' => 'تلگرام', 'whatsapp' => 'واتس‌اپ', 'other' => 'شبکه اجتماعی'];
    $render = function (array $b) use ($shop, $socialLabels) {
        return match ($b['kind']) {
            'shop_name' => null,
            'address' => $shop['address'] ? 'نشانی: '.e($shop['address']) : null,
            'contact_primary' => 'تلفن: <span class="num ltr">'.e($shop['contact_primary']).'</span>',
            'contact_mobile_extra' => ! empty($shop['landline']) ? 'موبایل: <span class="num ltr">'.e(fa($shop['mobile_display'])).'</span>' : null,
            'website' => ! empty($shop['website']) ? 'وب‌سایت: <span class="ltr">'.e($shop['website']).'</span>' : null,
            'social' => collect($shop['socials'] ?? [])->filter(fn ($s) => ! empty($s['handle']))->map(fn ($s) => e($socialLabels[$s['network']] ?? '').': <span class="ltr">'.e($s['handle']).'</span>')->implode(' · ') ?: null,
            'license_union' => ! empty($shop['license_union']) ? 'شماره پروانه کسب: <span class="num">'.e(fa($shop['license_union'])).'</span>' : null,
            'license_online' => ! empty($shop['license_online']) ? 'نماد اعتماد: <span class="num">'.e(fa($shop['license_online'])).'</span>' : null,
            default => null,
        };
    };
    $nameBlock = $blocks->firstWhere('kind', 'shop_name') ?? ['align' => 'right'];
    $colLabels = ['row_no' => 'ردیف', 'name' => 'شرح کالا', 'description' => 'توضیح', 'weight_g' => 'وزن (گرم)', 'purity' => 'عیار', 'weight_750' => 'وزن ۷۵۰', 'unit_rate' => 'نرخ هر گرم', 'wage' => 'اجرت', 'profit' => 'سود', 'vat' => 'مالیات', 'amount' => 'مبلغ (تومان)'];
    $numeric = ['row_no', 'weight_g', 'weight_750', 'unit_rate', 'wage', 'profit', 'vat', 'amount'];
    $cols = $v['columns'];
    $t = $L['typography'] ?? [];
    $classes = 'inv t-'.($t['text_size'] ?? 'normal').' d-'.($t['density'] ?? 'comfortable').' a-'.($t['accent'] ?? 'ink').(($t['dividers'] ?? true) ? '' : ' no-div')
        .' pm-'.(($L['print']['margins'] ?? 'normal') === 'narrow' ? 'narrow' : 'normal').' po-'.(($L['print']['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait');
    $isDraft = ($v['status'] ?? '') === 'draft';
@endphp
<article class="{{ $classes }}" aria-label="فاکتور فروش {{ $v['number'] }}">
    @if (! empty($sample))<span class="sample-stamp">پیش‌نمایش با داده نمونه</span>@endif
    @if (($v['status'] ?? '') === 'void')<div class="void-stamp" aria-hidden="true">باطل شد</div>@endif

    <header class="inv-head">
        <div class="blocks">
            @if (($L['logo']['visible'] ?? false) && ! empty($shop['logo']))
                <div class="logo-wrap al-{{ $nameBlock['align'] }}"><img class="logo s-{{ $L['logo']['size'] ?? 'medium' }}" src="{{ route('public.logo', [$shop['logo']['tenant'], $shop['logo']['version']]) }}" alt="لوگوی {{ $shop['name'] }}"></div>
            @endif
            <div class="shop-name accent al-{{ $nameBlock['align'] }}">{{ $shop['name'] }}</div>
            @foreach ($blocks->where('area', 'header') as $b)
                @php $html = $render($b); @endphp
                @if ($html)<div class="al-{{ $b['align'] }}">{!! $html !!}</div>@endif
            @endforeach
            <div class="meta-line">
                <span><strong>فاکتور فروش</strong> شماره <strong class="num ltr">{{ $v['number'] }}</strong></span>
                <span>تاریخ: <span class="num">{{ $v['issued_fa'] }}</span></span>
                @if (($v['status'] ?? '') === 'void')<span><strong>باطل‌شده</strong> در {{ $v['voided_fa'] }}</span>@endif
            </div>
        </div>
        <div class="qr-box">
            @if ($isDraft)
                <div class="qr-draft">پیش‌نویس</div>
            @else
                <div class="t">بررسی اصالت فاکتور</div>
                {!! $qr !!}
                <div class="num ltr">{{ $v['number'] }}</div>
                <div class="ltr">{{ $verifyShort }}</div>
            @endif
        </div>
    </header>

    <div class="inv-buyer">
        <span>خریدار: <strong>{{ $v['buyer_name'] ?: '—' }}</strong></span>
        @if ($v['buyer_mobile'])<span>موبایل: <span class="num ltr">{{ $v['buyer_mobile'] }}</span></span>@endif
        @if (! empty($v['buyer_national_id']))<span>کد ملی: <span class="num ltr">{{ $v['buyer_national_id'] }}</span></span>@endif
        @if ($v['rate_fa'])<span>نرخ هر گرم طلای ۱۸ عیار: <span class="num">{{ $v['rate_fa'] }}</span> تومان @if($v['rate_manual'])(نرخ دستی)@elseif($v['rate_emergency'] ?? false)(نرخ اعلامی زرلیو)@endif</span>@endif
    </div>

    @if ($v['use_ledger'] ?? false)
        @php $L2 = $v['ledger']; $side = \App\Domain\Invoices\InvoicePresenter::SIDE_FA; $sideShort = \App\Domain\Invoices\InvoicePresenter::SIDE_SHORT; @endphp
        {{-- «حساب طلا و ریال»: bazaar-style debit/credit table from the customer's account (بد = مشتری بدهکار، بس = مشتری بستانکار). --}}
        <div class="table-scroll">
            <table class="ledger cols-many" aria-label="جدول بد و بس طلا و مبلغ">
                <thead>
                    <tr><th class="n" scope="col">ردیف</th><th scope="col">شرح</th><th scope="col">عیار</th><th class="n" scope="col">وزن</th><th class="n" scope="col">وزن ۷۵۰</th><th class="n" scope="col">فی (هر گرم ۱۸)</th>
                        <th class="n" scope="col">طلا (گرم ۷۵۰) بد/بس</th><th class="n" scope="col">مبلغ (تومان) بد/بس</th></tr>
                </thead>
                <tbody>
                    @foreach ($v['rows'] as $r)
                        <tr class="{{ ($r['direction'] ?? 'OUT') === 'IN' ? 'row-in' : '' }}">
                            <td class="n">{{ $r['no'] }}</td>
                            <td>@if(($r['direction'] ?? 'OUT') === 'IN')<span class="in-tag">دریافتی</span> @endif{{ $r['name'] }}
                                @if($r['by_weight'] ?? false)<span class="desc">{{ $r['type'] === 'GOLD' ? 'تسویه وزنی؛ اجرت، سود و مالیات نقدی' : 'حساب وزنی (بدون تبدیل به پول)' }}</span>@endif
                                @if($r['description'])<span class="desc">{{ $r['description'] }}</span>@endif
                                @if(! empty($r['deduction_percent']))<span class="desc">کسر ذوب/ناخالصی {{ $r['deduction_percent'] }}</span>@endif
                                @if(! empty($r['assay_ref']))<span class="desc">برگه عیارسنجی {{ $r['assay_ref'] }}</span>@endif</td>
                            <td>{{ $r['purity_short'] ?? '—' }}</td>
                            <td class="n">{{ in_array($r['type'], ['GOLD', 'GOLD_IN'], true) ? $r['weight'] : '—' }}</td>
                            <td class="n">{{ $r['weight_750'] ?? '—' }}</td>
                            <td class="n">{{ $r['unit_rate'] }}</td>
                            <td class="n side"><strong>{{ $r['gold_cell'] ?? '—' }}</strong></td>
                            <td class="n side"><strong>{{ $r['money_cell'] ?? '—' }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><th colspan="6" scope="row">جمع</th><td class="n side">{{ $L2['gold_total_cell'] }}</td><td class="n side">{{ $L2['money_total_cell'] }}</td></tr>
                    <tr class="bal"><th colspan="6" scope="row">مانده سند · <span class="num">{{ $v['issued_fa'] }}</span></th>
                        <td class="n side">{{ $L2['gold_side'] === 'ZERO' ? 'تسویه' : $sideShort[$L2['gold_side']].' '.$L2['gold_balance'].' گرم طلای ۱۸ عیار' }}</td>
                        <td class="n side">{{ $L2['money_side'] === 'ZERO' ? 'تسویه' : $sideShort[$L2['money_side']].' '.$L2['money_balance'].' تومان' }}</td></tr>
                </tfoot>
            </table>
        </div>
        <section class="inv-split" aria-label="مانده سند">
            <dl><div class="h"><dt>مانده سند (طلا)</dt><dd class="num">{{ $v['issued_fa'] }}</dd></div><div class="t"><dt>{{ $side[$L2['gold_side']] }}</dt><dd>{{ $L2['gold_balance'] }} گرم ۱۸ عیار</dd></div></dl>
            <dl><div class="h"><dt>مانده سند (مبلغ)</dt><dd class="num">{{ $v['issued_fa'] }}</dd></div><div class="t"><dt>{{ $side[$L2['money_side']] }}</dt><dd>{{ $L2['money_balance'] }} تومان</dd></div></dl>
        </section>
    @else
        <div class="table-scroll">
            <table class="{{ count($cols) >= 9 ? 'cols-many' : '' }}">
                <thead><tr>@foreach ($cols as $c)<th class="c-{{ $c }} {{ in_array($c, $numeric, true) ? 'n' : '' }}" scope="col">{{ $colLabels[$c] }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($v['rows'] as $r)
                        <tr class="{{ ($r['direction'] ?? 'OUT') === 'IN' ? 'row-in' : '' }}">
                            @foreach ($cols as $c)
                                @switch($c)
                                    @case('row_no')<td class="c-row_no n">{{ $r['no'] }}</td>@break
                                    @case('name')<td class="c-name">@if(($r['direction'] ?? 'OUT') === 'IN')<span class="in-tag">دریافتی</span> @endif{{ $r['name'] }}@if(! in_array('description', $cols, true) && $r['description'])<span class="desc">{{ $r['description'] }}</span>@endif
                                        @if(! empty($r['deduction_percent']) || ! empty($r['assay_ref']))<span class="desc">@if(! empty($r['deduction_percent']))کسر ذوب/ناخالصی {{ $r['deduction_percent'] }} ({{ $r['deduction_fa'] }} تومان)@endif @if(! empty($r['assay_ref'])) · برگه عیارسنجی {{ $r['assay_ref'] }}@endif</span>@endif</td>@break
                                    @case('description')<td class="c-description">{{ $r['description'] ?: '—' }}</td>@break
                                    @case('weight_g')<td class="n">{{ in_array($r['type'], ['GOLD', 'GOLD_IN'], true) ? $r['weight'] : '—' }}</td>@break
                                    @case('purity')<td>{{ ($v['has_gold_in'] ?? false) ? ($r['purity_short'] ?? $r['purity']) : $r['purity'] }}</td>@break
                                    @case('weight_750')<td class="n">{{ $r['weight_750'] ?? '—' }}</td>@break
                                    @case('unit_rate')<td class="n">{{ $r['unit_rate'] }}</td>@break
                                    @case('wage')<td class="n">{{ $r['wage'] }}</td>@break
                                    @case('profit')<td class="n">{{ $r['profit'] }}</td>@break
                                    @case('vat')<td class="n">{{ $r['vat'] }}</td>@break
                                    @case('amount')<td class="n"><strong>{{ $r['amount'] }}</strong></td>@break
                                @endswitch
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (($v['has_gold_in'] ?? false) && ! ($v['use_ledger'] ?? false))
        {{-- Tahesab-style split: gold ledger (750-equivalent grams) and money ledger (toman). --}}
        <section class="inv-split" aria-label="تفکیک طلایی و مبلغ">
            <dl aria-label="تفکیک طلایی">
                <div class="h"><dt>تفکیک طلایی</dt><dd>گرم (معادل ۷۵۰)</dd></div>
                <div><dt>طلای فروخته‌شده</dt><dd>{{ $v['w750']['out'] }}</dd></div>
                <div><dt>طلای دریافتی از مشتری</dt><dd>−{{ $v['w750']['in'] }}</dd></div>
                <div class="t"><dt>{{ $v['w750']['net_label'] }}</dt><dd>{{ $v['w750']['net'] }}</dd></div>
            </dl>
            <dl aria-label="تفکیک ریالی">
                <div class="h"><dt>تفکیک مبلغ</dt><dd>تومان</dd></div>
                <div><dt>جمع فروش</dt><dd>{{ $v['sales_fa'] }}</dd></div>
                <div><dt>ارزش طلای دریافتی</dt><dd>−{{ $v['gold_in_fa'] }}</dd></div>
                @if ($v['gold_in_has_deduction'])<div class="sub"><dt>(کسر ذوب/ناخالصی اعمال‌شده)</dt><dd>{{ $v['gold_in_deduction_fa'] }}</dd></div>@endif
                <div class="t"><dt>{{ $v['payable_label'] }}</dt><dd>{{ $v['payable_fa'] }}</dd></div>
            </dl>
        </section>
    @endif
    <section class="inv-sum">
        <div class="inv-notes">
            @if ($L['summary']['public_note']['visible'] ?? false)<p>{{ $L['summary']['public_note']['text'] }}</p>@endif
            <p>مبالغ به تومان است. @if($v['has_gold'])مالیات بر ارزش افزوده فقط روی اجرت و سود محاسبه شده است.@endif @if($v['has_gold_in'] ?? false)<br>ردیف‌های «دریافتی» طلایی است که مشتری به‌جای پول داده و از مبلغ فاکتور کسر شده است؛ وزن ۷۵۰ یعنی وزن معادل طلای ۱۸ عیار.@endif</p>
            @if ($v['issuer'])<p>صادرکننده: {{ $v['issuer'] }}</p>@endif
        </div>
        <div>
            @if ($L['summary']['show_component_breakdown'] ?? true)
                <dl>
                    @if ($v['has_gold'])
                        <div><dt>وزن کل طلا</dt><dd>{{ $v['weight_total'] }} گرم</dd></div>
                        <div><dt>ارزش طلا</dt><dd>@if(($v['ledger']['by_weight'] ?? false) && $v['metal_fa'] === '۰')با طلا تسویه شد (وزنی)@else{{ $v['metal_fa'] }}@endif</dd></div>
                        <div><dt>اجرت</dt><dd>{{ $v['wage_fa'] }}</dd></div>
                        <div><dt>سود</dt><dd>{{ $v['profit_fa'] }}</dd></div>
                        <div><dt>مالیات ({{ $v['tax_rate_fa'] }}٪)</dt><dd>{{ $v['vat_fa'] }}</dd></div>
                    @endif
                    @if ($v['has_misc'])<div><dt>اقلام متفرقه</dt><dd>{{ $v['misc_total_fa'] }}</dd></div>@endif
                </dl>
            @endif
            <div class="payable {{ ($v['customer_credit'] ?? false) ? 'credit' : '' }}"><span>{{ $v['payable_label'] ?? 'قابل پرداخت' }}</span><span class="num">{{ $v['payable_fa'] }} تومان</span></div>
        </div>
    </section>

    @if ($L['summary']['signature_box'] ?? true)
        <div class="inv-sign">@if(($L['template_id'] ?? '') === 'ledger')<div>صادرکننده سند (مهر و امضا)</div><div>گیرنده سند (امضای خریدار)</div>@else<div>مهر و امضای فروشنده</div><div>امضای خریدار</div>@endif</div>
    @endif

    @php $footer = $blocks->where('area', 'footer')->map(fn ($b) => ['align' => $b['align'], 'html' => $render($b)])->filter(fn ($x) => $x['html']); @endphp
    @if ($footer->isNotEmpty())
        <footer class="inv-foot">@foreach ($footer as $f)<div class="al-{{ $f['align'] }}">{!! $f['html'] !!}</div>@endforeach</footer>
    @endif
    @if ($v['show_talata_mark'])<div class="mark">صادرشده با زرلیو · zarlio.ir</div>@endif
</article>
