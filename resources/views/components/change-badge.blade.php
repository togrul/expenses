@props(['change', 'inverse' => false])

@if ($change !== null)
    @php
        $isUp = $change > 0;
        $isGood = $inverse ? ! $isUp : $isUp;
    @endphp
    <span @class([
        'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold num',
        'bg-positive/10 text-positive' => $change != 0 && $isGood,
        'bg-negative/10 text-negative' => $change != 0 && ! $isGood,
        'bg-line text-muted' => $change == 0,
    ])>
        {{ $change > 0 ? '↑' : ($change < 0 ? '↓' : '') }}{{ abs($change) }}%
    </span>
    <span class="ms-1">{{ __('keçən aya görə') }}</span>
@else
    <span>{{ __('keçən ay məlumat yoxdur') }}</span>
@endif
