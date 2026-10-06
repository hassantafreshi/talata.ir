<x-layouts.admin title="کارکنان و دسترسی" page="admin-ops" description="هر کار حساس با «اجازه» بررسی می‌شود، نه با نام نقش. غیرفعال کردن، دسترسی را از همان لحظه قطع می‌کند.">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>نام</th><th>موبایل</th><th>نقش</th><th>کلید عبور</th><th>آخرین ورود</th><th>وضعیت</th>@if($canManage)<th></th>@endif</tr></thead>
        <tbody>
        @foreach ($staff as $s)
            <tr>
                <td>{{ $s->name }}@if($s->id === $me->id) <span class="badge info">شما</span>@endif</td>
                <td class="mono">{{ \App\Support\Mobile::display($s->mobile) }}</td>
                <td>{{ $roles[$s->role] ?? $s->role }}</td>
                <td>@if($passkeys[$s->id] ?? 0)<span class="badge ok">{{ fa($passkeys[$s->id]) }} دستگاه</span>@else<span class="badge {{ $requirePasskey ? 'err' : 'off' }}">ندارد</span>@endif</td>
                <td class="n small">{{ $s->last_login_at ? jdate($s->last_login_at, true) : '—' }}</td>
                <td>@if($s->active)<span class="badge ok">فعال</span>@else<span class="badge off">غیرفعال</span>@endif</td>
                @if ($canManage)
                    <td>
                        <details><summary class="small">ویرایش…</summary>
                            <form class="stack-sm" data-action="{{ route('admin.staff.update', $s->id) }}" data-method="PUT" data-reload data-confirm="تغییرات {{ $s->name }} ذخیره شود؟ (نقش: {role} · فعال: {active})">
                                <div class="field"><label for="sn{{ $s->id }}">نام</label><input id="sn{{ $s->id }}" name="name" value="{{ $s->name }}" required maxlength="60"></div>
                                <div class="field"><label for="sr{{ $s->id }}">نقش</label><select id="sr{{ $s->id }}" name="role">@foreach ($roles as $k => $label)<option value="{{ $k }}" @selected($s->role === $k)>{{ $label }}</option>@endforeach</select></div>
                                <label class="check"><input type="checkbox" name="active" @checked($s->active)> فعال</label>
                                <button class="btn sm btn-dark" type="submit">ذخیره</button>
                            </form>
                        </details>
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table></div>

    <div class="detail-grid">
        <section class="band stack-sm">
            <h2>جدول اجازه‌ها</h2>
            <div class="table-wrap"><table class="t matrix">
                <thead><tr><th>کار</th>@foreach ($roles as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr><td>دیدن داشبورد، فروشگاه‌ها، پرداخت‌ها، پیامک و سوابق</td>@foreach ($roles as $k => $label)<td>✓</td>@endforeach</tr>
                    @foreach ($permissions as $key => $p)
                        <tr><td>{{ $p['label'] }} <span class="mono">{{ $key }}</span></td>@foreach ($roles as $k => $label)<td>@if($k === 'admin' || in_array($k, $p['roles'], true))<span aria-label="دارد">✓</span>@else<span class="muted" aria-label="ندارد">—</span>@endif</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table></div>
            <p class="xs muted">کارهای حساس (فعال‌سازی دستی، اعتبار، تأیید دستی پرداخت، قیمت، مالیات، نرخ اضطراری، تعلیق، کارکنان) دلیل می‌خواهند، تأیید دوباره دارند، به ورود تازه (حداکثر {{ fa(config('talata.admin.reauth_minutes')) }} دقیقه پیش) نیاز دارند و در سوابق ثبت می‌شوند.</p>
        </section>
        @if ($canManage)
            <form class="action-card stack-sm" data-action="{{ route('admin.staff.store') }}" data-reload data-confirm="{name} با نقش «{role}» به کارکنان اضافه شود؟">
                <h3>دعوت همکار</h3>
                <div class="field"><label for="nm">نام</label><input id="nm" name="name" required maxlength="60"></div>
                <div class="field"><label for="mb">شماره موبایل</label><input id="mb" name="mobile" required inputmode="tel" class="ltr-input" placeholder="09xxxxxxxxx"></div>
                <div class="field"><label for="rl">نقش</label><select id="rl" name="role">@foreach ($roles as $k => $label)<option value="{{ $k }}" @selected($k === 'support')>{{ $label }}</option>@endforeach</select></div>
                <p class="xs muted">با همین شماره و کد پیامکی وارد می‌شود و @if($requirePasskey)پیش از هر کاری باید کلید عبور (اثر انگشت) اضافه کند.@else بهتر است کلید عبور اضافه کند.@endif</p>
                <button class="btn btn-gold" type="submit">افزودن</button>
            </form>
        @endif
    </div>
</x-layouts.admin>
