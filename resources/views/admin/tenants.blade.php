<x-layouts.admin title="فروشگاه‌ها">
    <form class="filters band" method="get"><label class="small">نام یا شناسه<input name="q" value="{{ $search }}"></label><button class="btn btn-dark" type="submit">جستجو</button></form>
    <div class="table-wrap"><table class="t">
        <thead><tr><th>نام</th><th>شناسه</th><th>ساخت</th><th>وضعیت</th></tr></thead>
        <tbody>
        @forelse ($page as $t)
            <tr><td><a href="{{ route('admin.tenant', $t->id) }}">{{ $t->profile?->name ?: 'بدون نام' }}</a></td><td class="mono">{{ $t->public_id }}</td><td class="n">{{ jdate($t->created_at) }}</td><td>{{ $t->isActive() ? 'فعال' : 'غیرفعال' }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted center">فروشگاهی پیدا نشد.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $page->links('admin.partials.pager') }}
</x-layouts.admin>
