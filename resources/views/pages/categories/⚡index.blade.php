<?php

use App\Enums\TransactionType;
use App\Models\Category;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Kateqoriyalar')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $type = 'expense';

    public string $name = '';

    public string $color = '#71717a';

    public bool $showArchived = false;

    public string $search = '';

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Auth::user()->categories()
            ->withCount('transactions')
            ->when(! $this->showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->when(trim($this->search) !== '', fn ($query) => $query->whereLike('name', '%'.addcslashes(trim($this->search), '%_\\').'%'))
            ->ordered()
            ->get();
    }

    public function create(string $type = 'expense'): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name');
        $this->type = $type === 'income' ? 'income' : 'expense';
        $this->color = '#71717a';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $category = Auth::user()->categories()->findOrFail($id);
        $this->authorize('update', $category);

        $this->resetValidation();
        $this->editingId = $category->id;
        $this->type = $category->type->value;
        $this->name = $category->name;
        $this->color = $category->color ?? '#71717a';
        $this->showForm = true;
    }

    public function save(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'type' => ['required', Rule::enum(TransactionType::class)],
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('categories', 'name')
                    ->where('user_id', $user->id)
                    ->where('type', $this->type)
                    ->ignore($this->editingId),
            ],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        if ($this->editingId) {
            $category = $user->categories()->findOrFail($this->editingId);
            $this->authorize('update', $category);

            $category->update([
                'name' => $validated['name'],
                'color' => $validated['color'],
                'type' => $category->transactions()->exists() ? $category->type : $validated['type'],
            ]);
        } else {
            $user->categories()->create([
                ...$validated,
                'sort_order' => (int) $user->categories()->where('type', $validated['type'])->max('sort_order') + 1,
            ]);
        }

        $this->showForm = false;
        Flux::toast(variant: 'success', text: __('Kateqoriya yadda saxlanıldı.'));
        unset($this->categories);
    }

    /**
     * Categories with transactions are archived instead of deleted.
     */
    public function remove(int $id): void
    {
        $category = Auth::user()->categories()->findOrFail($id);
        $this->authorize('delete', $category);

        if ($category->system_key !== null) {
            Flux::toast(variant: 'warning', text: __('Sistem kateqoriyası silinə bilməz.'));

            return;
        }

        $isUsed = $category->transactions()->withTrashed()->exists()
            || Auth::user()->recurringPayments()->where('category_id', $category->id)->exists();

        if ($isUsed) {
            $category->update(['archived_at' => now()]);
            Flux::toast(text: __('Kateqoriya istifadə olunduğu üçün arxivləndi.'));
        } else {
            $category->delete();
            Flux::toast(text: __('Kateqoriya silindi.'));
        }

        unset($this->categories);
    }

    public function restore(int $id): void
    {
        $category = Auth::user()->categories()->findOrFail($id);
        $this->authorize('update', $category);

        $category->update(['archived_at' => null]);
        unset($this->categories);
    }
}; ?>

<div>
    <x-page-header :eyebrow="__('Təşkil')" :heading="__('Kateqoriyalar')" :subheading="__('İstifadə olunan kateqoriya silinmir, arxivlənir')">
        <x-slot name="actions">
            <x-search-input :placeholder="__('Kateqoriya axtar…')" class="w-56" />
            <flux:switch wire:model.live="showArchived" :label="__('Arxivi göstər')" />
        </x-slot>
    </x-page-header>

    <div class="grid gap-8 lg:grid-cols-2">
        @foreach (\App\Enums\TransactionType::cases() as $type)
            <section wire:key="section-{{ $type->value }}">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-ink">{{ $type === \App\Enums\TransactionType::Expense ? __('Xərc kateqoriyaları') : __('Gəlir kateqoriyaları') }}</h2>
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="create('{{ $type->value }}')">{{ __('Əlavə et') }}</flux:button>
                </div>

                <div class="card divide-y divide-line overflow-hidden">
                    @forelse ($this->categories->where('type', $type) as $category)
                        <div wire:key="category-{{ $category->id }}" @class(['group flex items-center gap-3.5 px-4 py-3 transition-colors hover:bg-surface-2/60', 'opacity-50' => $category->isArchived()])>
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl" style="background: {{ $category->color ?? '#a1a1aa' }}1f; color: {{ $category->color ?? '#a1a1aa' }}">
                                <flux:icon :name="$category->icon ?? 'tag'" variant="mini" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-ink">{{ $category->name }}</div>
                                <div class="text-xs text-faint">
                                    {{ trans_choice(':count əməliyyat', $category->transactions_count, ['count' => $category->transactions_count]) }}
                                    @if ($category->isArchived()) · {{ __('arxivdə') }} @endif
                                    @if ($category->system_key) · {{ __('sistem') }} @endif
                                </div>
                            </div>
                            @if ($category->isArchived())
                                <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="restore({{ $category->id }})">{{ __('Bərpa et') }}</flux:button>
                            @else
                                <div class="flex opacity-60 transition group-hover:opacity-100">
                                    <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="edit({{ $category->id }})" :aria-label="__('Redaktə et')" />
                                    @if ($category->system_key === null)
                                        <flux:button size="xs" variant="subtle" :icon="$category->transactions_count > 0 ? 'archive-box' : 'trash'" wire:click="remove({{ $category->id }})"
                                            :aria-label="$category->transactions_count > 0 ? __('Arxivlə') : __('Sil')" />
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-faint">{{ __('Kateqoriya yoxdur') }}</div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    <flux:modal wire:model.self="showForm" class="sheet-on-mobile w-full max-w-sm">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Kateqoriyanı redaktə et') : __('Yeni kateqoriya') }}</flux:heading>

            <x-picker wire:model="type" :label="__('Növ')" :disabled="(bool) $editingId" :options="collect(\App\Enums\TransactionType::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])->all()" />

            <flux:input wire:model="name" :label="__('Ad')" required maxlength="80" />

            <flux:field>
                <flux:label>{{ __('Rəng') }}</flux:label>
                <input type="color" wire:model="color" class="h-10 w-20 cursor-pointer rounded border border-zinc-200 bg-transparent dark:border-zinc-700" />
                <flux:error name="color" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Yadda saxla') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
