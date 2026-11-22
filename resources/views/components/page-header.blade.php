@props(['heading', 'subheading' => null, 'eyebrow' => null])

<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @if ($eyebrow)
            <div class="eyebrow mb-2">{{ $eyebrow }}</div>
        @endif
        <h1 class="text-2xl font-semibold tracking-tight text-ink sm:text-[1.75rem]">{{ $heading }}</h1>
        @if ($subheading)
            <p class="mt-1.5 text-sm text-muted">{{ $subheading }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
