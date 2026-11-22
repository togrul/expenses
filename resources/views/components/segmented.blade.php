{{-- Pill segmented control. $options: value => label; $model: Livewire property name. --}}
@props(['options', 'model', 'value'])

<div {{ $attributes->class('inline-flex items-center gap-0.5 rounded-xl border border-line bg-surface p-1') }} role="tablist">
    @foreach ($options as $optionValue => $label)
        <button
            type="button"
            role="tab"
            aria-selected="{{ (string) $value === (string) $optionValue ? 'true' : 'false' }}"
            wire:click="$set('{{ $model }}', '{{ $optionValue }}')"
            @class([
                'cursor-pointer rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors duration-200',
                'bg-ink text-canvas shadow-sm' => (string) $value === (string) $optionValue,
                'text-muted hover:text-ink' => (string) $value !== (string) $optionValue,
            ])
        >{{ $label }}</button>
    @endforeach
</div>
