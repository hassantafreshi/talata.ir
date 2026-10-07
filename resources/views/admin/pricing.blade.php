@php
    $boot = ['current' => $current, 'vat' => $vat];
    $t = fn ($toman) => toman((int) $toman * 10);
@endphp
<x-layouts.admin title="قیمت پلن‌ها و پیامک" page="admin-pricing">
    <script type="application/json" id="boot">@json($boot)</script>
    <p class="small muted">هر تغییر یک <strong>نسخه قیمت جدید</strong> منتشر می‌کند (نسخه فعلی: {{ fa($current['version']) }}). سفارش‌های پرداخت‌شده یا در حال پرداخت با همان مبلغ قبلی خودشان تسویه می‌شوند و هیچ فاکتوری تغییر نمی‌کند. قیمت‌ها بدون مالیات‌اند؛ مالیات {{ fa($vat) }}٪ هنگام پرداخت اضافه می‌شود. پلن رایگان همیشه صفر است.</p>

    <form class="stack" data-pricing-form novalidate>
        <div class="pricing-grid">
            @foreach ($current['plans'] as $code => $p)
                <section class="band white stack-sm" aria-labelledby="pl-{{ $code }}">
                    <h2 id="pl-{{ $code }}">پلن {{ $p['label_fa'] }}</h2>
                    @foreach (['monthly' => 'ماهانه', 'yearly' => 'سالانه'] as $period => $label)
                        <div class="field">
                            <label for="p-{{ $code }}-{{ $period }}">قیمت {{ $label }} (تومان، بدون مالیات)</label>
                            <div class="input-wrap ltr-input"><input id="p-{{ $code }}-{{ $period }}" name="plans.{{ $code }}.{{ $period }}" inputmode="numeric" value="{{ $p[$period] }}" data-old="{{ $p[$period] }}" data-money @disabled(! $canEdit)><span class="unit">تومان</span></div>
                            <div class="err"></div>
                            <p class="hint" data-preview>فعلی: {{ $t($p[$period]) }} · با مالیات: <span data-vat></span></p>
                        </div>
                    @endforeach
                    <p class="xs muted" data-yearly-note></p>
                </section>
            @endforeach
            <section class="band white stack-sm" aria-labelledby="sms-h">
                <h2 id="sms-h">قیمت هر بخش پیامک</h2>
                <p class="xs muted">اعتبار پیامک به‌ازای هر بخش (۷۰ نویسه فارسی) از کیف اعتبار کم می‌شود.</p>
                @foreach ($current['sms'] as $code => $s)
                    <div class="field">
                        <label for="s-{{ $code }}">پلن {{ $s['label_fa'] }}</label>
                        <div class="input-wrap ltr-input"><input id="s-{{ $code }}" name="sms.{{ $code }}" inputmode="numeric" value="{{ $s['per_segment'] }}" data-old="{{ $s['per_segment'] }}" data-money @disabled(! $canEdit)><span class="unit">تومان</span></div>
                        <div class="err"></div>
                        <p class="hint" data-preview>فعلی: {{ $t($s['per_segment']) }} · پیامک ۲ بخشی: <span data-two></span></p>
                    </div>
                @endforeach
            </section>
        </div>
        @if ($canEdit)
            <section class="band stack-sm" aria-labelledby="ch-h">
                <h2 id="ch-h">تغییرات</h2>
                <ul class="list" data-changes><li class="muted small">هنوز چیزی تغییر نکرده است.</li></ul>
                <div class="field"><label for="p-note">یادداشت (دلیل تغییر، اختیاری)</label><div class="input-wrap"><input id="p-note" name="note" maxlength="250"></div></div>
                <button class="btn btn-gold block" type="submit" data-busy-text="در حال انتشار…" disabled>انتشار قیمت‌های جدید</button>
            </section>
        @else
            <div class="notice info">فقط مدیر ارشد می‌تواند قیمت‌ها را تغییر دهد.</div>
        @endif
    </form>

    <h2>تاریخچه نسخه‌های قیمت</h2>
    <div class="table-wrap"><table class="t">
        <thead><tr><th class="n">نسخه</th><th>زمان</th><th>توسط</th><th class="n">پایه ماهانه</th><th class="n">حرفه‌ای ماهانه</th><th class="n">پیامک (رایگان/پایه/حرفه‌ای)</th><th>یادداشت</th>@if($canEdit)<th></th>@endif</tr></thead>
        <tbody>
        @foreach ($history as $h)
            @php $pp = $h->payload; $sm = $pp['sms_credit']['per_segment_toman'] ?? []; @endphp
            <tr>
                <td class="n">{{ fa($h->version) }} @if($h->version === $current['version'])<span class="badge ok">فعال</span>@endif</td>
                <td>{{ jdate($h->effective_from, true) }}</td>
                <td>{{ $authors[$h->created_by_staff] ?? 'سامانه' }}</td>
                <td class="n">{{ $t($pp['plans']['basic']['price_toman']['monthly'] ?? 0) }}</td>
                <td class="n">{{ $t($pp['plans']['professional']['price_toman']['monthly'] ?? 0) }}</td>
                <td class="n">{{ fa(($sm['free'] ?? '—').' / '.($sm['basic'] ?? '—').' / '.($sm['professional'] ?? '—')) }}</td>
                <td class="small">{{ $h->note }}</td>
                @if($canEdit)<td>@if($h->version !== $current['version'])<button type="button" class="btn sm btn-line" data-restore="{{ $h->id }}" data-version="{{ $h->version }}">بازگرداندن</button>@endif</td>@endif
            </tr>
        @endforeach
        </tbody>
    </table></div>

    <section class="stack-sm" aria-labelledby="promo-h">
        <h2 id="promo-h">کدهای تخفیف سرویس</h2>
        <p class="small muted">جدا از کد معرف همکاران. درصد از مبلغ بدون مالیات کم می‌شود؛ هر فروشگاه هر کد را یک بار. کد ۱۰۰٪ خرید را بدون رفتن به بانک کامل می‌کند (مناسب آزمایش پیش از اتصال درگاه).</p>
        @if ($canEdit)
            <details class="action-card">
                <summary><h3>ساخت کد تخفیف</h3></summary>
                <form class="stack-sm" data-action="{{ route('admin.promo.store') }}" data-idem data-reload data-confirm="کد {code} با {percent}٪ تخفیف ساخته شود؟">
                    <div class="form-grid">
                        <div class="field"><label for="pc">کد (حرف و رقم انگلیسی)</label><input id="pc" name="code" required maxlength="20" class="ltr-input" placeholder="TEST100"></div>
                        <div class="field"><label for="pp">درصد تخفیف</label><input id="pp" name="percent" required inputmode="decimal" class="ltr-input" value="100"></div>
                        <fieldset class="field"><legend class="label">برای</legend>
                            <label class="check"><input type="checkbox" name="product_plan" checked> خرید پلن</label>
                            <label class="check"><input type="checkbox" name="product_sms"> شارژ پیامک</label></fieldset>
                        <div class="field"><label for="pm">حداکثر دفعات (خالی = نامحدود)</label><input id="pm" name="max_uses" inputmode="numeric" class="ltr-input" value="20"></div>
                        <div class="field"><label for="pd">اعتبار (روز؛ خالی = همیشه)</label><input id="pd" name="days" inputmode="numeric" class="ltr-input" value="30"></div>
                        <div class="field"><label for="pn">یادداشت</label><input id="pn" name="note" maxlength="200"></div>
                        <div class="field wide"><label for="pr">دلیل</label><textarea id="pr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                    </div>
                    <button class="btn btn-dark" type="submit">ساخت کد</button>
                </form>
            </details>
        @endif
        <div class="table-wrap"><table class="data">
            <thead><tr><th>کد</th><th>درصد</th><th>برای</th><th>استفاده</th><th>تا</th><th>وضعیت</th><th>یادداشت</th>@if($canEdit)<th></th>@endif</tr></thead>
            <tbody>
            @forelse ($promos as $p)
                <tr>
                    <td class="mono">{{ $p->code }}</td><td class="n">{{ fa(rtrim(rtrim($p->percent, '0'), '.')) }}٪</td>
                    <td class="small">{{ collect($p->products)->map(fn ($x) => \App\Domain\Billing\PromoCodes::PRODUCTS[$x] ?? $x)->implode('، ') }}</td>
                    <td class="n">{{ fa($promoUses[$p->id] ?? 0) }}{{ $p->max_uses ? ' از '.fa($p->max_uses) : '' }}</td>
                    <td>{{ $p->expires_at ? jdate($p->expires_at) : '—' }}</td>
                    <td>@if($p->active && (! $p->expires_at || $p->expires_at->isFuture()))<span class="badge ok">فعال</span>@else<span class="badge off">غیرفعال</span>@endif</td>
                    <td class="small">{{ $p->note }}</td>
                    @if($canEdit)<td>@if($p->active)<form data-action="{{ route('admin.promo.deactivate', $p->id) }}" data-reload data-confirm="کد {{ $p->code }} غیرفعال شود؟" class="cluster"><input name="reason" required minlength="5" maxlength="250" placeholder="دلیل" aria-label="دلیل غیرفعال کردن"><button class="btn sm btn-line" type="submit">غیرفعال</button></form>@endif</td>@endif
                </tr>
            @empty
                <tr><td colspan="8" class="muted">هنوز کدی ساخته نشده است.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layouts.admin>
