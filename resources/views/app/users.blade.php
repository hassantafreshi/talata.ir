<x-layouts.app title="کاربران و دسترسی‌ها" page="users" :back="route('settings')">
    <p class="small muted">همکاران با شماره موبایل خود و کد پیامکی (یا اثر انگشت) وارد می‌شوند؛ رمز عبور لازم نیست.@if($limit) حداکثر {{ fa($limit) }} کاربر.@endif</p>
    @unless ($canRestrict)
        <div class="notice info">همکاران به‌طور پیش‌فرض دسترسی کامل دارند. تعیین سطح دسترسی هر همکار در پلن پایه و حرفه‌ای است. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></div>
    @endunless
    <ul class="list" data-members>
        @foreach ($members as $m)
            <li class="row-card stack-sm" data-member="{{ $m->id }}">
                <div class="between">
                    <span><strong class="num ltr">{{ \App\Support\Mobile::display($m->user?->mobile ?? $m->invited_mobile) }}</strong>
                        @if ($m->isOwner())<span class="badge dark">مالک</span>@elseif($m->status === 'invited')<span class="badge warn">دعوت‌شده؛ هنوز وارد نشده</span>@endif
                        @if ($m->id === $me->id)<span class="badge info">شما</span>@endif</span>
                    @unless ($m->isOwner())<button type="button" class="btn btn-link sm" data-remove>حذف</button>@endunless
                </div>
                @if ($m->isOwner())
                    <p class="xs muted">همه دسترسی‌ها، از جمله پرداخت و مدیریت کاربران.</p>
                @elseif ($canRestrict)
                    <div class="chips">
                        @foreach ($permissions as $key => $label)
                            <label class="chip"><input type="checkbox" data-perm value="{{ $key }}" @checked(in_array($key, $m->permissions ?? [], true))>{{ $label }}</label>
                        @endforeach
                    </div>
                @else
                    <p class="xs muted"><span class="badge ok">دسترسی کامل</span> صدور و ابطال فاکتور، مشتریان، تنظیمات و پرداخت.</p>
                @endif
            </li>
        @endforeach
    </ul>
    <form class="band stack-sm" data-invite novalidate>
        <h2>افزودن همکار</h2>
        <div class="field"><label for="u-mobile">موبایل همکار</label><div class="input-wrap ltr-input"><input id="u-mobile" name="mobile" inputmode="tel" maxlength="14" data-digits required></div><div class="err"></div></div>
        @if ($canRestrict)
        <p class="xs muted">دسترسی‌های همکار (پیش‌فرض: همه):</p>
        <div class="chips">
            @foreach ($permissions as $key => $label)
                <label class="chip"><input type="checkbox" name="permissions[]" value="{{ $key }}" checked>{{ $label }}</label>
            @endforeach
        </div>
        @endif
        <p class="xs muted">همکار پس از ورود با همین شماره، دعوت را در «تنظیمات» می‌پذیرد. برای دعوت پیامکی ارسال نمی‌شود.</p>
        <button class="btn btn-gold block" type="submit" data-busy-text="در حال افزودن…">افزودن</button>
    </form>
</x-layouts.app>
