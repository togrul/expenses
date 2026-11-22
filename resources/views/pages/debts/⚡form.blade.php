<?php

use App\Actions\Debts\CreateDebt;
use App\Actions\Debts\CreateLoan;
use App\Actions\Debts\GenerateDebtSchedule;
use App\Actions\Debts\UpdateDebt;
use App\Actions\Debts\UpdateLoan;
use App\Enums\DebtKind;
use App\Enums\InterestMethod;
use App\Enums\LoanScheduleType;
use App\Models\Debt;
use App\Rules\MoneyAmount;
use App\Support\LoanCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Borc')] class extends Component {
    public ?Debt $debt = null;

    public string $kind = 'personal';

    public string $counterparty = '';

    public string $title = '';

    public string $totalAmount = '';

    public string $startedOn = '';

    public string $dueOn = '';

    public string $note = '';

    public bool $generateSchedule = false;

    public ?int $installments = 3;

    public string $firstDueOn = '';

    public string $interestRate = '';

    public ?int $termMonths = 12;

    public string $scheduleType = 'annuity';

    public bool $termsLocked = false;

    public string $monthlyPayment = '';

    public string $interestMethod = 'monthly';

    public ?int $paidMonths = 0;

    public bool $recordPaidAsExpense = false;

    public bool $shiftWeekends = false;

    public function mount(?Debt $debt = null): void
    {
        if ($debt?->exists) {
            $this->authorize('update', $debt);

            $this->debt = $debt;
            $this->kind = $debt->kind->value;
            $this->counterparty = $debt->counterparty;
            $this->title = (string) $debt->title;
            $this->startedOn = (string) $debt->started_on?->toDateString();
            $this->dueOn = (string) $debt->due_on?->toDateString();
            $this->note = (string) $debt->note;

            if ($debt->isLoan()) {
                $this->totalAmount = Money::toInput((int) $debt->principal);
                $this->interestRate = rtrim(rtrim((string) $debt->interest_rate, '0'), '.');
                $this->termMonths = (int) $debt->term_months;
                $this->scheduleType = $debt->schedule_type->value ?? 'annuity';
                $this->firstDueOn = (string) $debt->payments()->min('due_on');
                $this->monthlyPayment = $debt->monthly_payment ? Money::toInput($debt->monthly_payment) : '';
                $this->interestMethod = $debt->interest_method->value ?? 'monthly';
                $this->shiftWeekends = $debt->shift_weekends;
                $this->termsLocked = $debt->payments()->whereNotNull('paid_at')->exists();
            } else {
                $this->totalAmount = Money::toInput($debt->total_amount);
            }

            return;
        }

        $this->firstDueOn = today()->addMonthNoOverflow()->toDateString();

        if (request()->routeIs('loans.*')) {
            $this->kind = DebtKind::Loan->value;
        } else {
            $this->startedOn = today()->toDateString();
        }
    }

    public function updatedKind(): void
    {
        // A loan's start date is its disbursement date, usually in the past — don't prefill today.
        if ($this->isLoan() && ! $this->debt && $this->startedOn === today()->toDateString()) {
            $this->startedOn = '';
        }
    }

    public function isLoan(): bool
    {
        return $this->kind === DebtKind::Loan->value;
    }

    public function save(CreateDebt $createDebt, UpdateDebt $updateDebt, GenerateDebtSchedule $generateDebtSchedule, CreateLoan $createLoan, UpdateLoan $updateLoan): void
    {
        $this->isLoan()
            ? $this->saveLoan($createLoan, $updateLoan)
            : $this->savePersonal($createDebt, $updateDebt, $generateDebtSchedule);
    }

    private function savePersonal(CreateDebt $createDebt, UpdateDebt $updateDebt, GenerateDebtSchedule $generateDebtSchedule): void
    {
        $validated = $this->validate([
            'counterparty' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:150'],
            'totalAmount' => ['required', new MoneyAmount],
            'startedOn' => ['nullable', 'date'],
            'dueOn' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'generateSchedule' => ['boolean'],
            'installments' => ['exclude_unless:generateSchedule,true', 'required', 'integer', 'min:1', 'max:360'],
            'firstDueOn' => ['exclude_unless:generateSchedule,true', 'nullable', 'date'],
        ]);

        $data = [
            'counterparty' => $validated['counterparty'],
            'title' => $validated['title'] ?: null,
            'total_amount' => Money::parse($validated['totalAmount']),
            'started_on' => $validated['startedOn'] ?: null,
            'due_on' => $validated['dueOn'] ?: null,
            'note' => $validated['note'] ?: null,
        ];

        if ($this->debt) {
            $debt = $updateDebt->handle($this->debt, $data);
            Flux::toast(variant: 'success', text: __('Borc yeniləndi.'));
        } else {
            $debt = DB::transaction(function () use ($createDebt, $generateDebtSchedule, $data): Debt {
                $debt = $createDebt->handle(Auth::user(), $data);

                if ($this->generateSchedule) {
                    $generateDebtSchedule->handle(
                        $debt,
                        (int) $this->installments,
                        $this->firstDueOn ? CarbonImmutable::parse($this->firstDueOn) : null,
                    );
                }

                return $debt;
            });

            Flux::toast(variant: 'success', text: __('Borc əlavə olundu.'));
        }

        $this->redirect($debt->url(), navigate: true);
    }

    private function saveLoan(CreateLoan $createLoan, UpdateLoan $updateLoan): void
    {
        $this->interestRate = str_replace(',', '.', trim($this->interestRate));

        $validated = $this->validate([
            'counterparty' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:150'],
            'totalAmount' => ['required', new MoneyAmount],
            'interestRate' => ['required', 'numeric', 'min:0', 'max:200'],
            'termMonths' => ['required', 'integer', 'min:1', 'max:480'],
            'scheduleType' => ['required', Rule::enum(LoanScheduleType::class)],
            'firstDueOn' => ['required', 'date'],
            'startedOn' => ['nullable', 'required_if:interestMethod,daily,daily360', 'date', 'before:firstDueOn'],
            'shiftWeekends' => ['boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'monthlyPayment' => ['nullable', new MoneyAmount],
            'interestMethod' => ['required', Rule::enum(InterestMethod::class)],
            'paidMonths' => ['nullable', 'integer', 'min:0', 'lt:termMonths'],
            'recordPaidAsExpense' => ['boolean'],
        ]);

        $data = [
            'counterparty' => $validated['counterparty'],
            'title' => $validated['title'] ?: null,
            'principal' => Money::parse($validated['totalAmount']),
            'interest_rate' => (float) $validated['interestRate'],
            'term_months' => (int) $validated['termMonths'],
            'schedule_type' => $validated['scheduleType'],
            'first_due_on' => CarbonImmutable::parse($validated['firstDueOn'])->toDateString(),
            'started_on' => $validated['startedOn'] ?: null,
            'note' => $validated['note'] ?: null,
            'monthly_payment' => $validated['scheduleType'] === 'annuity' && $validated['monthlyPayment'] ? Money::parse($validated['monthlyPayment']) : null,
            'interest_method' => $validated['interestMethod'],
            'shift_weekends' => (bool) $validated['shiftWeekends'],
            'paid_months' => $this->debt ? 0 : (int) $validated['paidMonths'],
            'record_paid_as_expense' => (bool) $validated['recordPaidAsExpense'],
        ];

        if ($this->debt) {
            $debt = $updateLoan->handle($this->debt, $data);
            Flux::toast(variant: 'success', text: __('Kredit yeniləndi.'));
        } else {
            $debt = $createLoan->handle(Auth::user(), $data);
            Flux::toast(variant: 'success', text: __('Kredit əlavə olundu.'));
        }

        $this->redirect($debt->url(), navigate: true);
    }

    /**
     * Live loan preview for the form.
     *
     * @return array{monthly: int, first: int, last: int, interest: int, total: int, standard: int|null, paid_count: int, paid_total: int, principal_left: int}|null
     */
    #[Computed]
    public function loanPreview(): ?array
    {
        $principal = Money::tryParse($this->totalAmount);
        $rate = str_replace(',', '.', trim($this->interestRate));

        if (! $principal || ! is_numeric($rate) || (float) $rate < 0 || $this->termMonths < 1 || $this->termMonths > 480) {
            return null;
        }

        $type = LoanScheduleType::tryFrom($this->scheduleType) ?? LoanScheduleType::Annuity;
        $method = InterestMethod::tryFrom($this->interestMethod) ?? InterestMethod::Monthly;
        $fixedPayment = $type === LoanScheduleType::Annuity ? Money::tryParse($this->monthlyPayment) : null;

        try {
            $firstDueOn = CarbonImmutable::parse($this->firstDueOn ?: today()->addMonthNoOverflow());
            $startedOn = $this->startedOn ? CarbonImmutable::parse($this->startedOn) : null;
        } catch (\Throwable) {
            return null;
        }

        if ($method->isDaily() && ($startedOn === null || $startedOn->gte($firstDueOn))) {
            $method = InterestMethod::Monthly;
        }

        $rows = LoanCalculator::schedule($principal, (float) $rate, $this->termMonths, $type, $fixedPayment ?: null, $method, LoanCalculator::dueDates($firstDueOn, $this->termMonths, $this->shiftWeekends), $startedOn);
        $paid = array_slice($rows, 0, max(0, min((int) $this->paidMonths, $this->termMonths - 1)));

        return [
            ...LoanCalculator::summarize($rows),
            'standard' => $type === LoanScheduleType::Annuity ? LoanCalculator::annuityPayment($principal, (float) $rate, $this->termMonths) : null,
            'paid_count' => count($paid),
            'paid_total' => array_sum(array_column($paid, 'payment')),
            'principal_left' => $paid === [] ? $principal : end($paid)['balance'],
        ];
    }

    public function previewSchedule(): string
    {
        $amount = Money::tryParse($this->totalAmount);

        if (! $amount || $this->installments < 1 || $this->installments > 360) {
            return '';
        }

        $parts = Money::split($amount, $this->installments);

        return __(':count × :amount', ['count' => $this->installments, 'amount' => Money::format($parts[0])])
            .($parts[0] !== end($parts) ? ' ('.__('sonuncu :amount', ['amount' => Money::format(end($parts))]).')' : '');
    }
}; ?>

