<?php

use App\Actions\Debts\AddAdHocDebtPayment;
use App\Actions\Debts\DeleteDebt;
use App\Actions\Debts\GenerateDebtSchedule;
use App\Actions\Debts\RecordDebtPaymentExpense;
use App\Actions\Debts\UpdateDebtPayment;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Debt $debt;

    public string $adHocAmount = '';

    public string $adHocPaidOn = '';

    public bool $adHocCreateTransaction = true;

    public string $installmentAmount = '';

    public string $installmentDueOn = '';

    public string $installmentNote = '';

    public ?int $scheduleCount = 3;

    public ?int $editingPaymentId = null;

    public string $editAmount = '';

    public string $editPrincipal = '';

    public string $editDueOn = '';

    public string $scheduleFirstDueOn = '';

    public function mount(Debt $debt): void
    {
        $this->authorize('view', $debt);

        $this->debt = $debt;
    }

    public function render(): mixed
    {
        $this->debt->load(['payments.transaction']);

        return $this->view()->title($this->debt->counterparty);
    }

    #[On('payment-updated')]
    public function refreshDebt(): void
    {
        $this->debt->refresh();
    }

    public function openAdHoc(): void
    {
        $this->resetValidation();
        $this->adHocAmount = '';
        $this->adHocPaidOn = today()->toDateString();
        $this->adHocCreateTransaction = true;
        Flux::modal('ad-hoc-payment')->show();
    }

    public function payAdHoc(AddAdHocDebtPayment $addAdHocDebtPayment): void
    {
        $this->authorize('update', $this->debt);

        $validated = $this->validate([
            'adHocAmount' => ['required', new MoneyAmount(max: $this->debt->remaining())],
            'adHocPaidOn' => ['required', 'date'],
            'adHocCreateTransaction' => ['boolean'],
        ]);

        $amount = Money::parse($validated['adHocAmount']);
        $addAdHocDebtPayment->handle($this->debt, $amount, CarbonImmutable::parse($validated['adHocPaidOn']), $this->adHocCreateTransaction);

        Flux::modal('ad-hoc-payment')->close();
        Flux::toast(variant: 'success', heading: __('Ödənildi'), text: Money::format($amount));
        $this->debt->refresh();
    }

    public function openInstallment(): void
    {
        $this->resetValidation();
        $this->installmentAmount = Money::toInput(max(0, $this->debt->unplannedAmount())) ?: '';
        $this->installmentDueOn = '';
        $this->installmentNote = '';
        Flux::modal('add-installment')->show();
    }

    public function addInstallment(): void
    {
        $this->authorize('update', $this->debt);

        $validated = $this->validate([
            'installmentAmount' => ['required', new MoneyAmount],
            'installmentDueOn' => ['nullable', 'date'],
            'installmentNote' => ['nullable', 'string', 'max:255'],
        ]);

        $this->debt->payments()->create([
            'amount' => Money::parse($validated['installmentAmount']),
            'due_on' => $validated['installmentDueOn'] ?: null,
            'note' => $validated['installmentNote'] ?: null,
            'sort_order' => $this->debt->payments()->max('sort_order') + 1,
        ]);

        Flux::modal('add-installment')->close();
        Flux::toast(text: __('Hissə əlavə olundu.'));
        $this->debt->refresh();
    }

    public function recordExpense(RecordDebtPaymentExpense $recordDebtPaymentExpense, ?int $paymentId = null): void
    {
        $this->authorize('update', $this->debt);

        if ($paymentId !== null) {
            $created = (int) $recordDebtPaymentExpense->handle($this->debt->payments()->findOrFail($paymentId));
        } else {
            $created = $recordDebtPaymentExpense->forDebts([$this->debt]);
        }

        Flux::toast(variant: 'success', text: trans_choice(':count ödəniş xərclərə yazıldı', $created, ['count' => $created]));
        $this->debt->refresh();
    }

    public function openEditInstallment(int $paymentId): void
    {
        $payment = $this->debt->payments()->whereNull('paid_at')->findOrFail($paymentId);

        $this->resetValidation();
        $this->editingPaymentId = $payment->id;
        $this->editAmount = Money::toInput($payment->amount);
        $this->editPrincipal = $payment->principal_amount !== null ? Money::toInput($payment->principal_amount) : '';
        $this->editDueOn = (string) $payment->due_on?->toDateString();
        Flux::modal('edit-installment')->show();
    }

    public function saveInstallment(UpdateDebtPayment $updateDebtPayment): void
    {
        $this->authorize('update', $this->debt);

        $validated = $this->validate([
            'editAmount' => ['required', new MoneyAmount],
            'editPrincipal' => [$this->debt->isLoan() ? 'required' : 'nullable', new MoneyAmount(min: 0)],
            'editDueOn' => ['nullable', 'date'],
        ]);

        $payment = $this->debt->payments()->findOrFail($this->editingPaymentId);

        $updateDebtPayment->handle($payment, [
            'amount' => Money::parse($validated['editAmount']),
            'principal_amount' => $validated['editPrincipal'] !== null && $validated['editPrincipal'] !== '' ? Money::parse($validated['editPrincipal']) : null,
            'due_on' => $validated['editDueOn'] ?: null,
        ]);

        Flux::modal('edit-installment')->close();
        Flux::toast(variant: 'success', text: __('Hissə yeniləndi.'));
        $this->debt->refresh();
    }

    public function deleteInstallment(int $paymentId): void
    {
        $this->authorize('update', $this->debt);

        $payment = $this->debt->payments()->whereNull('paid_at')->findOrFail($paymentId);
        $payment->delete();

        Flux::toast(text: __('Hissə silindi.'));
        $this->debt->refresh();
    }

    public function openSchedule(): void
    {
        $this->resetValidation();
        $this->scheduleCount = 3;
        $this->scheduleFirstDueOn = today()->addMonthNoOverflow()->toDateString();
        Flux::modal('generate-schedule')->show();
    }

    public function generateSchedule(GenerateDebtSchedule $generateDebtSchedule): void
    {
        $this->authorize('update', $this->debt);

        $validated = $this->validate([
            'scheduleCount' => ['required', 'integer', 'min:1', 'max:360'],
            'scheduleFirstDueOn' => ['nullable', 'date'],
        ]);

        $generateDebtSchedule->handle(
            $this->debt,
            $validated['scheduleCount'],
            $validated['scheduleFirstDueOn'] ? CarbonImmutable::parse($validated['scheduleFirstDueOn']) : null,
        );

        Flux::modal('generate-schedule')->close();
        Flux::toast(variant: 'success', text: __('Hissələr yaradıldı.'));
        $this->debt->refresh();
    }

    public function delete(DeleteDebt $deleteDebt): void
    {
        $this->authorize('delete', $this->debt);

        $deleteDebt->handle($this->debt);

        Flux::toast(text: $this->debt->isLoan() ? __('Kredit silindi.') : __('Borc silindi.'));
        $this->redirect(Debt::indexUrl($this->debt->kind), navigate: true);
    }
}; ?>

