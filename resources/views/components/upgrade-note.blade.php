{{-- One-line «this is on a higher plan» note with an upgrade button that opens the «ارتقا» sheet. --}}
@props(['cap'])
@php($info = \App\Domain\Plans\UpgradeInfo::for($cap))
<div {{ $attributes->merge(['class' => 'notice upgrade-note']) }}>
    <span class="small">{{ $slot }}</span>
    <button type="button" class="btn btn-gold sm" data-upgrade="{{ $cap }}" data-upgrade-plans="{{ $info['plans'] }}" data-upgrade-price="{{ $info['price_fa'] }}"><span aria-hidden="true">🔒</span> ارتقا</button>
</div>
