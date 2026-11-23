{{-- Shown in place of the page while a slow navigation loads (see resources/js/native.js). --}}
<div {{ $attributes->class('page-skeleton') }} aria-hidden="true">
    <div class="mb-6 space-y-2">
        <div class="skeleton h-3 w-24"></div>
        <div class="skeleton h-7 w-48"></div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach (range(1, 4) as $index)
            <div class="card space-y-3 p-4">
                <div class="skeleton h-3 w-16"></div>
                <div class="skeleton h-6 w-24"></div>
            </div>
        @endforeach
    </div>

    <div class="card divide-y divide-line">
        @foreach (range(1, 6) as $index)
            <div class="flex items-center gap-4 px-4 py-3.5">
                <div class="skeleton size-10 shrink-0 rounded-xl"></div>
                <div class="flex-1 space-y-2">
                    <div class="skeleton h-3.5" style="width: {{ [58, 44, 66, 38, 52, 47][$index - 1] }}%"></div>
                    <div class="skeleton h-2.5 w-1/4"></div>
                </div>
                <div class="skeleton h-4 w-16"></div>
            </div>
        @endforeach
    </div>
</div>