<div class="max-w-2xl">
    <a href="{{ $debt ? $debt->url() : \App\Models\Debt::indexUrl($kind) }}" wire:navigate class="mb-6 inline-flex items-center gap-1.5 text-sm text-muted transition hover:text-ink">
        <flux:icon name="arrow-left" variant="micro" /> {{ __('Geri') }}
    </a>

    <x-page-header :eyebrow="$this->isLoan() ? __('Kreditlər') : __('Borclar')" :heading="$debt ? ($this->isLoan() ? __('Krediti redaktə et') : __('Borcu redaktə et')) : ($this->isLoan() ? __('Yeni kredit') : __('Yeni borc'))" />


    <form wire:submit="save" class="space-y-6">
        <div class="card space-y-6 p-6 sm:p-7">
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="counterparty" :label="$this->isLoan() ? __('Bank') : __('Kimə')" :placeholder="$this->isLoan() ? __('məs. Kapital Bank') : __('məs. Elvin')" required autofocus />
                <flux:input wire:model="title" :label="__('Başlıq')" :placeholder="$this->isLoan() ? __('məs. Nağd kredit') : __('məs. Telefon krediti')" />
            </div>

            <flux:input wire:model.live.debounce.400ms="totalAmount" :label="$this->isLoan() ? __('Kredit məbləği (əsas borc)') : __('Ümumi məbləğ')" inputmode="decimal" placeholder="0,00" required :disabled="$termsLocked" class="[&_input]:text-lg [&_input]:font-semibold">
                <x-slot name="iconTrailing"><span class="text-sm text-faint">{{ \App\Support\Money::symbol() }}</span></x-slot>
            </flux:input>

            @if ($this->isLoan())
                <div class="grid gap-5 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.400ms="interestRate" :label="__('İllik faiz dərəcəsi')" inputmode="decimal" placeholder="18" required :disabled="$termsLocked">
                        <x-slot name="iconTrailing"><span class="text-sm text-faint">%</span></x-slot>
                    </flux:input>
                    <flux:input wire:model.live.debounce.400ms="termMonths" type="number" min="1" max="480" :label="__('Müddət (ay)')" required :disabled="$termsLocked" />
                </div>

                <flux:select wire:model.live="scheduleType" :label="__('Qrafik növü')" :disabled="$termsLocked" :description="__('Bank “düşən faiz” deyirsə və hər ay eyni məbləğ ödəyirsinizsə — Annuitet.')">
                    @foreach (\App\Enums\LoanScheduleType::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="grid gap-5 sm:grid-cols-2">
                    <flux:input wire:model.live="startedOn" type="date" :label="__('Kreditin alındığı tarix')" :disabled="$termsLocked" />
                    <flux:input wire:model.live="firstDueOn" type="date" :label="__('İlk ödəniş tarixi')" required :disabled="$termsLocked" :description="__('Növbəti ödənişlər hər ay eyni gün')" />
                </div>

                <details class="group rounded-xl border border-line px-4 py-3" @if ($monthlyPayment !== '' || $interestMethod !== 'monthly' || $shiftWeekends) open @endif>
                    <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-muted">
                        <flux:icon name="chevron-right" variant="micro" class="transition group-open:rotate-90" />
                        {{ __('Bankın qrafiki ilə dəqiq uyğunlaşdırma') }}
                    </summary>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2">
                        @if ($scheduleType === 'annuity')
                            <flux:input wire:model.live.debounce.400ms="monthlyPayment" :label="__('Aylıq ödəniş (bankın qrafikindən)')" inputmode="decimal" :placeholder="$this->loanPreview['standard'] ?? null ? \App\Support\Money::toInput($this->loanPreview['standard']) : '0,00'" :disabled="$termsLocked" :description="__('Boş qalsa avtomatik hesablanır')" />
                        @endif
                        <flux:select wire:model.live="interestMethod" :label="__('Faizin hesablanması')" :disabled="$termsLocked" :description="__('Günlük üsul üçün alınma tarixi lazımdır')">
                            @foreach (\App\Enums\InterestMethod::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="mt-6 border-t border-line pt-5">
                        <flux:checkbox wire:model.live="shiftWeekends" :disabled="$termsLocked" :label="__('Həftəsonuna düşən ödəniş növbəti iş gününə keçir')" :description="__('Bank qrafikində şənbə/bazar tarixləri bazar ertəsinə keçirsə, işarələyin')" />
                    </div>
                    <p class="mt-5 text-xs text-faint">{{ __('Bankın qalığı ilə uyğun gəlmirsə: əksər banklar “Günlük (faktiki gün / 360)” və iş günü qaydasını istifadə edir.') }}</p>
                </details>

                @unless ($debt)
                    <div class="grid items-end gap-5 sm:grid-cols-2">
                        <flux:input wire:model.live.debounce.300ms="paidMonths" type="number" min="0" :max="$termMonths - 1" :label="__('Artıq ödənilmiş aylar')" :description="__('Kredit əvvəldən davam edirsə — ilk neçə ay ödənilib')" />
                        @if ($paidMonths > 0)
                            <flux:checkbox wire:model="recordPaidAsExpense" :label="__('Bu ödənişləri xərclərə də yaz')" class="pb-2" />
                        @endif
                    </div>
                @endunless

                @if ($termsLocked)
                    <flux:text class="text-xs">{{ __('Ödəniş edildiyi üçün kredit şərtləri kilidlənib.') }}</flux:text>
                @endif

                @if ($this->loanPreview)
                    <div class="rounded-2xl border border-gold/20 bg-gold-soft p-5">
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div>
                                <div class="eyebrow">{{ $scheduleType === 'annuity' ? __('Aylıq ödəniş') : __('İlk ödəniş') }}</div>
                                <x-money :amount="$this->loanPreview['first']" class="mt-1.5 block text-lg font-semibold text-ink" />
                                <div class="text-[11px] text-faint">{{ __('sonuncu') }} <x-money :amount="$this->loanPreview['last']" /></div>
                            </div>
                            <div>
                                <div class="eyebrow">{{ __('Ümumi faiz') }}</div>
                                <x-money :amount="$this->loanPreview['interest']" class="mt-1.5 block text-lg font-semibold text-negative" />
                            </div>
                            <div>
                                <div class="eyebrow">{{ __('Cəmi ödəniləcək') }}</div>
                                <x-money :amount="$this->loanPreview['total']" class="mt-1.5 block text-lg font-semibold text-ink" />
                            </div>
                            <div>
                                <div class="eyebrow">{{ __('Həddən artıq') }}</div>
                                <div class="mt-1.5 text-lg font-semibold text-ink num">
                                    {{ number_format($this->loanPreview['interest'] * 100 / max(1, $this->loanPreview['total'] - $this->loanPreview['interest']), 1, ',', ' ') }}%
                                </div>
                            </div>
                        </div>
                        @if ($this->loanPreview['paid_count'] > 0)
                            <div class="mt-4 border-t border-gold/20 pt-4 text-sm text-muted">
                                {{ __(':n ay ödənilib', ['n' => $this->loanPreview['paid_count']]) }}: <x-money :amount="$this->loanPreview['paid_total']" class="font-semibold text-ink" />
                                · {{ __('əsas borc qalığı') }}: <x-money :amount="$this->loanPreview['principal_left']" class="font-semibold text-ink" />
                            </div>
                        @endif
                    </div>
                @endif

            @else
                <div class="grid gap-5 sm:grid-cols-2">
                    <flux:input wire:model="startedOn" type="date" :label="__('Başlama tarixi')" />
                    <flux:input wire:model="dueOn" type="date" :label="__('Son ödəniş tarixi')" :description="__('İstəyə bağlı')" />
                </div>
            @endif

            <flux:textarea wire:model="note" :label="__('Qeyd')" rows="3" />
        </div>

        @if (! $debt && ! $this->isLoan())
            <div class="card p-6 sm:p-7">
                <flux:switch wire:model.live="generateSchedule" :label="__('Hissələri avtomatik yarat')" :description="__('Bərabər aylıq hissələr; qalıq qəpik son hissəyə əlavə olunur.')" />

                @if ($generateSchedule)
                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <flux:input wire:model.live.debounce.300ms="installments" type="number" min="1" max="360" :label="__('Neçə ay')" />
                        <flux:input wire:model="firstDueOn" type="date" :label="__('İlk ödəniş tarixi')" :description="__('Boş qalsa hissələr tarixsiz olacaq.')" />
                    </div>
                    @if ($this->previewSchedule())
                        <div class="mt-5 flex items-center gap-2 rounded-xl bg-gold-soft px-4 py-3 text-sm font-medium text-gold">
                            <flux:icon name="calculator" variant="mini" />
                            {{ $this->previewSchedule() }}
                        </div>
                    @endif
                @endif
            </div>
        @endif

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="$debt ? $debt->url() : \App\Models\Debt::indexUrl($kind)" wire:navigate>{{ __('Ləğv et') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('Yadda saxla') }}</flux:button>
        </div>
    </form>
</div>
