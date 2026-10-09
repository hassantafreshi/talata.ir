<x-layouts.public :scripts="false" title="{{ ! empty($revoked) ? 'بارکد لغوشده' : 'لینک نامعتبر' }}">
    <section class="hero stack-sm">
        @if (! empty($revoked))
            <span class="badge err">لغوشده</span>
            <h1 class="h2">فروشنده این بارکد را به دلایل امنیتی لغو کرده است.</h1>
            <p class="meta">این برگه دیگر برای بررسی اصالت معتبر نیست. برگه تازه فاکتور را از همان فروشنده بخواهید.</p>
        @else
            <span class="badge off">پیدا نشد</span>
            <h1 class="h2">{{ ! empty($share) ? 'این لینک فاکتور معتبر نیست یا فروشنده آن را غیرفعال کرده است.' : 'فاکتوری با این کد بررسی در زرلیو پیدا نشد.' }}</h1>
            <p class="meta">اگر این کد را از روی یک برگه فاکتور اسکن کرده‌اید، آن برگه با رکورد زرلیو تطبیق ندارد. از فروشنده توضیح بخواهید.</p>
        @endif
    </section>
    @if (! empty($share))<p class="small muted">بارکد «بررسی اصالت» روی فاکتور چاپی همچنان قابل استفاده است.</p>@endif
</x-layouts.public>
