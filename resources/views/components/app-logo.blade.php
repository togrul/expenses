@props([
    'sidebar' => false,
])

<a {{ $attributes->class('group flex items-center gap-3') }}>
    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#ecd8a6] via-[#d4b06a] to-[#a8823d] text-zinc-950 shadow-[inset_0_1px_0_rgb(255_255_255/0.5),0_6px_16px_-6px_rgb(212_176_106/0.6)]">
        <x-app-logo-icon class="size-5" />
    </span>
    <span class="leading-tight">
        <span class="block text-[15px] font-semibold tracking-tight text-ink">{{ config('app.name') }}</span>
        @if ($sidebar)
            <span class="block text-[11px] text-faint">{{ __('Şəxsi büdcə') }}</span>
        @endif
    </span>
</a>
