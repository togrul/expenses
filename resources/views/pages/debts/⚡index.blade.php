<?php

use App\Actions\Debts\RecordDebtPaymentExpense;
use App\Enums\DebtDirection;
use App\Enums\DebtKind;
use App\Models\Debt;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One list, two sections: personal debts (/debts) and bank loans (/loans).
 */
new class extends Component {
    #[Locked]
    public string $kind = 'personal';

    #[Url]
    public string $status = 'active';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->kind = request()->routeIs('loans.*') ? DebtKind::Loan->value : DebtKind::Personal->value;
    }

    public function render(): mixed
    {
        return $this->view()->title($this->isLoan() ? __('Kreditlər') : __('Borclar'));
    }

    public function isLoan(): bool
    {
        return $this->kind === DebtKind::Loan->value;
    }

    /**
     * @return Collection<int, Debt>
     */
    #[Computed]
    public function debts(): Collection
    {
        return Auth::user()->debts()
            ->where('kind', $this->kind)
            ->withPaidTotal()
            ->with('payments')
            ->when($this->status === 'active', fn ($query) => $query->whereNull('closed_at'))
            ->when($this->status === 'closed', fn ($query) => $query->whereNotNull('closed_at'))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $query->where(fn ($q) => $q->whereLike('counterparty', $term)->orWhereLike('title', $term)->orWhereLike('note', $term));
            })
            ->orderByRaw('closed_at is not null')
            ->latest()
            ->get();
    }

    /**
     * Paid installments of this section that were never written into expenses.
     *
     * @return array{count: int, amount: int}
     */
    #[Computed]
    public function unrecorded(): array
    {
        $row = Auth::user()->debtPayments()
            ->where('debts.kind', $this->kind)
            ->whereNotNull('debt_payments.paid_at')
            ->whereNull('debt_payments.transaction_id')
            ->toBase()
            ->selectRaw('count(*) as count, coalesce(sum(debt_payments.paid_amount), 0) as amount')
            ->first();

        return ['count' => (int) ($row->count ?? 0), 'amount' => (int) ($row->amount ?? 0)];
    }

    public function recordAllExpenses(RecordDebtPaymentExpense $recordDebtPaymentExpense): void
    {
        $created = $recordDebtPaymentExpense->forDebts(Auth::user()->debts()->where('kind', $this->kind)->get());

        Flux::toast(variant: 'success', text: trans_choice(':count ödəniş xərclərə yazıldı', $created, ['count' => $created]));
        unset($this->unrecorded);
    }

    /**
     * Summary over the open items of this section, independent of the search.
     *
     * @return array{remaining: int, paid: int, total: int, count: int, principal_left: int, monthly: int, interest_paid: int, interest_left: int}
     */
    #[Computed]
    public function totals(): array
    {
        $open = Auth::user()->debts()
            ->where('kind', $this->kind)
            ->where('direction', DebtDirection::IOwe)
            ->whereNull('closed_at')
            ->withPaidTotal()
            ->with('payments')
            ->get();

        $loans = $this->isLoan() ? $open->map(fn (Debt $debt) => $debt->loanSummary()) : collect();

        return [
            'remaining' => (int) $open->sum(fn (Debt $debt) => $debt->remaining()),
            'paid' => (int) $open->sum(fn (Debt $debt) => $debt->paidTotal()),
            'total' => (int) $open->sum('total_amount'),
            'count' => $open->count(),
            'principal_left' => (int) $loans->sum('principal_left'),
            'monthly' => (int) $loans->sum('next_payment'),
            'interest_paid' => (int) $loans->sum('interest_paid'),
            'interest_left' => (int) $loans->sum('interest_left'),
        ];
    }
}; ?>

@php($createUrl = route($this->isLoan() ? 'loans.create' : 'debts.create'))

