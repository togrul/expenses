{{--
    Money field with a calculator keypad on phones ("12+8,5" → 20,50).
    <x-amount-input wire:model="amount" :label="__('Məbləğ')" autofocus />
--}}
@props([
    'label' => null,
    'description' => null,
    'disabled' => false,
    'autofocus' => false,
])

@php($model = $attributes->wire('model')->value())

<div {{ $attributes->whereDoesntStartWith('wire:model')->whereDoesntStartWith('x-model')->class('grid gap-2') }}>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    <div
        x-data="amountPad"
        x-modelable="value"
        {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }}
        x-on:focusout="if (! $el.contains($event.relatedTarget)) finish()"
    >
        <div class="relative">
            <input
                x-ref="input"
                type="text"
                x-bind:inputmode="touch ? 'none' : 'decimal'"
                x-bind:value="touch ? display : expr"
                x-on:input="typed($event)"
                x-on:focus="padOpen = touch"
                x-on:keydown.enter="if (hasOperator) { $event.preventDefault(); finish() }"
                placeholder="0,00"
                autocomplete="off"
                enterkeyhint="done"
                aria-label="{{ $label ?? __('Məbləğ') }}"
                @disabled($disabled)
                @if ($autofocus) autofocus @endif
                class="num h-14 w-full rounded-xl border border-zinc-200 border-b-zinc-300/80 bg-white ps-4 pe-12 text-2xl font-semibold tracking-tight text-zinc-900 caret-gold shadow-xs outline-none transition placeholder:text-zinc-400 focus:border-gold/60 focus:ring-2 focus:ring-gold/20 disabled:opacity-60 dark:border-white/10 dark:bg-white/10 dark:text-white dark:placeholder:text-zinc-500"
            />
            <span class="pointer-events-none absolute end-4 top-1/2 -translate-y-1/2 text-lg font-medium text-faint">{{ \App\Support\Money::symbol() }}</span>
        </div>

        <p x-cloak x-show="preview" class="num mt-1.5 text-sm font-medium text-gold">= <span x-text="preview"></span> {{ \App\Support\Money::symbol() }}</p>

        <div
            x-cloak
            x-show="padOpen"
            x-transition:enter="transition duration-200 ease-out"
            x-transition:enter-start="opacity-0 -translate-y-1"
            class="amount-pad mt-3 grid grid-cols-4 gap-2"
            data-no-drag
        >
            @foreach (['7', '8', '9', 'back', '4', '5', '6', '-', '1', '2', '3', '+', ',', '0', 'clear', 'done'] as $key)
                <button
                    type="button"
                    tabindex="-1"
                    x-on:pointerdown.prevent
                    x-on:mousedown.prevent
                    x-on:click="press(@js($key))"
                    @class([
                        'amount-key',
                        'is-operator' => in_array($key, ['-', '+', 'back']),
                        'is-done' => $key === 'done',
                    ])
                    aria-label="{{ match ($key) { 'back' => __('Sil'), 'clear' => __('Təmizlə'), 'done' => __('Hazır'), '-' => __('Çıx'), '+' => __('Əlavə et'), default => $key } }}"
                >
                    @switch($key)
                        @case('back')
                            <flux:icon name="backspace" variant="outline" class="size-6" />
                            @break
                        @case('clear')
                            <span class="text-base">C</span>
                            @break
                        @case('done')
                            <flux:icon name="check" class="size-6" />
                            @break
                        @case('-')
                            −
                            @break
                        @default
                            {{ $key }}
                    @endswitch
                </button>
            @endforeach
        </div>
    </div>

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    @if ($model)
        <flux:error :name="$model" />
    @endif
</div>
