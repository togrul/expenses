<?php

use App\Actions\Transactions\CreateTransaction;
use App\Actions\Transactions\UpdateTransaction;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $show = false;

    public ?int $transactionId = null;

    public bool $isLinked = false;

    public string $type = 'expense';

    public string $amount = '';

    public ?int $categoryId = null;

    public string $occurredOn = '';

    public string $note = '';

    #[On('open-transaction-modal')]
    public function create(?string $type = null): void
    {
        $this->resetValidation();
        $this->reset('transactionId', 'isLinked', 'amount', 'categoryId', 'note');
        $this->type = $type === 'income' ? 'income' : 'expense';
        $this->occurredOn = today()->toDateString();
        $this->show = true;
    }

    #[On('edit-transaction')]
    public function edit(int $id): void
    {
        $transaction = Auth::user()->transactions()->findOrFail($id);
        $this->authorize('update', $transaction);

        $this->resetValidation();
        $this->transactionId = $transaction->id;
        $this->isLinked = $transaction->isLinked();
        $this->type = $transaction->type->value;
        $this->amount = Money::toInput($transaction->amount);
        $this->categoryId = $transaction->category_id;
        $this->occurredOn = $transaction->occurred_on->toDateString();
        $this->note = (string) $transaction->note;
        $this->show = true;
    }

    public function updatedType(): void
    {
        $this->categoryId = null;
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Auth::user()->categories()
            ->where('type', $this->type)
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $this->categoryId))
            ->ordered()
            ->get();
    }

    /**
     * The categories used most in the last 90 days, shown as one-tap chips.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function quickCategories(): Collection
    {
        $usage = Auth::user()->transactions()
            ->where('type', $this->type)
            ->whereNotNull('category_id')
            ->where('occurred_on', '>=', today()->subDays(90))
            ->selectRaw('category_id, count(*) as uses')
            ->groupBy('category_id')
            ->orderByDesc('uses')
            ->limit(6)
            ->pluck('uses', 'category_id');

        return $this->categories
            ->filter(fn (Category $category): bool => $usage->has($category->id) && $category->archived_at === null)
            ->sortByDesc(fn (Category $category): int => $usage[$category->id])
            ->values();
    }

    public function save(CreateTransaction $createTransaction, UpdateTransaction $updateTransaction): void
    {
        $validated = $this->validate([
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount' => ['required', new MoneyAmount],
            'categoryId' => ['nullable', 'integer'],
            'occurredOn' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [
            'type' => $validated['type'],
            'amount' => Money::parse($validated['amount']),
            'category_id' => $validated['categoryId'],
            'occurred_on' => $validated['occurredOn'],
            'note' => $validated['note'] ?: null,
        ];

        if ($this->transactionId) {
            $transaction = Auth::user()->transactions()->findOrFail($this->transactionId);
            $this->authorize('update', $transaction);
            $updateTransaction->handle($transaction, $data);
            Flux::toast(variant: 'success', text: __('Əməliyyat yeniləndi.'));
        } else {
            $createTransaction->handle(Auth::user(), $data);
            Flux::toast(variant: 'success', text: __('Əməliyyat əlavə olundu · :amount', ['amount' => Money::format($data['amount'])]));
        }

        $this->show = false;
        $this->dispatch('transaction-saved');
    }
}; ?>

<div>
    <flux:modal wire:model.self="show" class="sheet-on-mobile w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $transactionId ? __('Əməliyyatı redaktə et') : __('Yeni əməliyyat') }}</flux:heading>

            @if ($isLinked)
                <flux:callout icon="link" variant="secondary" :text="__('Bu əməliyyat borc/daimi ödənişdən yaranıb. Məbləğ və tarixi mənbədə dəyişin.')" />
            @endif

            <div class="grid grid-cols-2 gap-2" role="radiogroup">
                @foreach (\App\Enums\TransactionType::cases() as $case)
                    <flux:button
                        type="button"
                        wire:click="$set('type', '{{ $case->value }}')"
                        :variant="$type === $case->value ? 'primary' : 'outline'"
                        :disabled="$isLinked"
                        :icon="$case === \App\Enums\TransactionType::Income ? 'arrow-down-left' : 'arrow-up-right'"
                        role="radio"
                        aria-checked="{{ $type === $case->value ? 'true' : 'false' }}"
                    >
                        {{ $case->label() }}
                    </flux:button>
                @endforeach
            </div>

            <flux:input
                wire:model="amount"
                :label="__('Məbləğ')"
                inputmode="decimal"
                placeholder="0,00"
                autocomplete="off"
                :disabled="$isLinked"
                autofocus
            >
                <x-slot name="iconTrailing">
                    <span class="text-sm text-zinc-500">{{ \App\Support\Money::symbol() }}</span>
                </x-slot>
            </flux:input>

            <flux:select wire:model.live="categoryId" :label="__('Kateqoriya')" :placeholder="__('Kateqoriya seçin')">
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id" wire:key="category-option-{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->quickCategories->isNotEmpty())
                <div class="-mt-2 flex flex-wrap gap-1.5" aria-label="{{ __('Tez-tez istifadə olunan kateqoriyalar') }}">
                    @foreach ($this->quickCategories as $category)
                        <button
                            type="button"
                            wire:key="quick-category-{{ $category->id }}"
                            wire:click="$set('categoryId', {{ $category->id }})"
                            @class([
                                'cursor-pointer rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                'border-gold/40 bg-gold-soft text-gold' => $categoryId === $category->id,
                                'border-line-strong text-muted hover:text-ink' => $categoryId !== $category->id,
                            ])
                            aria-pressed="{{ $categoryId === $category->id ? 'true' : 'false' }}"
                        >
                            {{ $category->name }}
                        </button>
                    @endforeach
                </div>
            @endif

            <flux:input wire:model="occurredOn" type="date" :label="__('Tarix')" :disabled="$isLinked" />

            <flux:input wire:model="note" :label="__('Qeyd')" :placeholder="__('İstəyə bağlı')" maxlength="255" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Yadda saxla') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
