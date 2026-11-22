{{-- Live search box bound to a Livewire property. --}}
@props(['model' => 'search', 'placeholder' => null])

<div {{ $attributes->class('relative') }}>
    <flux:icon name="magnifying-glass" variant="mini" class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-faint" />
    <input
        type="search"
        wire:model.live.debounce.300ms="{{ $model }}"
        placeholder="{{ $placeholder ?? __('Axtar…') }}"
        aria-label="{{ $placeholder ?? __('Axtar') }}"
        class="h-10 w-full rounded-xl border border-line bg-surface ps-9 pe-3 text-sm text-ink placeholder:text-faint transition outline-none focus:border-gold/60 focus:ring-2 focus:ring-gold/20 [&::-webkit-search-cancel-button]:cursor-pointer"
    />
</div>
