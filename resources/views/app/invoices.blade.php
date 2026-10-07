<x-layouts.app title="فاکتورها" page="invoices">
    @php($canIssue = app(\App\Tenancy\TenantContext::class)->membership()?->can('invoice.issue'))
    @if ($canIssue)
        {{-- On mobile the bottom bar has no «فاکتور جدید»; this is the way to start one from the list. --}}
        <a href="{{ route('invoices.new') }}" class="btn btn-gold block mobile-only">+ فاکتور جدید</a>
    @endif
    @if ($quota['limit'] !== null)
        <div class="band stack-sm">
            <div class="between small"><span>فاکتورهای این ماه</span><span class="num">{{ fa($quota['used']) }} از {{ fa($quota['limit']) }}</span></div>
            <progress class="meter" max="{{ $quota['limit'] }}" value="{{ min($quota['used'], $quota['limit']) }}" aria-label="مصرف سهمیه فاکتور"></progress>
            <div class="xs muted">سهمیه از {{ $quota['resets_at_fa'] }} دوباره پر می‌شود.</div>
        </div>
    @endif
    @if ($historyRestricted)
        <div class="notice info">در پلن رایگان فقط فاکتورهای ماه جاری نمایش داده می‌شود. فاکتورهای قبلی حذف نشده‌اند. <a href="{{ route('settings.plan') }}">مشاهده همه با ارتقا</a></div>
    @endif

    <form class="stack-sm" data-filter role="search" action="{{ route('invoices.index') }}">
        <div class="field"><label for="q" class="sr-only">جستجو</label><div class="input-wrap"><input id="q" name="q" type="search" value="{{ $search }}" placeholder="جستجو: شماره، نام یا موبایل مشتری" autocomplete="off"></div></div>
        <div class="seg" role="radiogroup" aria-label="وضعیت">
            @foreach (['all' => 'همه', 'issued' => 'قطعی', 'draft' => 'پیش‌نویس', 'void' => 'باطل'] as $k => $label)
                <label><input type="radio" name="filter" value="{{ $k }}" @checked($filter === $k)>{{ $label }}</label>
            @endforeach
        </div>
        @if (! $historyRestricted || $canInstallments)
            <div class="chips" role="group" aria-label="فیلترهای بیشتر">
                @unless ($historyRestricted)
                    <label class="chip"><input type="checkbox" name="month" value="1" @checked($onlyMonth)>این ماه</label>
                @endunless
                @if ($canInstallments)
                    <label class="chip"><input type="checkbox" name="installment" value="1" @checked($onlyInstallment)>اقساطی</label>
                @endif
            </div>
        @endif
        <noscript><button class="btn btn-line">اعمال</button></noscript>
    </form>
    <p class="xs muted" data-count role="status">{{ fa($page->total()) }} مورد</p>
    <ul class="list" data-list aria-live="polite">
        @include('app.partials.invoice-rows')
    </ul>
</x-layouts.app>
