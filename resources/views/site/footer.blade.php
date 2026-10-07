<footer class="site-footer small">
    <nav aria-label="پیوندها" class="cluster">
        <a href="{{ route('site.home') }}">زرلیو</a>
        <a href="{{ route('site.terms') }}">قوانین و مقررات</a>
        <a href="{{ route('site.privacy') }}">حریم خصوصی</a>
        <a href="{{ route('login') }}">ورود</a>
    </nav>
    @if ($support ?? null)<p>پشتیبانی: <a class="num ltr" dir="ltr" href="tel:{{ $support }}">{{ fa($support) }}</a></p>@endif
    <p class="xs muted"><span dir="ltr">zarlio.ir</span></p>
</footer>
