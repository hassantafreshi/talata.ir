@php($A = \App\Http\Controllers\Admin\QuotesController::ASSETS_FA)
<x-layouts.admin title="نرخ و مظنه" page="admin-ops" description="دریافت مرکزی هر ۱۸۰ ثانیه برای همه فروشگاه‌ها. فاکتورهای صادرشده با نرخ ثبت‌شده خودشان می‌مانند.">
    @if ($canManage)
        <x-slot:actions><button class="btn sm btn-line" type="button" data-post="{{ route('admin.quotes.refresh') }}" data-reload>دریافت دوباره الان</button></x-slot:actions>
    @endif
    <div class="detail-grid">
        <section class="stack-sm">
            <div class="table-wrap"><table class="t">
                <thead><tr><th>دارایی</th><th class="n">مقدار</th><th>واحد</th><th class="n">تغییر</th><th>تازگی</th><th>منبع</th><th>دریافت</th></tr></thead>
                <tbody>
                @foreach ($rows as $asset => $r)
                    @php($q = $r['q'])
                    <tr>
                        <td>{{ $A[$asset] }} <span class="mono">{{ $asset }}</span></td>
                        <td class="n">@if($q){{ $asset === 'XAU_USD' ? fa(number_format((float) $q->value, 2)) : toman((string) \Brick\Math\BigDecimal::of($q->value)->toScale(0, \Brick\Math\RoundingMode::HalfUp)) }}@else — @endif</td>
                        <td>{{ $asset === 'XAU_USD' ? 'دلار/اونس' : 'تومان' }}</td>
                        <td class="n">{{ $q && $q->change_vs_previous_pct !== null ? pct(number_format((float) $q->change_vs_previous_pct, 2, '.', '')).'٪' : '—' }}</td>
                        <td>@include('partials.freshness', ['f' => $r['freshness']])</td>
                        <td class="small">{{ $q?->source ?? '—' }}@if($q?->is_demo) <span class="badge dark">نمونه</span>@endif</td>
                        <td class="n small">{{ $q ? jdate($q->fetched_at, true) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <section class="band stack-sm">
                <h2>آخرین دریافت‌ها</h2>
                <table class="t"><tbody>
                    @forelse ($fetches as $f)
                        <tr class="lvl-{{ $f->level }}"><td class="n small">{{ jdate(\Carbon\CarbonImmutable::parse($f->created_at), true) }}</td><td><span class="badge {{ $f->level === 'info' ? 'ok' : ($f->level === 'warning' ? 'warn' : 'err') }}">{{ $f->level === 'info' ? 'موفق' : 'ناموفق' }}</span></td><td class="small">{{ $f->message }}</td></tr>
                    @empty
                        <tr><td class="muted">هنوز دریافتی ثبت نشده است.</td></tr>
                    @endforelse
                </tbody></table>
                <p class="xs muted">ارائه‌دهنده: <span class="mono">{{ $driver }}</span> · فاصله دریافت الان {{ fa(\App\Domain\Market\QuoteSchedule::intervalSeconds(now())) }} ثانیه (بسته به ساعت تهران)@if($lastError) · آخرین خطا: {{ jdate(\Carbon\CarbonImmutable::parse($lastError), true) }}@endif</p>
            </section>
        </section>

        <section class="stack-sm">
            <div class="action-card stack-sm">
                <h3>افزایش ثابت قیمت طلا</h3>
                <p class="small">به قیمت هر گرم طلا (۱۸ فروش، ۱۸ خرید و ۲۴ عیار) که از سرویس نرخ می‌رسد اضافه می‌شود: <strong class="num">{{ fa(number_format($markupToman)) }} تومان</strong>. از دریافت بعدی اعمال می‌شود؛ نرخ اضطراری و فاکتورهای صادرشده تغییر نمی‌کنند.</p>
                @if ($canManage)
                    <form class="stack-sm" data-action="{{ route('admin.quotes.markup') }}" data-idem data-reload data-confirm="افزایش قیمت طلا «{markup_toman}» تومان شود؟">
                        <div class="field"><label for="gm">مبلغ (تومان برای هر گرم)</label><input id="gm" name="markup_toman" class="ltr-input num" inputmode="numeric" maxlength="20" value="{{ $markupToman }}" required></div>
                        <div class="field"><label for="gmr">دلیل</label><textarea id="gmr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                        <button class="btn btn-dark sm" type="submit">ذخیره</button>
                    </form>
                @endif
                <details class="xs muted"><summary>زمان‌بندی دریافت نرخ (ساعت تهران)</summary>
                    <ul>@foreach ($schedule as [$from, $to, $sec])<li>{{ fa(sprintf('%02d:00', $from)) }} تا {{ fa(sprintf('%02d:00', $to)) }}: هر {{ fa($sec >= 60 ? intdiv($sec, 60).' دقیقه' : $sec.' ثانیه') }} · {{ fa(\App\Domain\Market\QuoteSchedule::requestsPerDay($from, $to, $sec)) }} درخواست</li>@endforeach</ul>
                </details>
            </div>
            <div class="action-card stack-sm {{ $emergency ? 'danger' : '' }}">
                <h3>نرخ اضطراری ۱۸ عیار (فروش)</h3>
                @if ($emergency)
                    <p><span class="badge warn">فعال</span> <strong>{{ toman($emergency->value_irr) }} تومان</strong> · از {{ jdate($emergency->starts_at, true) }} {{ $emergency->ends_at ? 'تا '.jdate($emergency->ends_at, true) : 'تا لغو دستی' }}</p>
                    <p class="small">دلیل: {{ $emergency->reason }}</p>
                    @if ($canManage)
                        <form class="stack-sm" data-action="{{ route('admin.quotes.emergency.cancel') }}" data-reload data-confirm="نرخ اعلامی لغو شود و نرخ سرویس دوباره به فروشگاه‌ها نمایش داده شود؟">
                            <div class="field"><label for="cx">دلیل لغو</label><input id="cx" name="reason" required minlength="5" maxlength="250"></div>
                            <button class="btn btn-line" type="submit">لغو نرخ اعلامی</button>
                        </form>
                    @endif
                @else
                    <p class="small muted">وقتی سرویس نرخ قطع یا اشتباه است. فروشگاه‌ها آن را با برچسب «نرخ اعلامی زرلیو (دستی)» می‌بینند و همچنان می‌توانند نرخ دستی خودشان را ثبت کنند.</p>
                @endif
                @if ($canManage)
                    <form class="stack-sm" data-action="{{ route('admin.quotes.emergency') }}" data-idem data-reload
                          data-confirm="نرخ {value_toman} تومان برای {validity} به همه فروشگاه‌ها نمایش داده شود؟ فاکتورهای صادرشده تغییر نمی‌کنند.">
                        <div class="field"><label for="ev">نرخ هر گرم ۱۸ عیار (تومان)</label><input id="ev" name="value_toman" required inputmode="numeric" class="ltr-input"></div>
                        <div class="field"><label for="ed">مدت اعتبار</label><select id="ed" name="validity">@foreach ($validity as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
                        <div class="field"><label for="er">دلیل</label><textarea id="er" name="reason" required minlength="5" maxlength="250"></textarea></div>
                        <button class="btn btn-gold" type="submit">{{ $emergency ? 'جایگزینی نرخ اعلامی' : 'انتشار نرخ اعلامی' }}</button>
                    </form>
                @endif
            </div>
            <section class="band stack-sm">
                <h2>سابقه نرخ‌های اعلامی</h2>
                <ul class="list">
                    @forelse ($history as $h)
                        <li class="list-item"><span class="body"><strong>{{ toman($h->value_irr) }} تومان</strong>
                            <span class="sub">{{ jdate($h->starts_at, true) }} · {{ $staffNames[$h->created_by_staff] ?? '—' }} · {{ $h->cancelled_at ? 'لغو '.jdate($h->cancelled_at, true) : ($h->isActive() ? 'فعال' : 'پایان اعتبار') }}</span>
                            <span class="sub">{{ $h->reason }}</span></span></li>
                    @empty
                        <li class="muted small">تاکنون نرخ اعلامی ثبت نشده است.</li>
                    @endforelse
                </ul>
            </section>
        </section>
    </div>
</x-layouts.admin>