<div>
    <x-page-header
        :eyebrow="$this->isLoan() ? __('Bank öhdəlikləri') : __('Şəxsi öhdəliklər')"
        :heading="$this->isLoan() ? __('Kreditlər') : __('Borclar')"
        :subheading="$this->isLoan() ? __('Bank kreditləri: əsas borc, faiz və aylıq ödəniş qrafiki') : __('Dostlara, tanışlara və mağazalara olan borclar')"
    >
        <x-slot name="actions">
            <flux:button icon="plus" variant="primary" :href="$createUrl" wire:navigate>{{ $this->isLoan() ? __('Kredit əlavə et') : __('Borc əlavə et') }}</flux:button>
        </x-slot>
    </x-page-header>

    @if ($this->totals['count'] > 0)
        @php($percent = $this->totals['total'] > 0 ? (int) floor($this->totals['paid'] * 100 / $this->totals['total']) : 0)

        @if ($this->isLoan())
            <div class="card gold-glow mb-8 grid gap-6 p-6 sm:grid-cols-[1.4fr_1fr_1fr] sm:p-7">
                <div>
                    <div class="eyebrow">{{ __('Əsas borc qalığı') }}</div>
                    <x-money :amount="$this->totals['principal_left']" class="mt-2 block text-4xl font-semibold text-ink" />
                    <div class="mt-4 flex items-center gap-3">
                        <x-meter :value="$percent" class="max-w-xs" />
                        <span class="text-xs font-medium text-muted num">{{ $percent }}% {{ __('ödənilib') }}</span>
                    </div>
                </div>
                <div class="sm:border-s sm:border-line sm:ps-6">
                    <div class="eyebrow">{{ __('Aylıq ödəniş') }}</div>
                    <x-money :amount="$this->totals['monthly']" class="mt-2 block text-xl font-semibold text-gold" />
                    <div class="mt-1 text-xs text-faint">{{ trans_choice(':count aktiv kredit', $this->totals['count'], ['count' => $this->totals['count']]) }}</div>
                </div>
                <div class="sm:border-s sm:border-line sm:ps-6">
                    <div class="eyebrow">{{ __('Faiz') }}</div>
                    <x-money :amount="$this->totals['interest_paid']" class="mt-2 block text-xl font-semibold text-negative" />
                    <div class="mt-1 text-xs text-faint">{{ __('ödənilib') }} · {{ __('qalıb') }} <x-money :amount="$this->totals['interest_left']" /></div>
                </div>
            </div>
        @else
            <div class="card gold-glow mb-8 grid gap-6 p-6 sm:grid-cols-[1.4fr_1fr_1fr] sm:p-7">
                <div>
                    <div class="eyebrow">{{ __('Ümumi qalıq') }}</div>
                    <x-money :amount="$this->totals['remaining']" class="mt-2 block text-4xl font-semibold text-ink" />
                    <div class="mt-4 flex items-center gap-3">
                        <x-meter :value="$percent" class="max-w-xs" />
                        <span class="text-xs font-medium text-muted num">{{ $percent }}% {{ __('ödənilib') }}</span>
                    </div>
                </div>
                <div class="sm:border-s sm:border-line sm:ps-6">
                    <div class="eyebrow">{{ __('Ödənilib') }}</div>
                    <x-money :amount="$this->totals['paid']" class="mt-2 block text-xl font-semibold text-positive" />
                    <div class="mt-1 text-xs text-faint">{{ __('ümumi') }} <x-money :amount="$this->totals['total']" /></div>
                </div>
                <div class="sm:border-s sm:border-line sm:ps-6">
                    <div class="eyebrow">{{ __('Aktiv borc') }}</div>
                    <div class="mt-2 text-xl font-semibold text-ink num">{{ $this->totals['count'] }}</div>
                    <div class="mt-1 text-xs text-faint">{{ __('Cəmləmək üçün kartları seçin') }}</div>
                </div>
            </div>
        @endif
    @endif

    @if ($this->unrecorded['count'] > 0)
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gold/25 bg-gold-soft px-4 py-3">
            <div class="flex items-center gap-2.5 text-sm text-ink">
                <flux:icon name="exclamation-triangle" variant="mini" class="text-gold" />
                <span>
                    {{ __(':n ödənilmiş ay xərclərə yazılmayıb', ['n' => $this->unrecorded['count']]) }}
                    · <x-money :amount="$this->unrecorded['amount']" class="font-semibold" />
                </span>
            </div>
            <flux:button size="sm" variant="primary" wire:click="recordAllExpenses">{{ __('Hamısını xərclərə yaz') }}</flux:button>
        </div>
    @endif

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <x-segmented model="status" :value="$status" :options="['active' => __('Aktiv'), 'closed' => __('Bağlanmış'), 'all' => __('Hamısı')]" />
            <x-search-input :placeholder="$this->isLoan() ? __('Bank, başlıq, qeyd…') : __('Ad, başlıq, qeyd…')" class="w-64" />
        </div>
        <x-select-all :entries="$this->debts->map(fn ($d) => ['debt-'.$d->id, $d->isLoan() ? $d->loanSummary()['principal_left'] : $d->remaining(), $d->counterparty])->values()->all()" />
    </div>

    @if ($this->debts->isEmpty())
        <x-empty-state
            :icon="$this->isLoan() ? 'landmark' : 'banknotes'"
            :heading="trim($search) !== ''
                ? __('“:q” üzrə heç nə tapılmadı', ['q' => trim($search)])
                : ($status === 'closed' ? __('Bağlanmış qeyd yoxdur') : ($this->isLoan() ? __('Hələ kredit yoxdur') : __('Hələ borc yoxdur')))"
            :text="$this->isLoan() ? __('Bank kreditini əlavə edin — qrafik, faiz və qalıq avtomatik hesablanacaq.') : __('Borcları və onların hissə-hissə ödənişini burada izləyin.')"
        >
            <flux:button size="sm" icon="plus" variant="primary" :href="$createUrl" wire:navigate>{{ $this->isLoan() ? __('Kredit əlavə et') : __('Borc əlavə et') }}</flux:button>
        </x-empty-state>
    @else
        <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($this->debts as $debt)
                @php($debtStatus = $debt->status())
                @php($next = $debt->nextPayment())
                @php($loan = $debt->isLoan() ? $debt->loanSummary() : null)
                @php($headline = $loan ? $loan['principal_left'] : $debt->remaining())
                <div
                    wire:key="debt-{{ $debt->id }}"
                    x-data
                    x-bind:class="$store.selection.has('debt-{{ $debt->id }}') && '!border-gold/60 ring-1 ring-gold/40'"
                    @class(['card card-hover group relative flex flex-col p-5', 'opacity-70' => $debtStatus === \App\Enums\DebtStatus::Paid])
                >
                    {{-- Stretched link: the whole card opens the item, the select toggle sits above it. --}}
                    <a href="{{ $debt->url() }}" wire:navigate class="absolute inset-0 z-0 rounded-[inherit] focus-visible:outline-2 focus-visible:outline-gold" aria-label="{{ $debt->displayName() }}"></a>

                    <div class="pointer-events-none flex items-start gap-3">
                        <x-initials :name="$debt->counterparty" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[15px] font-semibold text-ink">{{ $debt->counterparty }}</div>
                            <div class="flex items-center gap-1.5 truncate text-xs text-faint">
                                @if ($loan)
                                    <span class="rounded-md bg-gold-soft px-1.5 py-0.5 text-[10px] font-semibold text-gold num">{{ rtrim(rtrim((string) $debt->interest_rate, '0'), '.') }}%</span>
                                @endif
                                <span class="truncate">{{ $debt->title ?: ($loan ? __(':n ay', ['n' => $debt->term_months]) : __('Borc')) }}</span>
                            </div>
                        </div>
                        <x-select-toggle :key="'debt-'.$debt->id" :amount="$headline" :label="$debt->counterparty" class="pointer-events-auto relative z-10 mt-0.5" />
                    </div>

                    <div class="pointer-events-none mt-6 flex items-end justify-between gap-4">
                        <div>
                            <div class="eyebrow">{{ $loan ? __('Əsas borc qalığı') : __('Qalıq') }}</div>
                            <x-money :amount="$headline" class="mt-1.5 block text-[1.75rem] font-semibold leading-none text-ink" />
                        </div>
                        <x-status-pill :status="$debtStatus" />
                    </div>

                    <div class="pointer-events-none mt-5 flex items-center gap-3">
                        <x-meter :value="$debt->progressPercent()" :tone="$debtStatus === \App\Enums\DebtStatus::Overdue ? 'negative' : ($debtStatus === \App\Enums\DebtStatus::Paid ? 'positive' : 'gold')" />
                        <span class="text-xs font-semibold text-muted num">
                            {{ $loan ? $loan['paid_count'].' / '.$loan['count'] : $debt->progressPercent().'%' }}
                        </span>
                    </div>

                    <div class="pointer-events-none mt-5 grid grid-cols-2 gap-3 border-t border-line pt-4 text-xs">
                        <div>
                            @if ($loan)
                                <div class="text-faint">{{ __('Aylıq ödəniş') }}</div>
                                <x-money :amount="(int) ($loan['next_payment'] ?? 0)" class="mt-0.5 block font-medium text-gold" />
                            @else
                                <div class="text-faint">{{ __('Ümumi') }}</div>
                                <x-money :amount="$debt->total_amount" class="mt-0.5 block font-medium text-muted" />
                            @endif
                        </div>
                        <div class="text-end">
                            @if ($next)
                                <div class="text-faint">{{ __('Növbəti ödəniş') }}</div>
                                <div @class(['mt-0.5 font-medium', 'text-negative' => $next->isOverdue(), 'text-muted' => ! $next->isOverdue()])>
                                    {{ \App\Support\Preferences::formatDate($next->due_on, __('tarixsiz')) }}@unless ($loan) · <x-money :amount="$next->amount" />@endunless
                                </div>
                            @else
                                <div class="text-faint">{{ __('Ödənilib') }}</div>
                                <x-money :amount="$debt->paidTotal()" class="mt-0.5 block font-medium text-positive" />
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
