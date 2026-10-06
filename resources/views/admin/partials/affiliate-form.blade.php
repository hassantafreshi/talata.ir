{{-- Shared enrol/edit form. $a = existing affiliate or null. --}}
@php $pct = fn ($v) => (string) \Brick\Math\BigDecimal::of((string) $v)->strippedOfTrailingZeros(); @endphp
<form class="filters band" data-affiliate-form data-url="{{ $a ? route('admin.affiliates.update', $a->id) : route('admin.affiliates.store') }}" data-method="{{ $a ? 'PUT' : 'POST' }}" novalidate>
    @unless ($a)
        <label class="small field">موبایل کاربر (باید پنل فروشگاه داشته باشد)<input name="mobile" inputmode="tel" dir="ltr" required><span class="err"></span></label>
    @endunless
    <label class="small field">درصد کمیسیون<input name="commission_percent" inputmode="decimal" dir="ltr" value="{{ $a ? $pct($a->commission_percent) : '10' }}" required><span class="err"></span></label>
    <label class="small field">نوع کمیسیون<select name="commission_mode">@foreach (\App\Models\Affiliate::MODES as $k => $l)<option value="{{ $k }}" @selected(($a->commission_mode ?? 'FIRST_PAYMENT') === $k)>{{ $l }}</option>@endforeach</select><span class="err"></span></label>
    <label class="small field">تخفیف خریدار روی خرید اول پلن (٪)<input name="discount_percent" inputmode="decimal" dir="ltr" value="{{ $a ? $pct($a->discount_percent) : '10' }}"><span class="err"></span></label>
    <label class="small field">کد تخفیف (خالی = ساخت خودکار)<input name="code" dir="ltr" maxlength="16" value="{{ $a->code ?? '' }}" placeholder="TLAB12CD"><span class="err"></span></label>
    @if ($a)
        <label class="small field">وضعیت<select name="status"><option value="active" @selected($a->status === 'active')>فعال</option><option value="paused" @selected($a->status === 'paused')>متوقف</option></select></label>
    @endif
    <label class="small check"><input type="checkbox" name="include_sms_credit" value="1" @checked($a?->include_sms_credit)> کمیسیون روی خرید اعتبار پیامک هم</label>
    <label class="small field">یادداشت داخلی<input name="note" maxlength="250" value="{{ $a->note ?? '' }}"></label>
    <button class="btn btn-gold" type="submit" data-busy-text="…">{{ $a ? 'ذخیره شرایط' : 'فعال‌کردن همکاری' }}</button>
</form>
