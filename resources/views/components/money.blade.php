@props(['amount', 'currency' => null, 'signed' => false, 'type' => null])

@php
    $amount = (int) $amount;
    $isIncome = $type === \App\Enums\TransactionType::Income;
    $prefix = $signed && $type !== null ? ($isIncome ? '+' : '−') : ($amount < 0 ? '−' : '');
    $absolute = abs($amount);
    $major = number_format(intdiv($absolute, 100), 0, ',', ' ');
    $minor = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
@endphp

<span {{ $attributes->class('num whitespace-nowrap') }}>{{ $prefix }}{{ $major }}<span class="opacity-55">,{{ $minor }}</span><span class="ms-[0.2em] opacity-55">{{ \App\Support\Money::symbol($currency) }}</span></span>
