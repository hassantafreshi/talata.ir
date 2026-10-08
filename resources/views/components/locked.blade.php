{{--
  A feature the current plan does not include. Tapping anything inside opens the «ارتقا» sheet (lib/upgrade.js)
  instead of doing nothing. Usage: <x-locked cap="invoice.customize">…the disabled controls…</x-locked>
  or <x-locked cap="…" label="…" /> for a single locked button.
--}}
@props(['cap', 'label' => null, 'compact' => false])
@php($info = \App\Domain\Plans\UpgradeInfo::for($cap))
@if ($slot->isEmpty())
    <button type="button" {{ $attributes->merge(['class' => str_contains((string) $attributes->get('class'), 'chip') ? 'locked-btn' : 'btn btn-line locked-btn']) }} data-upgrade="{{ $cap }}" data-upgrade-plans="{{ $info['plans'] }}" data-upgrade-price="{{ $info['price_fa'] }}">
        <span class="lock-ico" aria-hidden="true">🔒</span>{{ $label }}@unless(str_contains((string) $attributes->get('class'), 'chip'))<span class="lock-tag">{{ $info['plans'] }}</span>@endunless
    </button>
@else
    <div {{ $attributes->merge(['class' => 'locked-area'.($compact ? ' compact' : '')]) }}>
        <div class="locked-content" inert>{{ $slot }}</div>
        <button type="button" class="locked-cover" data-upgrade="{{ $cap }}" data-upgrade-plans="{{ $info['plans'] }}" data-upgrade-price="{{ $info['price_fa'] }}">
            <span class="lock-pill"><span aria-hidden="true">🔒</span> {{ $label ?? 'ویژه پلن '.$info['plans'] }} · <strong>ارتقا</strong></span>
        </button>
    </div>
@endif
