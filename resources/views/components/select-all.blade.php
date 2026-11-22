{{-- "Select all" control for the sum bar: toggles every given [key, amount, label] entry. --}}
@props(['entries', 'label' => null])

<button
    type="button"
    x-data="{ entries: @js($entries) }"
    x-on:click.stop="$store.selection.setMany(entries, ! $store.selection.allSelected(entries))"
    x-bind:aria-pressed="$store.selection.allSelected(entries)"
    @disabled(count($entries) === 0)
    {{ $attributes->class('group/select inline-flex cursor-pointer items-center gap-2.5 rounded-lg py-1 pe-2 text-xs font-medium text-muted transition hover:text-ink disabled:cursor-default disabled:opacity-40') }}
>
    <span class="flex size-5 shrink-0 items-center justify-center rounded-md border border-line-strong bg-surface transition duration-150 group-hover/select:border-gold group-aria-pressed/select:border-gold group-aria-pressed/select:bg-gold">
        <svg class="size-3 text-accent-foreground opacity-0 transition group-aria-pressed/select:opacity-100" viewBox="0 0 12 12" fill="none" aria-hidden="true">
            <path d="M2.5 6.2 5 8.5l4.5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </span>
    <span x-text="$store.selection.allSelected(entries) ? @js(__('Seçimi ləğv et')) : @js($label ?? __('Hamısını seç'))">{{ $label ?? __('Hamısını seç') }}</span>
</button>
