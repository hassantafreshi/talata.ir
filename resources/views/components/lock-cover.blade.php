{{-- Tap-catcher over a locked container (give the container class="locked-area"): opens the «ارتقا» sheet. --}}
@props(['cap', 'label' => null])
@php($info = \App\Domain\Plans\UpgradeInfo::for($cap))
<button type="button" class="locked-cover" data-upgrade="{{ $cap }}" data-upgrade-plans="{{ $info['plans'] }}" data-upgrade-price="{{ $info['price_fa'] }}" aria-label="{{ $label ?? 'ویژه پلن '.$info['plans'] }} — ارتقا">
    <span class="lock-pill"><span aria-hidden="true">🔒</span> {{ $label ?? 'ویژه پلن '.$info['plans'] }} · <strong>ارتقا</strong></span>
</button>
