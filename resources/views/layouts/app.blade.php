<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="!px-4 pb-[calc(7rem+env(safe-area-inset-bottom))] sm:!px-8 lg:!px-12 lg:pb-12 lg:pt-10">
        <div class="mx-auto w-full max-w-7xl">
            {{ $slot }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
