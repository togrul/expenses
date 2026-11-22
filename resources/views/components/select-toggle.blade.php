{{--
    Selection checkbox for the "sum selected" bar.
    $key must be unique on the page; $amount is signed minor units.
--}}
@props(['key', 'amount', 'label' => ''])

<button
    type="button"
    x-data
    x-on:click.stop.prevent="$store.selection.toggle(@js($key), {{ (int) $amount }}, @js($label))"
    x-bind:aria-pressed="$store.selection.has(@js($key))"
    aria-label="{{ __('Cəmləmək üçün seç') }}"
    {{ $attributes->class('group/select relative flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line-strong bg-surface transition duration-150 hover:border-gold aria-pressed:border-gold aria-pressed:bg-gold before:absolute before:-inset-2.5 before:content-[\'\']') }}
>
    <svg class="size-3 text-accent-foreground opacity-0 transition group-aria-pressed/select:opacity-100" viewBox="0 0 12 12" fill="none" aria-hidden="true">
        <path d="M2.5 6.2 5 8.5l4.5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</button>
