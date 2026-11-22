{{-- Floating total of the currently selected rows/cards (see Alpine store "selection"). --}}
<div
    x-data="{ symbol: @js(\App\Support\Money::symbol()) }"
    x-cloak
    x-show="$store.selection.count > 0"
    x-transition:enter="transition duration-200 ease-out"
    x-transition:enter-start="translate-y-4 opacity-0"
    x-transition:leave="transition duration-150 ease-in"
    x-transition:leave-end="translate-y-4 opacity-0"
    x-on:keydown.escape.window="$store.selection.clear()"
    class="fixed inset-x-0 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-50 flex justify-center px-4 lg:bottom-8 lg:ps-64"
    role="status"
    aria-live="polite"
>
    <div class="flex items-center gap-4 rounded-2xl border border-line-strong bg-zinc-900/95 py-2.5 ps-4 pe-2.5 text-white shadow-2xl shadow-black/40 backdrop-blur-xl dark:bg-zinc-800/95">
        <div class="flex items-center gap-2">
            <span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-gold px-1.5 text-xs font-bold text-zinc-950 num" x-text="$store.selection.count"></span>
            <span class="text-sm text-zinc-300">{{ __('seçildi') }}</span>
        </div>

        <div class="h-6 w-px bg-white/10"></div>

        <div class="flex items-baseline gap-2">
            <span class="text-xs uppercase tracking-[0.035em] text-zinc-400">{{ __('Cəmi') }}</span>
            <span class="text-lg font-semibold num" x-text="$store.selection.format($store.selection.total, symbol)"></span>
        </div>

        <template x-if="$store.selection.positive !== 0 && $store.selection.negative !== 0">
            <div class="hidden items-center gap-3 text-xs num sm:flex">
                <span class="text-emerald-400" x-text="'+' + $store.selection.format($store.selection.positive, symbol)"></span>
                <span class="text-rose-400" x-text="$store.selection.format($store.selection.negative, symbol)"></span>
            </div>
        </template>

        <button type="button" x-on:click="$store.selection.clear()" class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-zinc-400 transition hover:bg-white/10 hover:text-white" aria-label="{{ __('Seçimi təmizlə') }}">
            <flux:icon name="x-mark" variant="mini" />
        </button>
    </div>
</div>
