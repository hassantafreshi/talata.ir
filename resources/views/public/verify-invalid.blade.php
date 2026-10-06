<x-layouts.public title="لینک نامعتبر">
    <section class="hero stack-sm">
        <span class="badge err">پیدا نشد</span>
        <h2>{{ ! empty($share) ? 'این لینک فاکتور معتبر نیست یا فروشنده آن را غیرفعال کرده است.' : 'فاکتوری با این کد بررسی در زرلیو پیدا نشد.' }}</h2>
        <p class="meta">اگر این کد را از روی یک برگه فاکتور اسکن کرده‌اید، آن برگه با رکورد زرلیو تطبیق ندارد. از فروشنده توضیح بخواهید.</p>
    </section>
    @if (! empty($share))<p class="small muted">بارکد «بررسی اصالت» روی فاکتور چاپی همچنان قابل استفاده است.</p>@endif
</x-layouts.public>
