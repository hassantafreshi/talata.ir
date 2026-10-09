<x-layouts.app title="فاکتورها" page="invoices">
    @php($canIssue = app(\App\Tenancy\TenantContext::class)->membership()?->can('invoice.issue'))
    @if ($canIssue)
        {{-- On mobile the bottom bar has no «فاکتور جدید»; this is the way to start one from the list. --}}
        <a href="{{ route('invoices.new') }}" class="btn btn-gold block mobile-only">+ فاکتور جدید</a>
        @php($pfOpen = \App\Models\Proforma::query()->where('status', 'SENT')->where('expires_at', '>', now())->count())
        @php($pfReady = \App\Models\Proforma::query()->where('status', 'CONFIRMED')->whereNull('issued_at')->count())
        <a class="list-item" href="{{ route('proformas.index') }}"><span class="body"><strong>پیش‌فاکتورها</strong><span class="sub">@if($pfReady){{ fa($pfReady) }} تأییدشده منتظر صدور فاکتور@if($pfOpen) · @endif @endif @if($pfOpen){{ fa($pfOpen) }} منتظر تأیید مشتری@endif @if(! $pfOpen && ! $pfReady)پیش‌فاکتورهای فرستاده‌شده و وضعیت تأیید@endif</span></span>@if($pfReady + $pfOpen)<span class="badge {{ $pfReady ? 'ok' : 'warn' }}">{{ fa($pfReady + $pfOpen) }}</span>@endif<span aria-hidden="true">‹</span></a>
    @endif
    @if ($quota['limit'] !== null)
        <div class="band stack-sm">
            <div class="between small"><span>فاکتورهای این ماه</span><span class="num">{{ fa($quota['used']) }} از {{ fa($quota['limit']) }}</span></div>
            <progress class="meter" max="{{ $quota['limit'] }}" value="{{ min($quota['used'], $quota['limit']) }}" aria-label="مصرف سهمیه فاکتور"></progress>
            <div class="xs muted">سهمیه از {{ $quota['resets_at_fa'] }} دوباره پر می‌شود.</div>
        </div>
    @endif
    @if ($historyRestricted)
        <x-upgrade-note cap="history.all">در پلن رایگان فقط فاکتورهای ماه جاری نمایش داده می‌شود. فاکتورهای قبلی حذف نشده‌اند و با ارتقا دیده می‌شوند.</x-upgrade-note>
    @endif

    <form class="stack-sm" data-filter role="search" action="{{ route('invoices.index') }}">
        <div class="field"><label for="q" class="sr-only">جستجو</label><div class="input-wrap"><input id="q" name="q" type="search" value="{{ $search }}" placeholder="جستجو: شماره، نام یا موبایل مشتری" autocomplete="off"></div></div>
        <div class="seg" role="radiogroup" aria-label="وضعیت">
            @foreach (['all' => 'همه', 'issued' => 'قطعی', 'draft' => 'پیش‌نویس', 'void' => 'باطل'] as $k => $label)
                <label><input type="radio" name="filter" value="{{ $k }}" @checked($filter === $k)>{{ $label }}</label>
            @endforeach
        </div>
        <div class="chips" role="group" aria-label="فیلترهای بیشتر">
            @unless ($historyRestricted)
                <label class="chip"><input type="checkbox" name="month" value="1" @checked($onlyMonth)>این ماه</label>
            @endunless
            @if ($canInstallments)
                <label class="chip"><input type="checkbox" name="installment" value="1" @checked($onlyInstallment)>اقساطی</label>
            @else
                {{-- Visible but locked: a tap explains the upgrade instead of the filter silently missing. --}}
                <x-locked cap="installments.manage" label="اقساطی" class="chip chip-locked" />
            @endif
        </div>
        <noscript><button class="btn btn-line">اعمال</button></noscript>
    </form>
    <p class="xs muted" data-count role="status">{{ fa($page->total()) }} مورد</p>
    <ul class="list" data-list aria-live="polite">
        @include('app.partials.invoice-rows')
    </ul>
</x-layouts.app>
