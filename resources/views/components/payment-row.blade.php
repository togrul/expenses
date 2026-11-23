{{--
    A checklist row for a debt installment or recurring occurrence.
    The round checkbox opens the pay (or undo) dialog handled by <livewire:pay-modal />.
    The square toggle on the right adds the row to the "sum selected" bar.
--}}
@props([
    'kind',
    'id',
    'title',
    'subtitle' => null,
    'dueOn' => null,
    'amount',
    'paid' => false,
    'paidAt' => null,
    'paidAmount' => null,
    'skipped' => false,
    'remindDaysBefore' => 3,
    'href' => null,
    'selectable' => true,
])

@php
    $today = today();
    $isOverdue = ! $paid && ! $skipped && $dueOn && $dueOn->lt($today);
    $isSoon = ! $paid && ! $skipped && ! $isOverdue && $dueOn && $dueOn->lte($today->addDays($remindDaysBefore));
    $shownAmount = $paid && $paidAmount !== null ? $paidAmount : $amount;
    $selectKey = "{$kind}-{$id}";
@endphp

<div
    x-data
    x-bind:class="$store.selection.has(@js($selectKey)) && 'ring-1 ring-gold/60 bg-gold-soft'"
    {{ $attributes->class([
        'group relative flex items-center gap-3.5 rounded-xl border bg-surface px-3.5 py-3 transition duration-200',
        'border-negative/25' => $isOverdue,
        'border-warning/25' => $isSoon,
        'border-line' => ! $isOverdue && ! $isSoon,
    ]) }}
>
    @if ($isOverdue || $isSoon)
        <span @class(['absolute inset-y-3 start-0 w-[3px] rounded-e-full', 'bg-negative' => $isOverdue, 'bg-warning' => $isSoon])></span>
    @endif

    @if ($skipped)
        <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-dashed border-line-strong text-faint">
            <flux:icon name="forward" variant="micro" />
        </span>
    @else
        <button
            type="button"
            role="checkbox"
            aria-checked="{{ $paid ? 'true' : 'false' }}"
            aria-label="{{ $paid ? __('Ödənişi geri al') : __('Ödənildi kimi qeyd et') }}"
            x-on:click="$dispatch('{{ $paid ? 'undo-item' : 'pay-item' }}', { kind: '{{ $kind }}', id: {{ $id }} })"
            @class([
                'relative flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full border-[1.5px] transition duration-200 before:absolute before:-inset-2.5 before:content-[\'\']',
                'border-positive bg-positive text-white dark:text-zinc-950' => $paid,
                'border-line-strong hover:border-positive hover:bg-positive/10' => ! $paid,
            ])
        >
            @if ($paid)
                <flux:icon name="check" variant="micro" />
            @endif
        </button>
    @endif

    <div class="min-w-0 flex-1">
        <div @class(['truncate text-sm font-medium', 'text-faint line-through decoration-line-strong' => $paid || $skipped, 'text-ink' => ! $paid && ! $skipped])>
            @if ($href)
                <a href="{{ $href }}" wire:navigate.hover class="hover:text-gold">{{ $title }}</a>
            @else
                {{ $title }}
            @endif
        </div>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-faint">
            @if ($subtitle)
                <span class="truncate">{{ $subtitle }}</span>
                <span aria-hidden="true">·</span>
            @endif
            @if ($paid)
                <span class="text-positive">{{ __('Ödənilib') }} {{ \App\Support\Preferences::formatDate($paidAt) }}</span>
            @elseif ($skipped)
                <span>{{ __('Keçildi') }}</span>
            @elseif ($dueOn)
                <span @class(['font-medium text-negative' => $isOverdue, 'font-medium text-warning' => $isSoon])>
                    {{ \App\Support\Preferences::formatDate($dueOn) }}
                    @if ($isOverdue)
                        — {{ __(':n gün gecikir', ['n' => (int) $dueOn->diffInDays($today)]) }}
                    @elseif ($dueOn->isToday())
                        — {{ __('bu gün') }}
                    @elseif ($isSoon)
                        — {{ __(':n gün qalıb', ['n' => (int) $today->diffInDays($dueOn)]) }}
                    @endif
                </span>
            @else
                <span>{{ __('Tarixsiz') }}</span>
            @endif
        </div>
    </div>

    <x-money :amount="$shownAmount" @class(['text-[15px] font-semibold', 'text-faint line-through' => $paid || $skipped, 'text-ink' => ! $paid && ! $skipped]) />

    @if ($selectable && ! $skipped)
        <x-select-toggle :key="$selectKey" :amount="$shownAmount" :label="$title" />
    @endif

    @isset($actions)
        {{ $actions }}
    @endisset
</div>
