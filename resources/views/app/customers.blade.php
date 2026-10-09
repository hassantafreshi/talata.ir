<x-layouts.app title="مشتریان و اقساط" page="customers" :back="route('settings')">
    @if ($quota['limit'] !== null)
        <div class="band stack-sm">
            <div class="between small"><span>مشتری جدید این ماه</span><span class="num">{{ fa($quota['used']) }} از {{ fa($quota['limit']) }}</span></div>
            <progress class="meter" max="{{ $quota['limit'] }}" value="{{ min($quota['used'], $quota['limit']) }}" aria-label="مصرف سهمیه مشتری"></progress>
        </div>
    @endif
    @unless ($canInstallments)
        <x-upgrade-note cap="installments.manage">فهرست مشتریان در همه پلن‌ها آزاد است. اقساط و یادآوری پیامکی با ارتقا.</x-upgrade-note>
    @endunless

    <div class="between">
        <form class="grow" role="search" action="{{ route('customers.index') }}">
            <label for="cq" class="sr-only">جستجو</label>
            <div class="input-wrap"><input id="cq" name="q" type="search" value="{{ $search }}" placeholder="جستجو: نام یا موبایل" autocomplete="off"></div>
            @if ($canInstallments && $bal !== 'all')<input type="hidden" name="bal" value="{{ $bal }}">@endif
        </form>
        @if ($canManage)<button type="button" class="btn btn-gold" data-add>+ مشتری</button>@endif
    </div>
    @if ($canInstallments)
        <div class="seg" role="group" aria-label="مانده حساب">
            @foreach (['all' => 'همه', 'owing' => 'مانده قسط دارند', 'settled' => 'تسویه‌شده'] as $k => $label)
                <a href="{{ route('customers.index', array_filter(['q' => $search, 'bal' => $k === 'all' ? null : $k])) }}" @if($bal === $k) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>
        <p class="xs muted">مانده قسط = بخش پرداخت‌نشدهٔ قراردادهای اقساطی فعال مشتری.</p>
    @endif
    <p class="xs muted">{{ fa($page->total()) }} مشتری</p>

    <ul class="list">
        @forelse ($page as $c)
            <li><a class="list-item" href="{{ route('customers.show', $c) }}"><span class="body"><strong>{{ $c->name }}</strong><span class="sub num ltr">{{ $c->mobile ? \App\Support\Mobile::display($c->mobile) : '—' }}</span></span>
                <span class="stack-sm center">
                    @if ($canInstallments && (int) $c->outstanding_irr > 0)<span class="num strong nowrap">{{ toman((string) $c->outstanding_irr) }} <span class="unit">تومان</span></span><span class="xs muted">مانده قسط</span>@endif
                    <span class="small muted">{{ fa($c->invoices_count) }} فاکتور</span>
                </span></a></li>
        @empty
            <li class="empty">{{ $total ? 'مشتری با این مشخصات پیدا نشد.' : 'هنوز مشتری ثبت نشده. هنگام صدور فاکتور هم می‌توانید مشتری را ذخیره کنید.' }}</li>
        @endforelse
    </ul>
    @if ($page->hasPages())
        <nav class="pager" aria-label="صفحه‌ها">
            @if ($page->previousPageUrl())<a class="btn btn-line sm" href="{{ $page->previousPageUrl() }}">قبلی</a>@endif
            <span class="small">صفحه {{ fa($page->currentPage()) }} از {{ fa($page->lastPage()) }}</span>
            @if ($page->nextPageUrl())<a class="btn btn-line sm" href="{{ $page->nextPageUrl() }}">بعدی</a>@endif
        </nav>
    @endif

    <template data-customer-tpl>
        <div class="between"><h2 data-title>مشتری جدید</h2><button type="button" class="icon-btn" data-close aria-label="بستن">✕</button></div>
        <form method="post" class="stack" data-customer-form novalidate>
            <div class="field"><label for="cu-name">نام</label><div class="input-wrap"><input id="cu-name" name="name" maxlength="80" required></div><div class="err"></div></div>
            <div class="field"><label for="cu-mobile">موبایل (اختیاری)</label><div class="input-wrap ltr-input"><input id="cu-mobile" name="mobile" inputmode="tel" maxlength="14" data-digits></div><div class="err"></div></div>
            <div class="field"><label for="cu-nid">کد ملی (اختیاری)</label><div class="input-wrap ltr-input"><input id="cu-nid" name="national_id" inputmode="numeric" maxlength="12" data-digits></div><div class="err"></div></div>
            <div class="field"><label for="cu-note">یادداشت داخلی (اختیاری)</label><div class="input-wrap"><input id="cu-note" name="note" maxlength="250"></div><div class="err"></div><p class="hint">فقط برای شما؛ روی فاکتور نمی‌آید.</p></div>
            <button class="btn btn-gold block" type="submit" data-busy-text="در حال ذخیره…">ذخیره</button>
        </form>
    </template>
</x-layouts.app>