@php($status = $debt->status())
@php($unplanned = $debt->unplannedAmount())
@php($paidPayments = $debt->payments->whereNotNull('paid_at')->sortByDesc('paid_at'))
@php($openPayments = $debt->sortedPayments()->filter(fn ($payment) => ! $payment->isPaid()))
@php($loan = $debt->isLoan() ? $debt->loanSummary() : null)

<div class="max-w-5xl">
    <a href="{{ \App\Models\Debt::indexUrl($debt->kind) }}" wire:navigate.hover class="mb-6 inline-flex items-center gap-1.5 text-sm text-muted transition hover:text-ink">
        <flux:icon name="arrow-left" variant="micro" /> {{ $debt->isLoan() ? __('Kreditlər') : __('Borclar') }}
    </a>

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <x-initials :name="$debt->counterparty" size="lg" />
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $debt->counterparty }}</h1>
                <div class="mt-1 flex items-center gap-2 text-sm text-muted">
                    {{ $debt->title ?: __('Borc') }}
                    <x-status-pill :status="$status" />
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="pencil-square" :href="$debt->url('edit')" wire:navigate.hover>{{ __('Redaktə') }}</flux:button>
            <flux:modal.trigger name="delete-debt">
                <flux:button variant="ghost" icon="trash" :aria-label="__('Sil')" />
            </flux:modal.trigger>
        </div>
    </div>

    <div class="card gold-glow mb-8 p-6 sm:p-8">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div>
                @if ($debt->isLoan())
                    <div class="eyebrow">{{ __('Qalıq — bu gün bağlamaq üçün') }}</div>
                    <x-money :amount="$loan['payoff']" class="mt-2 block text-4xl font-semibold text-ink sm:text-5xl" />
                    <div class="mt-2 text-xs text-muted">
                        {{ __('əsas borc') }} <x-money :amount="$loan['principal_left']" class="font-medium text-ink" />
                        + {{ __('yığılmış faiz') }} <x-money :amount="$loan['accrued']" class="font-medium text-ink" />
                        <span class="text-faint">· {{ __('təxmini, bankın “qalıq”ı ilə müqayisə edin') }}</span>
                    </div>
                @else
                    <div class="eyebrow">{{ __('Qalıq borc') }}</div>
                    <x-money :amount="$debt->remaining()" class="mt-2 block text-4xl font-semibold text-ink sm:text-5xl" />
                @endif
            </div>
            @if ($debt->remaining() > 0 && ! $debt->isLoan())
                <flux:button variant="primary" icon="banknotes" wire:click="openAdHoc">{{ __('Ödəniş et') }}</flux:button>
            @elseif ($debt->isLoan() && $loan['next_payment'])
                <div class="text-end">
                    <div class="eyebrow">{{ __('Növbəti ödəniş') }}</div>
                    <x-money :amount="$loan['next_payment']" class="mt-1.5 block text-2xl font-semibold text-gold" />
                </div>
            @endif
        </div>

        <div class="mt-6 flex items-center gap-3">
            <x-meter :value="$debt->progressPercent()" :tone="$status === \App\Enums\DebtStatus::Overdue ? 'negative' : ($status === \App\Enums\DebtStatus::Paid ? 'positive' : 'gold')" />
            <span class="text-sm font-semibold text-muted num">{{ $debt->progressPercent() }}%</span>
        </div>

        @if ($debt->isLoan())
            <dl class="mt-7 grid grid-cols-2 gap-6 border-t border-line pt-6 sm:grid-cols-4">
                <div><dt class="eyebrow">{{ __('Əsas borc qalığı') }}</dt><dd class="mt-1.5 text-sm font-semibold text-ink"><x-money :amount="$loan['principal_left']" /></dd><dd class="text-[11px] text-faint">{{ __('əsas') }} <x-money :amount="$loan['principal']" /></dd></div>
                <div><dt class="eyebrow">{{ __('Ödənilmiş faiz') }}</dt><dd class="mt-1.5 text-sm font-semibold text-negative"><x-money :amount="$loan['interest_paid']" /></dd><dd class="text-[11px] text-faint">{{ __('qalan faiz') }} <x-money :amount="$loan['interest_left']" /></dd></div>
                <div><dt class="eyebrow">{{ __('Şərtlər') }}</dt><dd class="mt-1.5 text-sm font-semibold text-ink num">{{ rtrim(rtrim((string) $debt->interest_rate, '0'), '.') }}% · {{ $debt->term_months }} {{ __('ay') }}</dd><dd class="text-[11px] text-faint">{{ $debt->schedule_type?->shortLabel() }}</dd></div>
                <div><dt class="eyebrow">{{ __('Ödənilib') }}</dt><dd class="mt-1.5 text-sm font-semibold text-positive num">{{ $loan['paid_count'] }} / {{ $loan['count'] }} {{ __('ay') }}</dd><dd class="text-[11px] text-faint"><x-money :amount="$debt->paidTotal()" /> · {{ __('qalan ödənişlər') }} <x-money :amount="$loan['remaining_payments']" /></dd></div>
            </dl>
        @else
        <dl class="mt-7 grid grid-cols-2 gap-6 border-t border-line pt-6 sm:grid-cols-4">
            <div><dt class="eyebrow">{{ __('Ümumi') }}</dt><dd class="mt-1.5 text-sm font-semibold text-ink"><x-money :amount="$debt->total_amount" /></dd></div>
            <div><dt class="eyebrow">{{ __('Ödənilib') }}</dt><dd class="mt-1.5 text-sm font-semibold text-positive"><x-money :amount="$debt->paidTotal()" /></dd></div>
            <div><dt class="eyebrow">{{ __('Başlama') }}</dt><dd class="mt-1.5 text-sm font-semibold text-ink num">{{ \App\Support\Preferences::formatDate($debt->started_on) }}</dd></div>
            <div><dt class="eyebrow">{{ __('Son tarix') }}</dt><dd class="mt-1.5 text-sm font-semibold text-ink num">{{ \App\Support\Preferences::formatDate($debt->due_on) }}</dd></div>
        </dl>
        @endif

        @if ($debt->note)
            <p class="mt-6 whitespace-pre-line rounded-xl bg-surface-2 px-4 py-3 text-sm text-muted">{{ $debt->note }}</p>
        @endif
    </div>

    @if (! $debt->isLoan() && $unplanned !== 0 && $debt->payments->isNotEmpty() && $debt->remaining() > 0)
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-warning/25 bg-warning/5 px-4 py-3 text-sm text-warning">
            <flux:icon name="exclamation-triangle" variant="mini" class="mt-0.5 shrink-0" />
            <span>{{ $unplanned > 0
                ? __('Planlaşdırılmış hissələr ümumi borcdan :amount azdır.', ['amount' => \App\Support\Money::format($unplanned)])
                : __('Planlaşdırılmış hissələr ümumi borcdan :amount çoxdur.', ['amount' => \App\Support\Money::format(-$unplanned)]) }}</span>
        </div>
    @endif

    <div class="grid gap-8 lg:grid-cols-[1.5fr_1fr]">
        <section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('Ödəniş hissələri') }}</h2>
                    <p class="text-xs text-faint">{{ trans_choice(':count hissə gözləyir', $openPayments->count(), ['count' => $openPayments->count()]) }}</p>
                </div>
                @if ($debt->remaining() > 0 && ! $debt->isLoan())
                    <div class="flex gap-1">
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="openInstallment">{{ __('Hissə') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="sparkles" wire:click="openSchedule">{{ __('Avtomatik') }}</flux:button>
                    </div>
                @endif
            </div>

            @php($paymentEntries = $debt->sortedPayments()->map(fn ($p) => ['debt_payment-'.$p->id, $p->isPaid() && $p->paid_amount !== null ? $p->paid_amount : $p->amount, $debt->counterparty])->values()->all())
            @if ($paymentEntries)
                <x-select-all :entries="$paymentEntries" class="mb-2 ms-3.5" />
            @endif

            <div class="space-y-2">
                @forelse ($debt->sortedPayments() as $payment)
                    <x-payment-row
                        wire:key="payment-{{ $payment->id }}"
                        kind="debt_payment"
                        :id="$payment->id"
                        :title="$payment->installment_number
                            ? __(':n / :total ay', ['n' => $payment->installment_number, 'total' => $debt->term_months]).($payment->split_from_id ? ' · '.__('qalıq') : '')
                            : ($payment->note ?? ($payment->split_from_id ? __('Qismən ödənişin qalığı') : __('Hissə :n', ['n' => $loop->iteration])))"
                        :subtitle="$payment->interest_amount !== null
                            ? __('Əsas :p · Faiz :i', ['p' => \App\Support\Money::format((int) $payment->principal_amount), 'i' => \App\Support\Money::format($payment->interest_amount)])
                            : null"
                        :due-on="$payment->due_on"
                        :amount="$payment->amount"
                        :paid="$payment->isPaid()"
                        :paid-at="$payment->paid_at"
                        :paid-amount="$payment->paid_amount"
                    >
                        <x-slot name="actions">
                            @unless ($payment->isPaid())
                                <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="openEditInstallment({{ $payment->id }})" :aria-label="__('Hissəni redaktə et')" class="opacity-0 transition group-hover:opacity-100 focus:opacity-100" />
                            @endunless
                            @unless ($payment->isPaid() || $debt->isLoan())
                                <flux:button size="xs" variant="subtle" icon="trash" wire:click="deleteInstallment({{ $payment->id }})" :aria-label="__('Hissəni sil')" class="opacity-0 transition group-hover:opacity-100 focus:opacity-100" />
                            @endunless
                        </x-slot>
                    </x-payment-row>
                @empty
                    <x-empty-state icon="list-bullet" :heading="__('Hissə yoxdur')" :text="__('Hissələri avtomatik yaradın və ya birbaşa “Ödəniş et” ilə ödəyin.')">
                        @if ($debt->remaining() > 0)
                            <flux:button size="sm" icon="sparkles" wire:click="openSchedule">{{ __('Hissələri yarat') }}</flux:button>
                        @endif
                    </x-empty-state>
                @endforelse
            </div>
        </section>

        <aside>
            @php($unrecorded = $paidPayments->whereNull('transaction_id'))
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-ink">{{ __('Ödəniş tarixçəsi') }}</h2>
                @if ($unrecorded->isNotEmpty())
                    <flux:button size="xs" variant="primary" icon="plus" wire:click="recordExpense">
                        {{ __('Hamısını xərclərə yaz (:n)', ['n' => $unrecorded->count()]) }}
                    </flux:button>
                @endif
            </div>
            @if ($unrecorded->isNotEmpty())
                <p class="mb-3 text-xs text-faint">
                    {{ __(':n ödəniş xərclərə yazılmayıb', ['n' => $unrecorded->count()]) }} — <x-money :amount="(int) $unrecorded->sum('paid_amount')" class="font-medium text-muted" />.
                    {{ __('Yazılanda öz ödəniş tarixi ilə əməliyyatlara düşür.') }}
                </p>
            @endif
            <div class="card p-2">
                @forelse ($paidPayments as $payment)
                    <div wire:key="history-{{ $payment->id }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5">
                        <span class="flex size-8 items-center justify-center rounded-full bg-positive/10 text-positive">
                            <flux:icon name="check" variant="micro" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-ink num">{{ \App\Support\Preferences::formatDate($payment->paid_at) }}</div>
                            @if ($payment->transaction)
                                <div class="text-xs text-faint">{{ __('Xərclərə yazılıb') }}</div>
                            @else
                                <button type="button" wire:click="recordExpense({{ $payment->id }})" class="cursor-pointer text-xs font-medium text-gold hover:underline">{{ __('Xərclərə yaz') }}</button>
                            @endif
                        </div>
                        <x-money :amount="(int) $payment->paid_amount" class="text-sm font-semibold text-ink" />
                    </div>
                @empty
                    <div class="px-3 py-8 text-center text-sm text-faint">{{ __('Hələ ödəniş edilməyib') }}</div>
                @endforelse
            </div>
        </aside>
    </div>

    @if ($debt->isLoan())
        @php($balance = (int) $debt->principal)
        <section class="mt-10" x-data="{ open: false }">
            <button type="button" x-on:click="open = ! open" class="mb-4 flex cursor-pointer items-center gap-2 text-base font-semibold text-ink">
                <flux:icon name="chevron-right" variant="mini" class="text-faint transition" x-bind:class="open && 'rotate-90'" />
                {{ __('Ödəniş qrafiki') }}
                <span class="text-xs font-normal text-faint">{{ __('əsas borc və faiz bölgüsü') }}</span>
            </button>
            <div x-show="open" x-collapse x-cloak class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-start">
                            <th class="eyebrow px-4 py-3 text-start">#</th>
                            <th class="eyebrow px-4 py-3 text-start">{{ __('Tarix') }}</th>
                            <th class="eyebrow px-4 py-3 text-end">{{ __('Ödəniş') }}</th>
                            <th class="eyebrow px-4 py-3 text-end">{{ __('Əsas') }}</th>
                            <th class="eyebrow px-4 py-3 text-end">{{ __('Faiz') }}</th>
                            <th class="eyebrow px-4 py-3 text-end">{{ __('Qalıq əsas') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($debt->sortedPayments() as $payment)
                            @php($balance -= (int) $payment->principal_amount)
                            <tr wire:key="schedule-{{ $payment->id }}" @class(['border-b border-line last:border-0', 'text-faint' => $payment->isPaid()])>
                                <td class="px-4 py-2.5 num">
                                    @if ($payment->isPaid())<flux:icon name="check" variant="micro" class="inline text-positive" />@endif
                                    {{ $payment->installment_number }}
                                </td>
                                <td class="px-4 py-2.5 num">{{ \App\Support\Preferences::formatDate($payment->due_on) }}</td>
                                <td class="px-4 py-2.5 text-end"><x-money :amount="$payment->amount" class="font-medium" /></td>
                                <td class="px-4 py-2.5 text-end"><x-money :amount="(int) $payment->principal_amount" /></td>
                                <td class="px-4 py-2.5 text-end text-negative"><x-money :amount="(int) $payment->interest_amount" /></td>
                                <td class="px-4 py-2.5 text-end text-muted"><x-money :amount="max(0, $balance)" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Edit installment --}}
    <flux:modal name="edit-installment" class="sheet-on-mobile w-full max-w-sm">
        <form wire:submit="saveInstallment" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Hissəni redaktə et') }}</flux:heading>
                @if ($debt->isLoan())
                    <flux:text class="mt-1">{{ __('Bankın qrafikindəki rəqəmləri yazın. Faiz = ödəniş − əsas.') }}</flux:text>
                @endif
            </div>
            <flux:input wire:model.live.debounce.300ms="editAmount" :label="__('Ödəniş')" inputmode="decimal" />
            @if ($debt->isLoan())
                <flux:input wire:model.live.debounce.300ms="editPrincipal" :label="__('Əsas borc hissəsi')" inputmode="decimal" />
                @php($previewInterest = (\App\Support\Money::tryParse($editAmount) ?? 0) - (\App\Support\Money::tryParse($editPrincipal) ?? 0))
                <flux:text class="text-sm">{{ __('Faiz') }}: <x-money :amount="max(0, $previewInterest)" class="font-semibold text-negative" /></flux:text>
            @endif
            <flux:input wire:model="editDueOn" type="date" :label="__('Ödəmə tarixi')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Yadda saxla') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Ad hoc payment --}}
    <flux:modal name="ad-hoc-payment" class="sheet-on-mobile w-full max-w-sm">
        <form wire:submit="payAdHoc" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Ödəniş et') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Qalıq') }}: <x-money :amount="$debt->remaining()" /></flux:text>
            </div>
            <flux:input wire:model="adHocAmount" :label="__('Məbləğ')" inputmode="decimal" placeholder="0,00" autofocus />
            <flux:input wire:model="adHocPaidOn" type="date" :label="__('Ödəniş tarixi')" />
            <flux:checkbox wire:model="adHocCreateTransaction" :label="__('Xərclərə də yaz')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Ödənildi') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add a single installment --}}
    <flux:modal name="add-installment" class="sheet-on-mobile w-full max-w-sm">
        <form wire:submit="addInstallment" class="space-y-5">
            <flux:heading size="lg">{{ __('Hissə əlavə et') }}</flux:heading>
            <flux:input wire:model="installmentAmount" :label="__('Məbləğ')" inputmode="decimal" placeholder="0,00" autofocus />
            <flux:input wire:model="installmentDueOn" type="date" :label="__('Ödəmə tarixi')" :description="__('İstəyə bağlı')" />
            <flux:input wire:model="installmentNote" :label="__('Qeyd')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Əlavə et') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Schedule generator --}}
    <flux:modal name="generate-schedule" class="sheet-on-mobile w-full max-w-sm">
        <form wire:submit="generateSchedule" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Hissələri avtomatik yarat') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Ödənilməmiş hissələr silinib qalıq (:amount) bərabər bölünəcək.', ['amount' => \App\Support\Money::format($debt->remaining())]) }}</flux:text>
            </div>
            <flux:input wire:model="scheduleCount" type="number" min="1" max="360" :label="__('Neçə ay')" />
            <flux:input wire:model="scheduleFirstDueOn" type="date" :label="__('İlk ödəniş tarixi')" :description="__('Boş qalsa hissələr tarixsiz olacaq.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Yarat') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-debt" class="sheet-on-mobile w-full max-w-sm">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Borc silinsin?') }}</flux:heading>
            <flux:text>{{ __('Borc, onun hissələri və ondan yaranmış xərc əməliyyatları silinəcək.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Sil') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
