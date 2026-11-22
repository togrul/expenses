<?php

use App\Actions\Debts\PayDebtPayment;
use App\Actions\Debts\UndoDebtPayment;
use App\Actions\Recurring\PayOccurrence;
use App\Actions\Recurring\SkipOccurrence;
use App\Actions\Recurring\UndoOccurrencePayment;
use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use App\Rules\MoneyAmount;
use App\Support\Money;
use App\Support\UpcomingItem;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Global dialog for paying, undoing and skipping debt installments and recurring occurrences.
 * Opened with the browser events "pay-item", "undo-item" and "skip-item" ({ kind, id }).
 */
new class extends Component {
    public bool $showPay = false;

    public bool $showUndo = false;

    public string $kind = '';

    public ?int $itemId = null;

    public string $title = '';

    public string $amount = '';

    public string $paidOn = '';

    public bool $createTransaction = true;

    public bool $isVariable = false;

    public int $plannedAmount = 0;

    public int $maxAmount = 0;

    #[On('pay-item')]
    public function openPay(string $kind, int $id): void
    {
        $item = $this->resolve($kind, $id);

        $this->resetValidation();
        $this->kind = $kind;
        $this->itemId = $id;
        $this->paidOn = today()->toDateString();
        $this->createTransaction = true;

        if ($item instanceof DebtPayment) {
            $this->title = $item->debt->counterparty.($item->debt->title ? ' — '.$item->debt->title : '');
            $this->isVariable = false;
            $this->maxAmount = $item->debt->remaining();
        } else {
            $this->title = $item->recurringPayment->name;
            $this->isVariable = $item->recurringPayment->is_variable;
            $this->maxAmount = 0;
        }

        $this->plannedAmount = $item->amount;
        $this->amount = $this->isVariable ? '' : Money::toInput($item->amount);
        $this->showPay = true;
    }

    #[On('undo-item')]
    public function openUndo(string $kind, int $id): void
    {
        $item = $this->resolve($kind, $id);

        $this->kind = $kind;
        $this->itemId = $id;
        $this->title = $item instanceof DebtPayment ? $item->debt->counterparty : $item->recurringPayment->name;
        $this->plannedAmount = (int) $item->paid_amount;
        $this->showUndo = true;
    }

    #[On('skip-item')]
    public function skip(int $id, bool $skip = true): void
    {
        $occurrence = $this->resolve(UpcomingItem::Occurrence, $id);

        app(SkipOccurrence::class)->handle($occurrence, $skip);

        Flux::toast(text: $skip ? __('Bu dövr keçildi.') : __('Keçid ləğv edildi.'));
        $this->dispatch('payment-updated');
    }

    public function pay(): void
    {
        $validated = $this->validate([
            'amount' => ['required', new MoneyAmount(max: $this->maxAmount ?: null)],
            'paidOn' => ['required', 'date'],
            'createTransaction' => ['boolean'],
        ]);

        $item = $this->resolve($this->kind, (int) $this->itemId);
        $amount = Money::parse($validated['amount']);
        $paidOn = CarbonImmutable::parse($validated['paidOn']);

        if ($item instanceof DebtPayment) {
            app(PayDebtPayment::class)->handle($item, $amount, $paidOn, $this->createTransaction);
        } else {
            app(PayOccurrence::class)->handle($item, $amount, $paidOn, $this->createTransaction);
        }

        $this->showPay = false;

        Flux::toast(
            variant: 'success',
            heading: __('Ödənildi'),
            text: $this->createTransaction
                ? __(':amount xərclərə yazıldı', ['amount' => Money::format($amount)])
                : Money::format($amount),
        );

        $this->dispatch('payment-updated');
    }

    public function undo(): void
    {
        $item = $this->resolve($this->kind, (int) $this->itemId);

        if ($item instanceof DebtPayment) {
            app(UndoDebtPayment::class)->handle($item);
        } else {
            app(UndoOccurrencePayment::class)->handle($item);
        }

        $this->showUndo = false;

        Flux::toast(text: __('Ödəniş geri alındı.'));
        $this->dispatch('payment-updated');
    }

    private function resolve(string $kind, int $id): DebtPayment|RecurringOccurrence
    {
        if ($kind === UpcomingItem::DebtPayment) {
            $payment = DebtPayment::query()->with('debt')->findOrFail($id);
            $this->authorize('update', $payment->debt);

            return $payment;
        }

        $occurrence = RecurringOccurrence::query()->with('recurringPayment')->findOrFail($id);
        $this->authorize('update', $occurrence->recurringPayment);

        return $occurrence;
    }
}; ?>

<div>
    <flux:modal wire:model.self="showPay" class="w-full max-w-sm">
        <form wire:submit="pay" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Ödənişi təsdiqlə') }}</flux:heading>
                <flux:text class="mt-1">{{ $title }}</flux:text>
            </div>

            <flux:input wire:model="amount" :label="__('Məbləğ')" inputmode="decimal" placeholder="0,00" autocomplete="off" autofocus
                :description="$isVariable
                    ? __('Məbləğ dəyişkəndir — faktiki məbləği yazın.')
                    : ($kind === 'debt_payment' ? __('Az yazsanız, qalan hissə üçün yeni hissə yaranacaq.') : null)">
                <x-slot name="iconTrailing">
                    <span class="text-sm text-zinc-500">{{ \App\Support\Money::symbol() }}</span>
                </x-slot>
            </flux:input>

            <flux:input wire:model="paidOn" type="date" :label="__('Ödəniş tarixi')" />

            <flux:checkbox wire:model="createTransaction" :label="__('Xərclərə də yaz')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Ödənildi') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showUndo" class="w-full max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Ödənişi geri al?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __(':title — :amount ödənişi ləğv ediləcək və bağlı xərc əməliyyatı silinəcək.', ['title' => $title, 'amount' => \App\Support\Money::format($plannedAmount)]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="undo">{{ __('Geri al') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
