<x-layouts.admin title="لاگ فعالیت کاربران و سرویس‌ها">
    <p class="small muted">هر کار مهم کاربران، سیستم و مدیران؛ غیرقابل ویرایش و حذف (append-only). برای فعالیت یک کاربر روی موبایلش بزنید.</p>
    @include('admin.partials.activity-filters', ['withMobile' => true])
    @include('admin.partials.activity-table')
</x-layouts.admin>
