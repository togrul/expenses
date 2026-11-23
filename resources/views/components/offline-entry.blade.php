{{-- Quick entry used while the phone is offline; saved locally and synced later (resources/js/native.js). --}}
@php
    $offlineCategories = auth()->user()->categories()->whereNull('archived_at')->ordered()->get()
        ->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name,
            'color' => $category->color,
            'type' => $category->type instanceof \BackedEnum ? $category->type->value : $category->type,
        ])
        ->values();
@endphp

<div x-data="offlineEntry(@js($offlineCategories))" x-on:open-offline-entry.window="open($event.detail)">
    <dialog x-ref="sheet" class="picker-sheet" aria-label="{{ __('Offline əməliyyat') }}" x-on:click.self="close()" x-on:cancel.prevent="close()">
        <div class="picker-handle" aria-hidden="true"></div>

        <div class="flex items-start justify-between gap-3 px-5 pb-3 pt-1">
            <div>
                <h3 class="text-[17px] font-semibold text-ink">{{ __('Offline əməliyyat') }}</h3>
                <p class="text-xs text-faint">{{ __('İnternet gələndə avtomatik göndəriləcək.') }}</p>
            </div>
            <button type="button" x-on:click="close()" class="flex size-8 cursor-pointer items-center justify-center rounded-full bg-surface-2 text-muted" aria-label="{{ __('Bağla') }}">
                <flux:icon name="x-mark" variant="micro" />
            </button>
        </div>

        <form x-on:submit.prevent="save()" class="space-y-4 overflow-y-auto px-5 pb-4" data-sheet-scroll>
            <div class="grid grid-cols-2 gap-2">
                @foreach (\App\Enums\TransactionType::cases() as $case)
                    <button
                        type="button"
                        x-on:click="type = @js($case->value); categoryId = null"
                        x-bind:class="type === @js($case->value) ? 'border-transparent bg-gold text-zinc-950' : 'border-line-strong text-muted'"
                        class="h-10 cursor-pointer rounded-xl border text-sm font-semibold transition"
                    >{{ $case->label() }}</button>
                @endforeach
            </div>

            <x-amount-input x-model="amount" :label="__('Məbləğ')" />

            <div class="flex flex-wrap gap-1.5">
                <template x-for="category in visibleCategories" x-bind:key="category.id">
                    <button
                        type="button"
                        x-on:click="categoryId = categoryId === category.id ? null : category.id"
                        x-bind:class="categoryId === category.id ? 'border-gold/40 bg-gold-soft text-gold' : 'border-line-strong text-muted'"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium"
                    >
                        <span class="size-2 rounded-full" x-bind:style="{ background: category.color || '#a1a1aa' }"></span>
                        <span x-text="category.name"></span>
                    </button>
                </template>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <input type="date" x-model="occurredOn" aria-label="{{ __('Tarix') }}" class="h-10 rounded-lg border border-line-strong bg-surface-2 px-3 text-ink" />
                <input type="text" x-model="note" placeholder="{{ __('Qeyd') }}" maxlength="255" class="h-10 rounded-lg border border-line-strong bg-surface-2 px-3 text-ink placeholder:text-faint" />
            </div>

            <p x-cloak x-show="error" x-text="error" class="text-sm text-negative"></p>

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Telefonda saxla') }}</flux:button>
        </form>
    </dialog>
</div>
