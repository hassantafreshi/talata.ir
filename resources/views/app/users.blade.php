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
                    @php $granted = $m->permissions ?? []; @endphp
                    <p class="xs">@if(count(array_intersect(\App\Models\Membership::allPermissions(), $granted)) === count(\App\Models\Membership::PERMISSIONS))<span class="badge ok">دسترسی کامل</span>@else
                        @foreach (\App\Models\Membership::PERMISSIONS as $key => $label)@if(in_array($key, $granted, true))<span class="badge info">{{ \Illuminate\Support\Str::before($label, ' (') }}</span> @endif @endforeach @endif</p>
                    <details class="perm-edit">
                        <summary class="btn btn-line sm">ویرایش دسترسی</summary>
                        <form method="post" class="stack-sm" data-perm-form novalidate>
                            @include('app.partials.permission-picker', ['selected' => $granted])
                            <button class="btn btn-dark block" type="submit" data-busy-text="در حال ذخیره…">ذخیره دسترسی</button>
                        </form>
                    </details>
                @else
                    <p class="xs muted"><span class="badge ok">دسترسی کامل</span> مظنه، ماشین‌حساب، فاکتورها، مشتریان، داشبورد، تنظیمات و پرداخت.</p>
                @endif
            </li>
        @endforeach
    </ul>
    <form method="post" class="band stack-sm" data-invite novalidate>
        <h2>افزودن همکار</h2>
        <div class="field"><label for="u-mobile">موبایل همکار</label><div class="input-wrap ltr-input"><input id="u-mobile" name="mobile" inputmode="tel" maxlength="14" data-digits required></div><div class="err"></div></div>
        @if ($canRestrict)
            <h3 class="small strong">همکار به چه بخش‌هایی دسترسی داشته باشد؟</h3>
            @include('app.partials.permission-picker', ['selected' => null])
        @else
            <p class="xs muted">در این پلن همکار دسترسی کامل دارد.</p>
        @endif
        <p class="xs muted">همکار پس از ورود با همین شماره، دعوت را در «تنظیمات» می‌پذیرد. برای دعوت پیامکی ارسال نمی‌شود.</p>
        <button class="btn btn-gold block" type="submit" data-busy-text="در حال افزودن…">افزودن</button>
    </form>
</x-layouts.app>
