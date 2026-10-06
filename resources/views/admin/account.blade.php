<x-layouts.admin title="حساب من" page="admin-account">
    <section class="band stack-sm narrow">
        <dl class="kv"><div><dt>نام</dt><dd>{{ $staff->name }}</dd></div><div><dt>نقش</dt><dd>{{ \App\Models\StaffUser::ROLES[$staff->role] ?? $staff->role }}</dd></div></dl>
        <h2>کلید عبور (اثر انگشت)</h2>
        <ul class="list" data-passkey-list>
            @foreach ($passkeys as $pk)
                <li class="list-item"><span class="body"><strong>{{ $pk->name }}</strong><span class="sub">{{ jdate($pk->created_at) }}@if($pk->last_used_at) · آخرین استفاده {{ jdate($pk->last_used_at, true) }}@endif</span></span><button class="btn btn-link sm" type="button" data-passkey-remove="{{ $pk->id }}">حذف</button></li>
            @endforeach
        </ul>
        <button class="btn btn-dark block" type="button" data-passkey-add data-busy-text="منتظر کلید…">افزودن کلید عبور</button>
    </section>
</x-layouts.admin>
