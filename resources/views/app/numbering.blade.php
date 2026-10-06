@php
    $boot = ['current' => $current, 'jy' => $jy, 'jm' => $jm, 'next' => $next, 'version' => $version, 'presets' => collect($presets)->map(fn ($p) => $p['settings'])];
    $ltr = fn ($s) => "\u{2066}".invno($s)."\u{2069}";
@endphp
<x-layouts.app title="شماره‌گذاری فاکتور" page="numbering" :back="route('settings')">
    <script type="application/json" id="boot">@json($boot)</script>

    <section class="hero center stack-sm" aria-live="polite">
        <span class="small">شماره فاکتور بعدی</span>
        <div class="price num ltr" data-next-number>{{ $ltr($preview[0]) }}</div>
        <span class="small" data-following>بعدی‌ها: {{ $ltr($preview[1]) }} · {{ $ltr($preview[2]) }}</span>
    </section>

    <form class="stack" data-numbering novalidate>
        <fieldset class="band stack-sm">
            <legend class="label">یک روش را انتخاب کنید</legend>
            <div class="tiles numbering-presets">
                @foreach ($presets as $key => $p)
                    <label class="tile"><input type="radio" name="preset" value="{{ $key }}">
                        <strong>{{ $p['label'] }}</strong>
                        <span class="num ltr strong">{{ $ltr($p['example']) }}</span>
                        <span class="xs muted">{{ $p['hint'] }}</span></label>
                @endforeach
            </div>
        </fieldset>

        <details class="band stack-sm" data-custom>
            <summary class="strong">تنظیم دقیق</summary>
            <div class="field"><label for="n-prefix">پیشوند (اختیاری)</label><div class="input-wrap"><input id="n-prefix" name="prefix" maxlength="6" placeholder="مثلاً ط یا A1" value="{{ $current['prefix'] }}"></div><div class="err"></div>
                <p class="hint">برای چند شعبه یا چند دفتر فاکتور (طلا، نقره، آب‌شده). حداکثر ۶ حرف یا عدد.</p></div>
            <fieldset class="field"><legend class="label">سال در شماره</legend>
                <div class="seg" role="radiogroup">
                    <label><input type="radio" name="year" value="full">۱۴۰۵</label>
                    <label><input type="radio" name="year" value="short">۰۵</label>
                    <label><input type="radio" name="year" value="none">بدون سال</label>
                </div><div class="err"></div></fieldset>
            <label class="check"><input type="checkbox" name="month"> ماه هم در شماره بیاید (مثلاً ۰۷ برای مهر)</label>
            <div class="field" data-month-err><div class="err"></div></div>
            <fieldset class="field"><legend class="label">شماره از کِی دوباره از ۱ شروع شود؟</legend>
                <div class="seg" role="radiogroup">
                    <label><input type="radio" name="reset" value="yearly">هر سال (نوروز)</label>
                    <label><input type="radio" name="reset" value="monthly">هر ماه</label>
                    <label><input type="radio" name="reset" value="never">هیچ‌وقت</label>
                </div></fieldset>
            <div class="grid-2">
                <fieldset class="field"><legend class="label">جداکننده</legend>
                    <div class="seg" role="radiogroup">
                        <label><input type="radio" name="separator" value="-">-</label>
                        <label><input type="radio" name="separator" value="/">/</label>
                        <label><input type="radio" name="separator" value="">بدون</label>
                    </div></fieldset>
                <div class="field"><label for="n-digits">حداقل رقم شماره</label>
                    <div class="input-wrap"><select id="n-digits" name="digits">@for($d = 1; $d <= 8; $d++)<option value="{{ $d }}">{{ fa($d) }} رقم ({{ fa(str_pad('1', $d, '0', STR_PAD_LEFT)) }})</option>@endfor</select></div><div class="err"></div></div>
            </div>
        </details>

        <section class="band stack-sm">
            <div class="field"><label for="n-next">شروع از شماره (اختیاری)</label><div class="input-wrap ltr-input"><input id="n-next" name="next" inputmode="numeric" maxlength="8" data-digits placeholder="{{ fa($next[$current['reset']]) }}"></div><div class="err"></div>
                <p class="hint">اگر تا امروز فاکتور کاغذی داشتید، شماره بعدی دفترچه را بنویسید تا از همان ادامه دهد. شماره‌های صادرشده تکرار نمی‌شوند.</p></div>
            <p class="notice info small">تغییر از فاکتور بعدی اعمال می‌شود. @if($issuedCount)شماره {{ fa($issuedCount) }} فاکتور صادرشده قبلی عوض نمی‌شود.@endif</p>
            <button class="btn btn-gold block" type="submit" data-busy-text="در حال ذخیره…">ذخیره شماره‌گذاری</button>
        </section>
    </form>
</x-layouts.app>
