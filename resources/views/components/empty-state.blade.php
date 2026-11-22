@props(['icon' => 'inbox', 'heading', 'text' => null])

<div {{ $attributes->class('flex flex-col items-center justify-center rounded-2xl border border-dashed border-line-strong px-6 py-12 text-center') }}>
    <span class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-gold-soft text-gold">
        <flux:icon :name="$icon" variant="outline" class="size-6" />
    </span>
    <div class="text-sm font-semibold text-ink">{{ $heading }}</div>
    @if ($text)
        <p class="mt-1 max-w-sm text-sm text-muted">{{ $text }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
