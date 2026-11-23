{{--
    Select replacement: a tappable field that opens a bottom sheet on phones
    (a centred list on desktop). Bind it with wire:model like a select.

    <x-picker wire:model.live="categoryId" :label="__('Kateqoriya')" :options="[['value' => 1, 'label' => 'Qida', 'color' => '#f59e0b']]" />
--}}
@props([
    'label' => null,
    'description' => null,
    'placeholder' => null,
    'options' => [],
    'size' => null,
    'disabled' => false,
    'searchable' => null,
    'title' => null,
])

@php
    $model = $attributes->wire('model')->value();
    $options = collect($options)->map(fn ($option) => is_array($option) ? $option : ['value' => $option, 'label' => (string) $option])->values();
    $searchable ??= $options->count() > 8;
    $title ??= $label ?? $attributes->get('aria-label');
    $placeholder ??= __('Seçin');
    $small = $size === 'sm';
@endphp

<div {{ $attributes->whereDoesntStartWith('wire:model')->except('aria-label')->class(['min-w-0', 'grid gap-2' => $label || $description]) }} data-picker>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    <div
        x-data="picker(@js($placeholder))"
        x-modelable="value"
        {{ $attributes->whereStartsWith('wire:model') }}
        x-on:keydown.escape.prevent.stop="close()"
    >
        <button
            type="button"
            x-on:click="open()"
            @disabled($disabled)
            aria-haspopup="dialog"
            aria-label="{{ $title }}"
            @class([
                'flex w-full cursor-pointer items-center gap-2.5 rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white text-start text-zinc-700 shadow-xs transition outline-none dark:border-white/10 dark:bg-white/10 dark:text-zinc-300',
                'focus-visible:border-gold/60 focus-visible:ring-2 focus-visible:ring-gold/20 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60',
                'h-8 px-2.5 text-sm' => $small,
                'h-10 px-3 text-base sm:text-sm' => ! $small,
            ])
        >
            <span x-cloak x-show="selected?.color" class="size-2.5 shrink-0 rounded-full" x-bind:style="{ background: selected?.color || '' }"></span>
            <span class="flex-1 truncate" x-bind:class="selected ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500'" x-text="selected ? selected.label : placeholder">{{ $placeholder }}</span>
            <flux:icon name="chevron-up-down" variant="micro" class="shrink-0 text-zinc-400" />
        </button>

        <dialog
            wire:ignore.self
            x-ref="sheet"
            class="picker-sheet"
            aria-label="{{ $title }}"
            x-on:click.self="close()"
            x-on:cancel.prevent="close()"
            x-on:close="query = ''"
        >
            <div class="picker-handle" aria-hidden="true"></div>

            <div class="flex items-center justify-between gap-3 px-5 pb-3 pt-1">
                <h3 class="text-[17px] font-semibold text-ink">{{ $title ?? __('Seçin') }}</h3>
                <button type="button" x-on:click="close()" class="flex size-8 cursor-pointer items-center justify-center rounded-full bg-surface-2 text-muted transition active:scale-95" aria-label="{{ __('Bağla') }}">
                    <flux:icon name="x-mark" variant="micro" />
                </button>
            </div>

            @if ($searchable)
                <div class="px-4 pb-2">
                    <div class="relative">
                        <flux:icon name="magnifying-glass" variant="micro" class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-faint" />
                        <input
                            type="search"
                            x-model="query"
                            placeholder="{{ __('Axtar…') }}"
                            enterkeyhint="search"
                            class="h-10 w-full rounded-xl border border-line bg-surface-2 ps-9 pe-3 text-ink placeholder:text-faint outline-none focus:border-gold/60"
                        />
                    </div>
                </div>
            @endif

            <div x-ref="list" class="picker-list" role="listbox" data-sheet-scroll>
                @foreach ($options as $option)
                    @php($optionLabel = (string) $option['label'])
                    <button
                        type="button"
                        role="option"
                        wire:key="picker-{{ $model }}-{{ $option['value'] ?? 'none' }}"
                        data-value="{{ $option['value'] }}"
                        data-label="{{ $optionLabel }}"
                        data-color="{{ $option['color'] ?? '' }}"
                        x-show="matches(@js(mb_strtolower($optionLabel)))"
                        x-on:click="choose(@js($option['value']))"
                        x-bind:aria-selected="isSelected(@js($option['value'])) ? 'true' : 'false'"
                        class="picker-option"
                    >
                        @if (! empty($option['color']))
                            <span class="size-3 shrink-0 rounded-full" style="background: {{ $option['color'] }}"></span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $optionLabel }}</span>
                            @if (! empty($option['description']))
                                <span class="block truncate text-xs font-normal text-faint">{{ $option['description'] }}</span>
                            @endif
                        </span>
                        <flux:icon name="check" variant="mini" class="picker-check size-5 shrink-0" />
                    </button>
                @endforeach

                <p x-cloak x-show="! hasMatches" class="px-4 py-8 text-center text-sm text-faint">{{ __('Heç nə tapılmadı') }}</p>
            </div>
        </dialog>
    </div>

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    @if ($model)
        <flux:error :name="$model" />
    @endif
</div>
