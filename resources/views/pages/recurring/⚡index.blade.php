<?php

use App\Actions\Recurring\CreateRecurringPayment;
use App\Actions\Recurring\DeleteRecurringPayment;
use App\Actions\Recurring\GenerateOccurrences;
use App\Actions\Recurring\UpdateRecurringPayment;
use App\Enums\DebtKind;
use App\Enums\Frequency;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPayment;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Daimi ödənişlər')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $amount = '';

    public bool $isVariable = false;

    public ?int $categoryId = null;

    public string $frequency = 'monthly';

    public ?int $interval = 1;

    public ?int $dayOfMonth = null;

    public ?int $dayOfWeek = 1;

    public ?int $monthOfYear = 1;

    public string $startsOn = '';

    public string $endsOn = '';

    public ?int $remindDaysBefore = 3;

    public bool $isActive = true;

    public string $note = '';

    public ?int $deletingId = null;

    public string $month = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(GenerateOccurrences $generateOccurrences): void
    {
        $generateOccurrences->ensureForUser(Auth::user());
        $this->month = today()->format('Y-m');
    }

    /**
     * @return Collection<int, RecurringPayment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return Auth::user()->recurringPayments()
            ->with(['category', 'occurrences' => fn ($query) => $query->whereNull('paid_at')->whereNull('skipped_at')->where('due_on', '>=', today())->orderBy('due_on')])
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $query->where(fn ($q) => $q->whereLike('name', $term)->orWhereLike('note', $term)
                    ->orWhereHas('category', fn ($c) => $c->whereLike('name', $term)));
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    /**
     * Occurrences due in the selected month (the month's checklist).
     *
     * @return \Illuminate\Support\Collection<int, RecurringOccurrence>
     */
    #[Computed]
    public function monthOccurrences(): \Illuminate\Support\Collection
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return Auth::user()->recurringOccurrences()
            ->with('recurringPayment.category')
            ->whereBetween('recurring_occurrences.due_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderBy('recurring_occurrences.due_on')
            ->get()
            ->filter(fn (RecurringOccurrence $occurrence): bool => $this->matchesSearch($occurrence->recurringPayment->name.' '.$occurrence->recurringPayment->category?->name))
            ->values();
    }

    /**
     * Bank loan installments due in the selected month, shown in the same checklist.
     *
     * @return \Illuminate\Support\Collection<int, DebtPayment>
     */
    #[Computed]
    public function monthLoanPayments(): \Illuminate\Support\Collection
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return Auth::user()->debtPayments()
            ->with('debt')
            ->where('debts.kind', DebtKind::Loan)
            ->whereBetween('debt_payments.due_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderBy('debt_payments.due_on')
            ->get()
            ->filter(fn (DebtPayment $payment): bool => $this->matchesSearch($payment->debt->counterparty.' '.$payment->debt->title))
            ->values();
    }

    private function matchesSearch(string $haystack): bool
    {
        $term = mb_strtolower(trim($this->search));

        return $term === '' || str_contains(mb_strtolower($haystack), $term);
    }

    public function updatedSearch(): void
    {
        unset($this->payments, $this->monthOccurrences, $this->monthLoanPayments);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Auth::user()->categories()
            ->ofType(TransactionType::Expense)
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $this->categoryId))
            ->ordered()
            ->get();
    }

    #[Computed]
    public function monthlyTotal(): int
    {
        return (int) Auth::user()->recurringPayments()->get()
            ->filter(fn (RecurringPayment $payment) => $payment->is_active && (! $payment->ends_on || $payment->ends_on->gte(today())))
            ->sum(fn (RecurringPayment $payment) => $payment->monthlyEquivalent());
    }

    public function shiftMonth(int $by): void
    {
        $this->month = CarbonImmutable::createFromFormat('!Y-m', $this->month)->addMonthsNoOverflow($by)->format('Y-m');
        unset($this->monthOccurrences, $this->monthLoanPayments);
    }

    #[On('payment-updated')]
    public function refreshLists(): void
    {
        unset($this->payments, $this->monthOccurrences, $this->monthLoanPayments);
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'amount', 'isVariable', 'categoryId', 'endsOn', 'note');
        $this->frequency = 'monthly';
        $this->interval = 1;
        $this->dayOfMonth = today()->day;
        $this->dayOfWeek = today()->dayOfWeekIso;
        $this->monthOfYear = today()->month;
        $this->startsOn = today()->toDateString();
        $this->remindDaysBefore = 3;
        $this->isActive = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $payment = Auth::user()->recurringPayments()->findOrFail($id);
        $this->authorize('update', $payment);

        $this->resetValidation();
        $this->editingId = $payment->id;
        $this->name = $payment->name;
        $this->amount = Money::toInput($payment->amount);
        $this->isVariable = $payment->is_variable;
        $this->categoryId = $payment->category_id;
        $this->frequency = $payment->frequency->value;
        $this->interval = $payment->interval;
        $this->dayOfMonth = $payment->day_of_month ?? $payment->starts_on->day;
        $this->dayOfWeek = $payment->day_of_week ?? $payment->starts_on->dayOfWeekIso;
        $this->monthOfYear = $payment->month_of_year ?? $payment->starts_on->month;
        $this->startsOn = $payment->starts_on->toDateString();
        $this->endsOn = (string) $payment->ends_on?->toDateString();
        $this->remindDaysBefore = $payment->remind_days_before;
        $this->isActive = $payment->is_active;
        $this->note = (string) $payment->note;
        $this->showForm = true;
    }

    public function save(CreateRecurringPayment $createRecurringPayment, UpdateRecurringPayment $updateRecurringPayment): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', new MoneyAmount],
            'isVariable' => ['boolean'],
            'categoryId' => ['nullable', 'integer'],
            'frequency' => ['required', Rule::enum(Frequency::class)],
            'interval' => ['required', 'integer', 'min:1', 'max:24'],
            'dayOfMonth' => ['nullable', 'required_unless:frequency,weekly', 'integer', 'min:1', 'max:31'],
            'dayOfWeek' => ['nullable', 'required_if:frequency,weekly', 'integer', 'min:1', 'max:7'],
            'monthOfYear' => ['nullable', 'required_if:frequency,yearly', 'integer', 'min:1', 'max:12'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'remindDaysBefore' => ['required', 'integer', 'min:0', 'max:60'],
            'isActive' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $frequency = Frequency::from($validated['frequency']);

        $data = [
            'name' => $validated['name'],
            'amount' => Money::parse($validated['amount']),
            'is_variable' => $validated['isVariable'],
            'category_id' => $validated['categoryId'],
            'frequency' => $frequency,
            'interval' => $validated['interval'],
            'day_of_month' => $frequency === Frequency::Weekly ? null : $validated['dayOfMonth'],
            'day_of_week' => $frequency === Frequency::Weekly ? $validated['dayOfWeek'] : null,
            'month_of_year' => $frequency === Frequency::Yearly ? $validated['monthOfYear'] : null,
            'starts_on' => $validated['startsOn'],
            'ends_on' => $validated['endsOn'] ?: null,
            'remind_days_before' => $validated['remindDaysBefore'],
            'is_active' => $validated['isActive'],
            'note' => $validated['note'] ?: null,
        ];

        if ($this->editingId) {
            $payment = Auth::user()->recurringPayments()->findOrFail($this->editingId);
            $this->authorize('update', $payment);
            $updateRecurringPayment->handle($payment, $data);
            Flux::toast(variant: 'success', text: __('Daimi ödəniş yeniləndi.'));
        } else {
            $createRecurringPayment->handle(Auth::user(), $data);
            Flux::toast(variant: 'success', text: __('Daimi ödəniş əlavə olundu.'));
        }

        $this->showForm = false;
        $this->refreshLists();
    }

    public function toggleActive(int $id, UpdateRecurringPayment $updateRecurringPayment): void
    {
        $payment = Auth::user()->recurringPayments()->findOrFail($id);
        $this->authorize('update', $payment);

        $updateRecurringPayment->handle($payment, ['is_active' => ! $payment->is_active]);

        Flux::toast(text: $payment->is_active ? __('Aktivləşdirildi.') : __('Dayandırıldı.'));
        $this->refreshLists();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('delete-recurring')->show();
    }

    public function delete(DeleteRecurringPayment $deleteRecurringPayment): void
    {
        $payment = Auth::user()->recurringPayments()->findOrFail($this->deletingId);
        $this->authorize('delete', $payment);

        $deleteRecurringPayment->handle($payment);

        Flux::modal('delete-recurring')->close();
        Flux::toast(text: __('Daimi ödəniş silindi.'));
        $this->refreshLists();
    }
}; ?>

