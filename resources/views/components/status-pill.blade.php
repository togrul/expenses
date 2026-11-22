@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\DebtStatus::Overdue => 'bg-negative/10 text-negative ring-negative/20',
        \App\Enums\DebtStatus::Paid => 'bg-positive/10 text-positive ring-positive/20',
        default => 'bg-gold-soft text-gold ring-gold/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset', $classes]) }}>
    <span class="size-1.5 rounded-full bg-current"></span>
    {{ $status->label() }}
</span>
