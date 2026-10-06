@php($ST = \App\Http\Controllers\Admin\TaxController::STATE_FA)
<x-layouts.admin title="قواعد مالیات" page="admin-ops" description="نسخه‌دار و با تاریخ شروع. قاعده‌ای که شروع شده تغییر نمی‌کند؛ تغییر یعنی نسخه جدید با تاریخ آینده. فاکتور صادرشده قاعده خودش را نگه می‌دارد.">
    <div class="notice warn">همه قاعده‌ها «نمونه» هستند تا کارشناس مالیاتی تأیید کند. زرلیو ادعای تأیید قانونی ندارد.</div>
    <div class="detail-grid">
        <section class="stack-sm">
            <div class="table-wrap"><table class="t">
                <thead><tr><th>دسته</th><th class="n">نسخه</th><th>مبنا</th><th class="n">نرخ</th><th>از</th><th>وضعیت</th><th class="n">فاکتورها</th><th>مرجع</th><th></th></tr></thead>
                <tbody>
                @foreach ($rules as $r)
                    @php($state = \App\Domain\Tax\TaxRuleAdmin::state($r, $current[$r->category] ?? null))
                    <tr>
                        <td>{{ $categories[$r->category] ?? $r->category }} <span class="mono">{{ $r->category }}</span></td>
                        <td class="n">{{ fa($r->version) }}</td>
                        <td class="small">{{ $bases[$r->base] ?? $r->base }}</td>
                        <td class="n">{{ pct($r->rate_percent) }}٪</td>
                        <td class="n small">{{ jdate($r->effective_from) }}</td>
                        <td><span class="badge {{ $ST[$state][1] }}">{{ $ST[$state][0] }}</span>@if($r->is_sample) <span class="badge dark">نمونه</span>@endif</td>
                        <td class="n">{{ fa($usage[(string) $r->id] ?? 0) }}</td>
                        <td class="xs">{{ $r->source_reference }}</td>
                        <td>
                            @if ($canManage && $state === 'scheduled')
                                <details><summary class="small">غیرفعال…</summary>
                                    <form class="stack-sm" data-action="{{ route('admin.tax.disable', $r->id) }}" data-reload data-confirm="نسخه {{ fa($r->version) }} پیش از شروع غیرفعال شود؟">
                                        <div class="field"><label for="dr{{ $r->id }}">دلیل</label><input id="dr{{ $r->id }}" name="reason" required minlength="5" maxlength="250"></div>
                                        <button class="btn sm btn-line" type="submit">غیرفعال شود</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <p class="xs muted">دسته‌های نقره، سکه و طلای آب‌شده برای نسخه ۲ رزرو شده‌اند. ردیف متفرقه قیمت نهایی دارد و مالیات جدا نمی‌گیرد.</p>
        </section>
        <section class="stack-sm">
            @if ($canManage)
                <form class="action-card stack-sm" data-action="{{ route('admin.tax.store') }}" data-idem data-reload
                      data-confirm="نسخه جدید {category} با نرخ {rate_percent}٪ از {effective_from} اجرا شود؟">
                    <h3>نسخه جدید</h3>
                    <div class="field"><label for="tc">دسته</label><select id="tc" name="category">@foreach ($schedulable as $key => $base)<option value="{{ $key }}">{{ $categories[$key] }}</option>@endforeach</select></div>
                    <div class="field"><label for="tr">نرخ (درصد)</label><input id="tr" name="rate_percent" required inputmode="decimal" class="ltr-input" placeholder="10"></div>
                    <div class="field" data-jdp data-min="{{ $minDate }}" data-quick="tomorrow,+1m" data-required><span class="label" id="tf-l">تاریخ شروع (آینده، از ساعت ۰۰:۰۰)</span><div class="input-wrap"><input type="hidden" name="effective_from" value="{{ $minDate }}"></div><div class="err"></div></div>
                    <div class="field"><label for="tn">مرجع قانونی یا یادداشت</label><textarea id="tn" name="reference" required minlength="5" maxlength="250"></textarea></div>
                    <label class="check"><input type="checkbox" name="expert_confirmed"> کارشناس مالیاتی این نرخ را تأیید کرده است</label>
                    <p class="xs muted">مبنای محاسبه همان «اجرت + سود + حق‌العمل» است که ماشین‌حساب پیاده می‌کند؛ گرد کردن: ریال، نیم به بالا.</p>
                    <button class="btn btn-gold" type="submit">زمان‌بندی نسخه</button>
                </form>
            @endif
            <section class="band stack-sm">
                <h2>مالیات خرید پلن و اعتبار پیامک</h2>
                <p>{{ pct($purchaseVat) }}٪ روی قیمت بدون مالیات؛ جدا در صفحه پرداخت نمایش داده می‌شود.</p>
                <a class="btn sm btn-line" href="{{ route('admin.pricing') }}">قیمت پلن و پیامک</a>
            </section>
        </section>
    </div>
</x-layouts.admin>
