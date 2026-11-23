@props(['label', 'tone' => null, 'icon' => null, 'href' => null])

@php
    $iconTone = match ($tone) {
        'positive' => 'bg-positive/10 text-positive',
        'negative' => 'bg-negative/10 text-negative',
        'warning' => 'bg-warning/10 text-warning',
        default => 'bg-gold-soft text-gold',
    };
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate.hover @endif {{ $attributes->class(['card relative overflow-hidden p-5', 'card-hover' => $href, 'ring-1 ring-negative/30' => $tone === 'negative']) }}>
    <div class="flex items-center justify-between gap-2">
        <span class="eyebrow">{{ $label }}</span>
        @if ($icon)
            <span class="flex size-8 items-center justify-center rounded-lg {{ $iconTone }}">
                <flux:icon :name="$icon" variant="micro" />
            </span>
        @endif
    </div>
    <div class="mt-3 text-[1.625rem] font-semibold leading-none tracking-tight text-ink">
        {{ $slot }}
    </div>
    @isset($footer)
        <div class="mt-3 text-xs text-muted">{{ $footer }}</div>
    @endisset
</{{ $tag }}>
