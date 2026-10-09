@php($map = ['FRESH' => ['ok', 'به‌روز'], 'STALE' => ['warn', 'قدیمی'], 'ERROR' => ['err', 'خطا'], 'OFFLINE' => ['off', 'آفلاین']])
<span class="badge {{ $map[$f][0] ?? 'off' }}" data-freshness-badge>{{ $map[$f][1] ?? '—' }}</span>
