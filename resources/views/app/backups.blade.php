<x-layouts.app title="پشتیبان تنظیمات" page="backups" :back="route('settings')">
    <p class="small muted">بعد از هر تغییر در تنظیمات (اطلاعات کسب‌وکار، لوگو، ظاهر فاکتور، متن پیامک، شماره‌گذاری) یک نسخه پشتیبان ذخیره می‌شود. {{ fa($keep) }} نسخه آخر نگه داشته می‌شود. فاکتورها، مشتریان، اعتبار پیامک و کاربران با بازگرداندن تغییر نمی‌کنند.</p>

    @unless ($enabled)
        <div class="notice info">پشتیبان‌گیری و بازگرداندن تنظیمات در پلن پایه و حرفه‌ای است. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></div>
    @else
        <form class="band stack-sm" data-manual novalidate>
            <div class="field"><label for="b-label">پشتیبان دستی (اختیاری: یک نام بنویسید)</label><div class="input-wrap"><input id="b-label" name="label" maxlength="80" placeholder="مثلاً قبل از تغییر قالب عید"></div><div class="err"></div></div>
            <button class="btn btn-dark block" type="submit" data-busy-text="در حال ذخیره…">پشتیبان‌گیری همین حالا</button>
        </form>
    @endunless

    @if ($list->isEmpty())
        <p class="notice info small">هنوز نسخه پشتیبانی وجود ندارد. با اولین تغییر تنظیمات، خودکار ساخته می‌شود.</p>
    @else
        <ol class="list backups" aria-label="نسخه‌های پشتیبان">
            @foreach ($list as $b)
                @php $diff = $differs($b); @endphp
                <li class="row-card stack-sm" data-backup="{{ $b->id }}">
                    <div class="between">
                        <span><strong>{{ $b->label ?: \App\Models\SettingsBackup::REASONS[$b->reason] ?? 'پشتیبان' }}</strong>
                            @if ($loop->first && ! $diff)<span class="badge ok">همین تنظیمات فعلی</span>@endif</span>
                        <span class="xs muted num">{{ jdate($b->created_at, true) }}</span>
                    </div>
                    <p class="xs muted">توسط {{ $who($b) }} · @if($b->label){{ \App\Models\SettingsBackup::REASONS[$b->reason] ?? '' }} · @endif
                        @if ($diff) فرق با الان: {{ implode('، ', array_map(fn ($d) => \Illuminate\Support\Str::before($d, ' ('), $diff)) }} @else بدون فرق با تنظیمات فعلی @endif</p>
                    <details>
                        <summary class="small">مشاهده محتوا</summary>
                        @php $p = $b->payload; @endphp
                        <dl class="kv small">
                            <div><dt>نام فروشگاه</dt><dd>{{ $p['profile']['name'] ?? '—' }}</dd></div>
                            <div><dt>موبایل کسب‌وکار</dt><dd class="num ltr">{{ \App\Support\Mobile::display($p['profile']['business_mobile'] ?? null) ?: '—' }}</dd></div>
                            <div><dt>آدرس</dt><dd>{{ $p['profile']['address'] ?? '—' }}</dd></div>
                            <div><dt>لوگو</dt><dd>{{ ! empty($p['logo']) ? 'نسخه '.fa($p['logo']['version']) : 'بدون لوگو' }}</dd></div>
                            <div><dt>قالب فاکتور</dt><dd>{{ ($p['layout']['template_id'] ?? 'simple_readable') === 'shop' ? 'فروشگاهی' : 'ساده و خوانا' }}</dd></div>
                            <div><dt>شماره‌گذاری</dt><dd class="num ltr">{{ invno(\App\Domain\Invoices\Numbering::format(\App\Domain\Invoices\Numbering::sanitize($p['numbering'] ?? [], false), now()->toImmutable(), config('talata.timezone'), 1)) }}</dd></div>
                            <div><dt>متن پیامک</dt><dd class="small">{{ $p['sms_template'] ?: 'متن پیش‌فرض' }}</dd></div>
                        </dl>
                    </details>
                    @if ($enabled && $diff)
                        <details class="restore">
                            <summary class="btn btn-line sm">بازگرداندن…</summary>
                            <form class="stack-sm" data-restore-form novalidate>
                                <p class="small">کدام بخش‌ها به این نسخه برگردند؟</p>
                                @foreach ($sections as $key => $label)
                                    <label class="choice"><input type="checkbox" name="sections[]" value="{{ $key }}" @checked(in_array($label, $diff, true))><span>{{ $label }}</span></label>
                                @endforeach
                                <p class="xs muted">پیش از بازگرداندن، تنظیمات فعلی هم پشتیبان گرفته می‌شود تا اگر پشیمان شدید برگردانید.</p>
                                <button class="btn btn-gold block" type="submit" data-busy-text="در حال بازگرداندن…">بازگرداندن بخش‌های انتخاب‌شده</button>
                            </form>
                        </details>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-layouts.app>
