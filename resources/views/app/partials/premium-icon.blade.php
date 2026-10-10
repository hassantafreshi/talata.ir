{{-- A small gem/diamond badge for "available on a higher plan" — friendlier than a padlock. Pass $size (px, default 14). --}}
@php($size = $size ?? 14)
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h10l4 5.5L12 21 3 8.5Z" fill="currentColor" fill-opacity=".12"/><path d="M3 8.5h18M9 3l1.5 5.5L12 21l1.5-12.5L15 3"/></svg>