@php($selectEntries = $this->payments->where('is_active', true)->map(fn ($p) => ['rp-'.$p->id, $p->monthlyEquivalent(), $p->name])->values()->all())

<div>
    <x-page-header :eyebrow="__('Abunələr və öhdəliklər')" :heading="__('Daimi ödənişlər')">
        <x-slot name="subheading">{{ __('Aylıq öhdəlik') }}: <x-money :amount="$this->monthlyTotal" class="font-semibold text-ink" /></x-slot>
        <x-slot name="actions">
            <flux:button icon="plus" variant="primary" wire:click="create">{{ __('Daimi ödəniş') }}</flux:button>
        </x-slot>
    </x-page-header>

    @if ($this->payments->isEmpty() && $this->monthLoanPayments->isEmpty() && trim($search) === '')
        <x-empty-state icon="arrow-path" :heading="__('Hələ daimi ödəniş yoxdur')" :text="__('İcarə, internet, abunələr kimi təkrarlanan ödənişləri əlavə edin.')">
            <flux:button size="sm" icon="plus" variant="primary" wire:click="create">{{ __('Daimi ödəniş əlavə et') }}</flux:button>
        </x-empty-state>
    @else
        <div class="grid gap-8 xl:grid-cols-[1.35fr_1fr]">
            <section>
                <x-search-input :placeholder="__('Ad, kateqoriya, qeyd…')" class="mb-4 w-full sm:w-72" />

                @if ($this->payments->isEmpty() && trim($search) !== '')
                    <x-empty-state icon="magnifying-glass" :heading="__('“:q” üzrə heç nə tapılmadı', ['q' => trim($search)])" />
                @elseif ($this->payments->isEmpty())
                    <x-empty-state icon="arrow-path" :heading="__('Hələ daimi ödəniş yoxdur')" :text="__('İcarə, internet, abunələr kimi təkrarlanan ödənişləri əlavə edin.')">
                        <flux:button size="sm" icon="plus" variant="primary" wire:click="create">{{ __('Daimi ödəniş əlavə et') }}</flux:button>
                    </x-empty-state>
                @else
                <div class="mb-3 flex items-center gap-3 px-4">
                    <x-select-all :entries="$selectEntries" :label="__('Aktivlərin hamısını seç')" />
                </div>

                <div class="card divide-y divide-line overflow-hidden">
                    @foreach ($this->payments as $payment)
                        @php($next = $payment->occurrences->first())
                        <div
                            wire:key="recurring-{{ $payment->id }}"
                            x-data
                            x-bind:class="$store.selection.has('rp-{{ $payment->id }}') && 'bg-gold-soft'"
                            @class(['group flex items-center gap-4 px-4 py-4 transition-colors hover:bg-surface-2/60', 'opacity-55' => ! $payment->is_active])
                        >
                            <x-select-toggle :key="'rp-'.$payment->id" :amount="$payment->monthlyEquivalent()" :label="$payment->name" />

                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" style="background: {{ $payment->category->color ?? '#a1a1aa' }}1f; color: {{ $payment->category->color ?? '#a1a1aa' }}">
                                <flux:icon :name="$payment->category->icon ?? 'arrow-path'" variant="mini" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold text-ink">{{ $payment->name }}</span>
                                    @if ($payment->is_variable)<span class="rounded-full bg-line px-1.5 py-0.5 text-[10px] font-medium text-muted">{{ __('dəyişkən') }}</span>@endif
                                    @unless ($payment->is_active)<span class="rounded-full bg-line px-1.5 py-0.5 text-[10px] font-medium text-muted">{{ __('dayandırılıb') }}</span>@endunless
                                </div>
                                <div class="truncate text-xs text-faint">
                                    {{ $payment->scheduleLabel() }}
                                    @if ($next) · {{ __('növbəti') }} {{ \App\Support\Preferences::formatDate($next->due_on) }}@endif
                                    @if ($payment->ends_on) · {{ __('bitir') }} {{ \App\Support\Preferences::formatDate($payment->ends_on) }}@endif
                                </div>
                            </div>

                            <div class="text-end">
                                <x-money :amount="$payment->amount" class="block text-[15px] font-semibold text-ink" />
                                @if ($payment->frequency !== \App\Enums\Frequency::Monthly || $payment->interval > 1)
                                    <span class="text-[11px] text-faint">≈ <x-money :amount="$payment->monthlyEquivalent()" /> / {{ __('ay') }}</span>
                                @endif
                            </div>

                            <flux:dropdown align="end">
                                <flux:button size="sm" variant="subtle" icon="ellipsis-horizontal" :aria-label="__('Əməliyyatlar')" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $payment->id }})">{{ __('Redaktə et') }}</flux:menu.item>
                                    <flux:menu.item :icon="$payment->is_active ? 'pause' : 'play'" wire:click="toggleActive({{ $payment->id }})">
                                        {{ $payment->is_active ? __('Dayandır') : __('Aktivləşdir') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $payment->id }})">{{ __('Sil') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    @endforeach
                </div>
                @endif
            </section>

            <section>
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <div class="eyebrow">{{ __('Aylıq checklist') }}</div>
                        <h2 class="mt-1 text-base font-semibold text-ink">{{ ucfirst(\Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->translatedFormat('F Y')) }}</h2>
                    </div>
                    <div class="flex items-center gap-1 rounded-xl border border-line bg-surface p-1">
                        <button type="button" wire:click="shiftMonth(-1)" class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted transition hover:bg-surface-2 hover:text-ink" aria-label="{{ __('Əvvəlki ay') }}"><flux:icon name="chevron-left" variant="micro" /></button>
                        <button type="button" wire:click="shiftMonth(1)" class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted transition hover:bg-surface-2 hover:text-ink" aria-label="{{ __('Növbəti ay') }}"><flux:icon name="chevron-right" variant="micro" /></button>
                    </div>
                </div>

                @php($occurrenceEntries = $this->monthOccurrences->toBase()->reject->isSkipped()->map(fn ($o) => ['occurrence-'.$o->id, $o->isPaid() && $o->paid_amount !== null ? $o->paid_amount : $o->amount, $o->recurringPayment->name])
                    ->merge($this->monthLoanPayments->toBase()->map(fn ($p) => ['debt_payment-'.$p->id, $p->isPaid() && $p->paid_amount !== null ? $p->paid_amount : $p->amount, $p->debt->counterparty]))
                    ->values()->all())
                @if ($occurrenceEntries)
                    <x-select-all :entries="$occurrenceEntries" class="mb-2 ms-3.5" />
                @endif

                <div class="space-y-2">
                    @forelse ($this->monthOccurrences as $occurrence)
                        <x-payment-row
                            wire:key="occurrence-{{ $occurrence->id }}"
                            kind="occurrence"
                            :id="$occurrence->id"
                            :title="$occurrence->recurringPayment->name"
                            :subtitle="$occurrence->recurringPayment->category?->name"
                            :due-on="$occurrence->due_on"
                            :amount="$occurrence->amount"
                            :paid="$occurrence->isPaid()"
                            :paid-at="$occurrence->paid_at"
                            :paid-amount="$occurrence->paid_amount"
                            :skipped="$occurrence->isSkipped()"
                            :remind-days-before="$occurrence->recurringPayment->remind_days_before"
                        >
                            <x-slot name="actions">
                                @unless ($occurrence->isPaid())
                                    <flux:dropdown align="end">
                                        <flux:button size="xs" variant="subtle" icon="ellipsis-vertical" :aria-label="__('Əməliyyatlar')" />
                                        <flux:menu>
                                            @if ($occurrence->isSkipped())
                                                <flux:menu.item icon="arrow-uturn-left" x-on:click="$dispatch('skip-item', { id: {{ $occurrence->id }}, skip: false })">{{ __('Keçidi ləğv et') }}</flux:menu.item>
                                            @else
                                                <flux:menu.item icon="forward" x-on:click="$dispatch('skip-item', { id: {{ $occurrence->id }}, skip: true })">{{ __('Bu dəfə keç') }}</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                @endunless
                            </x-slot>
                        </x-payment-row>
                    @empty
                        @if ($this->monthLoanPayments->isEmpty())
                            <x-empty-state icon="calendar" :heading="__('Bu ay ödəniş yoxdur')" />
                        @endif
                    @endforelse

                    @foreach ($this->monthLoanPayments as $loanPayment)
                        <x-payment-row
                            wire:key="loan-payment-{{ $loanPayment->id }}"
                            kind="debt_payment"
                            :id="$loanPayment->id"
                            :title="$loanPayment->debt->counterparty"
                            :subtitle="__('Kredit').' · '.__(':n / :total ay', ['n' => $loanPayment->installment_number, 'total' => $loanPayment->debt->term_months])"
                            :due-on="$loanPayment->due_on"
                            :amount="$loanPayment->amount"
                            :paid="$loanPayment->isPaid()"
                            :paid-at="$loanPayment->paid_at"
                            :paid-amount="$loanPayment->paid_amount"
                            :href="$loanPayment->debt->url()"
                        />
                    @endforeach
                </div>
            </section>
        </div>
    @endif

    <flux:modal wire:model.self="showForm" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Daimi ödənişi redaktə et') : __('Yeni daimi ödəniş') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Ad')" :placeholder="__('məs. İnternet')" required />
                <flux:input wire:model="amount" :label="__('Məbləğ')" inputmode="decimal" placeholder="0,00" required>
                    <x-slot name="iconTrailing"><span class="text-sm text-zinc-500">{{ \App\Support\Money::symbol() }}</span></x-slot>
                </flux:input>
            </div>

            <flux:checkbox wire:model="isVariable" :label="__('Məbləğ dəyişkəndir')" :description="__('məs. işıq pulu — ödəyəndə faktiki məbləğ soruşulacaq')" />

            <flux:select wire:model="categoryId" :label="__('Kateqoriya')" :placeholder="__('Kateqoriya seçin')">
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id" wire:key="rc-{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="frequency" :label="__('Tezlik')">
                    @foreach (\App\Enums\Frequency::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="interval" type="number" min="1" max="24" :label="__('Hər neçə (interval)')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($frequency === 'weekly')
                    <flux:select wire:model="dayOfWeek" :label="__('Həftənin günü')">
                        @foreach (range(1, 7) as $day)
                            <flux:select.option :value="$day">{{ ucfirst(now()->startOfWeek()->addDays($day - 1)->translatedFormat('l')) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    @if ($frequency === 'yearly')
                        <flux:select wire:model="monthOfYear" :label="__('Ay')">
                            @foreach (range(1, 12) as $m)
                                <flux:select.option :value="$m">{{ ucfirst(now()->startOfYear()->setMonth($m)->translatedFormat('F')) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:input wire:model="dayOfMonth" type="number" min="1" max="31" :label="__('Ayın günü')" :description="__('31 seçilsə, qısa aylarda ayın son günü')" />
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="startsOn" type="date" :label="__('Başlama tarixi')" required />
                <flux:input wire:model="endsOn" type="date" :label="__('Bitmə tarixi')" :description="__('İstəyə bağlı')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="remindDaysBefore" type="number" min="0" max="60" :label="__('Neçə gün əvvəl xatırlat')" />
                <div class="flex items-end pb-2">
                    <flux:switch wire:model="isActive" :label="__('Aktiv')" />
                </div>
            </div>

            <flux:input wire:model="note" :label="__('Qeyd')" />

            @if ($editingId)
                <flux:text class="text-xs">{{ __('Dəyişiklik yalnız gələcək, ödənilməmiş ödənişlərə tətbiq olunur.') }}</flux:text>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Yadda saxla') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-recurring" class="w-full max-w-sm">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Daimi ödəniş silinsin?') }}</flux:heading>
            <flux:text>{{ __('Gözləyən ödənişlər silinəcək. Artıq ödənilmiş ödənişlər və xərclər tarixçədə qalacaq.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Sil') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
