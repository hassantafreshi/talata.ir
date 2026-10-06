@php
    $S = \App\Http\Controllers\Admin\PaymentsController::STATUS_FA;
    $PL = \App\Domain\Admin\TenantDirectory::PLANS_FA;
    $canManage = $staff->allows('tenants.manage');
    $prices = $plans->map(fn ($p) => $p['price_toman'])->all();
    $lotSource = ['PURCHASE' => 'خرید', 'PROVIDER_ADJUST' => 'تنظیم مدیر'];
    $entryType = ['CREDIT' => 'شارژ', 'ADJUST' => 'تنظیم مدیر', 'EXPIRE' => 'انقضا'];
@endphp
<x-layouts.admin :title="$profile?->name ?: 'فروشگاه #'.$tenant->id" page="admin-tenant"
    :description="'شناسه #'.fa($tenant->id).' · ثبت‌نام '.jdate($tenant->created_at).($owner ? ' · مالک: '.($owner->name ?: '—').' '.\App\Support\Mobile::display($owner->mobile) : '')">
    <x-slot:actions>
        @if ($tenant->isActive())<span class="badge ok">فعال</span>@else<span class="badge err">تعلیق از {{ jdate($tenant->suspended_at, true) }}</span>@endif
    </x-slot:actions>

    @if (! $tenant->isActive())
        <div class="notice err">این فروشگاه تعلیق است: {{ $tenant->suspension_reason }}. کاربرانش به پنل دسترسی ندارند؛ صفحه‌های تأیید فاکتورهای صادرشده کار می‌کنند.</div>
    @endif

    <div class="detail-grid">
        <section class="stack-sm">
            <section class="band stack-sm">
                <h2>خلاصه و پلن</h2>
                <dl class="kv">
                    <div><dt>پلن</dt><dd>{{ $summary['plan']['label_fa'] }}@if($summary['plan']['period']) · {{ $summary['plan']['period'] === 'yearly' ? 'سالانه' : 'ماهانه' }}@endif @if($summary['plan']['ends_at_fa']) تا {{ $summary['plan']['ends_at_fa'] }}@endif</dd></div>
                    @foreach ($summary['quotas'] as $key => $q)
                        <div><dt>{{ \App\Domain\Plans\Entitlements::RESOURCES[$key] }} این ماه</dt><dd>{{ fa($q['used']) }} از {{ $q['limit'] === null ? 'نامحدود' : fa($q['limit']) }}</dd></div>
                    @endforeach
                    <div><dt>پیامک رایگان سالانه مانده</dt><dd>{{ fa($summary['free_sms_remaining']) }} از {{ fa($summary['free_sms_per_year']) }}</dd></div>
                    <div><dt>اعتبار پیامک</dt><dd><strong>{{ toman($balance) }} تومان</strong></dd></div>
                    <div><dt>موبایل کسب‌وکار</dt><dd class="mono">{{ $profile?->business_mobile ? \App\Support\Mobile::display($profile->business_mobile) : '—' }}</dd></div>
                    <div><dt>تلفن ثابت</dt><dd>{!! $profile?->landline ? e(fa($profile->landline)) : '<span class="badge warn">ندارد</span>' !!}</dd></div>
                    <div><dt>نشانی</dt><dd>{{ $profile?->address ?: '—' }}</dd></div>
                </dl>
            </section>

            <section class="band stack-sm" aria-labelledby="sub-h">
                <h2 id="sub-h">سابقه پلن</h2>
                <div class="table-wrap"><table class="t">
                    <thead><tr><th>پلن</th><th>از</th><th>تا</th><th>منبع</th><th>وضعیت</th></tr></thead>
                    <tbody>
                    @forelse ($subscriptions as $s)
                        <tr><td>{{ $PL[$s->plan_code] ?? $s->plan_code }} · {{ $s->period === 'yearly' ? 'سالانه' : 'ماهانه' }}</td><td class="n small">{{ jdate($s->starts_at) }}</td><td class="n small">{{ jdate($s->ends_at) }}</td>
                            <td>{{ $s->activated_by === 'PROVIDER' ? 'ثبت دستی مدیر' : 'پرداخت آنلاین' }}</td><td>{{ $s->status === 'active' ? 'فعال' : 'جایگزین‌شده' }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="muted">همیشه رایگان بوده است.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </section>

            <section class="band stack-sm" aria-labelledby="pay-h">
                <h2 id="pay-h">پرداخت‌ها</h2>
                <div class="table-wrap"><table class="t">
                    <thead><tr><th>سفارش</th><th>محصول</th><th class="n">مبلغ</th><th>وضعیت</th><th>زمان</th></tr></thead>
                    <tbody>
                    @forelse ($orders as $o)
                        <tr><td><a class="mono" href="{{ route('admin.payment', $o->id) }}">{{ $o->public_ref }}</a>@if($o->channel === 'MANUAL') <span class="badge info">دستی</span>@endif</td>
                            <td>{{ \App\Http\Controllers\Admin\PaymentsController::PRODUCT_FA[$o->product] ?? $o->product }}</td><td class="n">{{ toman($o->amount_irr) }}</td>
                            <td><span class="badge {{ $S[$o->status][1] ?? 'off' }}">{{ $S[$o->status][0] ?? $o->status }}</span></td><td class="n small">{{ jdate($o->created_at, true) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="muted">پرداختی ندارد.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </section>

            <section class="band stack-sm" aria-labelledby="cr-h">
                <h2 id="cr-h">اعتبار پیامک</h2>
                <div class="table-wrap"><table class="t">
                    <thead><tr><th>منبع</th><th class="n">مبلغ</th><th class="n">مانده</th><th>انقضا</th><th>یادداشت</th></tr></thead>
                    <tbody>
                    @forelse ($lots as $l)
                        <tr><td>{{ $lotSource[$l->source] ?? $l->source }}</td><td class="n">{{ toman($l->amount_irr) }}</td><td class="n">{{ toman($l->remaining_irr) }}</td>
                            <td class="small">{{ $l->expires_at ? jdate($l->expires_at) : 'منتقل می‌شود' }}</td><td class="xs">{{ $l->note }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="muted">اعتباری نخریده است.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                @if ($entries->isNotEmpty())
                    <details><summary class="small">گردش شارژ، تنظیم و انقضا</summary>
                        <ul class="list">@foreach ($entries as $e)<li class="list-item small"><span class="body">{{ $entryType[$e->type] ?? $e->type }} <span class="num">{{ toman($e->amount_irr) }}</span> تومان</span><span class="xs muted">{{ jdate($e->created_at, true) }}</span></li>@endforeach</ul>
                    </details>
                @endif
            </section>

            <section class="band stack-sm" aria-labelledby="ov-h">
                <h2 id="ov-h">قابلیت‌های ویژه</h2>
                <ul class="list">
                    @forelse ($overrides as $ov)
                        @php($live = ! $ov->expires_at || $ov->expires_at->gt(now()))
                        <li class="list-item"><span class="body"><strong>{{ $overridable[$ov->key] ?? $ov->key }}: {{ ($ov->value['enabled'] ?? false) ? 'روشن' : 'خاموش' }}</strong>
                            <span class="sub">{{ $live ? 'تا '.jdate($ov->expires_at) : 'پایان‌یافته '.jdate($ov->expires_at) }} · {{ $staffNames[$ov->created_by_staff] ?? '—' }} · {{ $ov->reason }}</span></span>
                            @if ($live && $canManage)
                                <details><summary class="small">پایان…</summary>
                                    <form class="stack-sm" data-action="{{ route('admin.tenant.override.end', [$tenant->id, $ov->id]) }}" data-reload data-confirm="این قابلیت ویژه همین حالا تمام شود؟">
                                        <div class="field"><label for="oe{{ $ov->id }}">دلیل</label><input id="oe{{ $ov->id }}" name="reason" required minlength="5" maxlength="250"></div>
                                        <button class="btn sm btn-line" type="submit">پایان</button>
                                    </form>
                                </details>
                            @endif
                        </li>
                    @empty
                        <li class="muted small">قابلیت ویژه‌ای ندارد؛ همه‌چیز طبق پلن است.</li>
                    @endforelse
                </ul>
            </section>

            <section class="band stack-sm">
                <h2>کاربران فروشگاه</h2>
                <ul class="list">
                    @foreach ($members as $m)
                        <li class="list-item"><span class="body">@if($m->user)<a class="mono" href="{{ route('admin.user', $m->user_id) }}">{{ \App\Support\Mobile::display($m->user->mobile) }}</a>@else<span class="mono">{{ \App\Support\Mobile::display($m->invited_mobile) }}</span>@endif
                            <span class="sub">{{ $m->isOwner() ? 'مالک' : 'همکار' }} · {{ ['active' => 'فعال', 'invited' => 'دعوت‌شده', 'removed' => 'حذف‌شده'][$m->status] ?? $m->status }}</span></span></li>
                    @endforeach
                </ul>
            </section>

            <section class="band stack-sm">
                <h2>رویدادهای اخیر</h2>
                <table class="t"><tbody>
                    @foreach ($events as $ev)
                        <tr><td class="n small">{{ jdate($ev->created_at, true) }}</td><td class="mono">{{ $ev->event }}</td><td class="small">{{ $ev->actor_type === 'staff' ? 'مدیر' : ($ev->actor_type === 'system' ? 'سیستم' : 'کاربر') }}</td></tr>
                    @endforeach
                </tbody></table>
                <div class="status-row">
                    @foreach ($byService as $svc => $c)<a class="badge off" href="{{ route('admin.activity', ['tenant' => $tenant->id, 'service' => $svc]) }}">{{ \App\Domain\Audit\Audit::SERVICE_LABELS[$svc] ?? $svc }} {{ fa($c) }}</a>@endforeach
                </div>
                <a class="btn btn-line" href="{{ route('admin.activity', ['tenant' => $tenant->id]) }}">همه سوابق این فروشگاه</a>
            </section>
        </section>

        <section class="stack-sm" aria-label="اقدام‌های دستی">
            @if ($canManage)
                <details class="action-card" open>
                    <summary><h3>فعال‌سازی دستی پلن</h3></summary>
                    <form class="stack-sm" data-action="{{ route('admin.tenant.activate', $tenant->id) }}" data-idem data-reload data-activation
                          data-prices='@json($prices)' data-vat="{{ $vat }}"
                          data-confirm="پلن {plan} {period} برای «{{ $profile?->name ?: 'این فروشگاه' }}» با دریافتی {received_toman} تومان (پیگیری {reference}) فعال شود؟">
                        <div class="form-grid">
                            <div class="field"><label for="ap">پلن</label><select id="ap" name="plan">@foreach ($plans as $code => $p)<option value="{{ $code }}">{{ $p['label_fa'] }}</option>@endforeach</select></div>
                            <div class="field"><label for="ape">دوره</label><select id="ape" name="period"><option value="monthly">ماهانه</option><option value="yearly">سالانه</option></select></div>
                            <div class="field"><label for="ar">مبلغ دریافتی با مالیات (تومان)</label><input id="ar" name="received_toman" required inputmode="numeric" class="ltr-input"></div>
                            <div class="field"><label for="af">شماره پیگیری پرداخت</label><input id="af" name="reference" required maxlength="60" class="ltr-input"></div>
                            <div class="field wide"><label for="arr">دلیل</label><textarea id="arr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                        </div>
                        <p class="small" data-preview aria-live="polite"></p>
                        <p class="xs muted">شروع: امروز (یا ادامه همان پلن). همان اعمال پرداخت آنلاین اجرا می‌شود و سفارش «ثبت دستی» در پرداخت‌ها می‌ماند.</p>
                        <button class="btn btn-gold" type="submit">ثبت با تأیید دوباره</button>
                    </form>
                </details>
                <details class="action-card">
                    <summary><h3>افزایش یا کاهش اعتبار پیامک</h3></summary>
                    <form class="stack-sm" data-action="{{ route('admin.tenant.credit', $tenant->id) }}" data-idem data-reload
                          data-confirm="{direction} {amount_toman} تومان اعتبار پیامک برای «{{ $profile?->name ?: 'این فروشگاه' }}»؟">
                        <div class="form-grid">
                            <fieldset class="field"><legend class="label">نوع</legend>
                                <label class="check"><input type="radio" name="direction" value="add" checked> افزایش</label>
                                <label class="check"><input type="radio" name="direction" value="deduct"> کاهش</label>
                            </fieldset>
                            <div class="field"><label for="ca">مبلغ (تومان)</label><input id="ca" name="amount_toman" required inputmode="numeric" class="ltr-input"></div>
                            <div class="field"><label for="cref">مرجع (اختیاری)</label><input id="cref" name="reference" maxlength="60"></div>
                            <label class="check"><input type="checkbox" name="carries_over" @checked($carryDefault)> به ماه بعد منتقل شود</label>
                            <div class="field wide"><label for="crr">دلیل</label><textarea id="crr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                        </div>
                        <button class="btn btn-dark" type="submit">ثبت اعتبار</button>
                    </form>
                </details>
                <details class="action-card">
                    <summary><h3>قابلیت ویژه (موقت)</h3></summary>
                    <form class="stack-sm" data-action="{{ route('admin.tenant.override', $tenant->id) }}" data-idem data-reload
                          data-confirm="«{key}» برای این فروشگاه {enabled} شود به مدت {days}؟">
                        <div class="form-grid">
                            <div class="field"><label for="ok">قابلیت</label><select id="ok" name="key">@foreach ($overridable as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
                            <div class="field"><label for="oen">وضعیت</label><select id="oen" name="enabled"><option value="1">روشن</option><option value="0">خاموش</option></select></div>
                            <div class="field"><label for="od">مدت</label><select id="od" name="days">@foreach ($overrideDays as $d => $label)<option value="{{ $d }}" @selected($d === '30')>{{ $label }}</option>@endforeach</select></div>
                            <div class="field wide"><label for="orr">دلیل</label><textarea id="orr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                        </div>
                        <button class="btn btn-dark" type="submit">ثبت قابلیت ویژه</button>
                    </form>
                </details>
            @endif

            @if ($staff->allows('tenants.suspend'))
                <div class="action-card danger stack-sm">
                    @if ($tenant->isActive())
                        <h3>تعلیق موقت</h3>
                        <p class="small muted">کاربران فروشگاه تا رفع تعلیق وارد پنل نمی‌شوند. فاکتورهای صادرشده و QR آن‌ها قابل تأیید می‌مانند.</p>
                        <form class="stack-sm" data-action="{{ route('admin.tenant.suspend', $tenant->id) }}" data-reload data-confirm="«{{ $profile?->name ?: 'این فروشگاه' }}» تعلیق شود؟ کاربرانش از همین لحظه به پنل دسترسی ندارند.">
                            <div class="field"><label for="sr">دلیل</label><textarea id="sr" name="reason" required minlength="5" maxlength="250"></textarea></div>
                            <button class="btn btn-line" type="submit">تعلیق فروشگاه</button>
                        </form>
                    @else
                        <h3>رفع تعلیق</h3>
                        <form class="stack-sm" data-action="{{ route('admin.tenant.unsuspend', $tenant->id) }}" data-reload data-confirm="تعلیق این فروشگاه برداشته شود؟">
                            <div class="field"><label for="ur">دلیل</label><textarea id="ur" name="reason" required minlength="5" maxlength="250"></textarea></div>
                            <button class="btn btn-gold" type="submit">رفع تعلیق</button>
                        </form>
                    @endif
                </div>
            @endif

            <section class="stack-sm" aria-labelledby="bk-h">
                <h2 id="bk-h">پشتیبان تنظیمات ({{ fa($backups->count()) }})</h2>
                <p class="xs muted">به درخواست مالک فروشگاه. پیش از بازگرداندن، تنظیمات فعلی پشتیبان گرفته می‌شود و کار با نام شما در سوابق ثبت می‌شود.</p>
                @forelse ($backups as $b)
                    <details class="band white stack-sm">
                        <summary><strong>{{ $b->label ?: (\App\Models\SettingsBackup::REASONS[$b->reason] ?? 'پشتیبان') }}</strong> <span class="xs muted num">{{ jdate($b->created_at, true) }}</span></summary>
                        <p class="xs">نام: {{ $b->payload['profile']['name'] ?? '—' }} · آدرس: {{ $b->payload['profile']['address'] ?? '—' }}</p>
                        @if ($staff->allows('tenants.restore'))
                            <form class="stack-sm" data-admin-restore data-url="{{ route('admin.tenant.backup.restore', [$tenant->id, $b->id]) }}">
                                @foreach ($sections as $key => $label)<label class="check"><input type="checkbox" name="sections[]" value="{{ $key }}"> {{ $label }}</label>@endforeach
                                <button class="btn sm btn-dark" type="submit">بازگرداندن بخش‌های انتخاب‌شده</button>
                            </form>
                        @endif
                    </details>
                @empty
                    <p class="small muted">پشتیبانی ثبت نشده است (فقط پلن پایه و حرفه‌ای).</p>
                @endforelse
            </section>
        </section>
    </div>
</x-layouts.admin>
