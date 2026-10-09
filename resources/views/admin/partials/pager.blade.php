@if ($paginator->hasPages())
    <nav class="pager" aria-label="صفحه‌ها">
        @if ($paginator->previousPageUrl())<a class="btn btn-line sm" href="{{ $paginator->previousPageUrl() }}">جدیدتر</a>@else<span></span>@endif
        <span>صفحه {{ fa($paginator->currentPage()) }}@if(method_exists($paginator, 'lastPage')) از {{ fa($paginator->lastPage()) }}@endif</span>
        @if ($paginator->nextPageUrl())<a class="btn btn-line sm" href="{{ $paginator->nextPageUrl() }}">قدیمی‌تر</a>@else<span></span>@endif
    </nav>
@endif
