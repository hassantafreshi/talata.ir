{{-- Team access picker: one-tap presets + grouped checklist (docs/TEAM_PERMISSIONS.md). $selected = granted keys, $uid = unique id prefix. --}}
@php $sel = $selected ?? \App\Models\Membership::allPermissions(); @endphp
<div class="perm-picker stack-sm" data-perm-picker>
    <fieldset class="field">
        <legend class="label">نقش آماده (بعد از انتخاب می‌توانید تیک‌ها را عوض کنید)</legend>
        <div class="chips" role="group">
            @foreach (\App\Models\Membership::PRESETS as $key => $preset)
                <button type="button" class="chip" data-preset="{{ $key }}" data-perms='@json($preset['permissions'] ?? \App\Models\Membership::allPermissions())' title="{{ $preset['hint'] }}">{{ $preset['label'] }}</button>
            @endforeach
        </div>
        <p class="xs muted" data-preset-hint></p>
    </fieldset>
    @foreach (\App\Models\Membership::GROUPS as $group => $keys)
        <fieldset class="perm-group">
            <legend class="small strong">{{ $group }}</legend>
            <div class="perm-grid">
                @foreach ($keys as $key)
                    <label class="choice"><input type="checkbox" data-perm value="{{ $key }}" @checked(in_array($key, $sel, true)) @if(isset(\App\Models\Membership::IMPLIES[$key])) data-needs="{{ implode(',', \App\Models\Membership::IMPLIES[$key]) }}" @endif><span>{{ \App\Models\Membership::PERMISSIONS[$key] }}</span></label>
                @endforeach
            </div>
        </fieldset>
    @endforeach
    <p class="xs muted" data-perm-summary aria-live="polite"></p>
</div>
