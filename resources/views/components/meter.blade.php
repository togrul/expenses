{{-- Thin progress meter. --}}
@props(['value', 'tone' => 'gold'])

@php
    $fill = match ($tone) {
        'negative' => 'bg-negative',
        'positive' => 'bg-positive',
        default => 'bg-gradient-to-r from-[#a8823d] via-[#d4b06a] to-[#ecd8a6]',
    };
@endphp

<div {{ $attributes->class('h-1.5 w-full overflow-hidden rounded-full bg-line') }} role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
    <div class="h-full rounded-full {{ $fill }} transition-[width] duration-500" style="width: {{ max(0, min(100, $value)) }}%"></div>
</div>
