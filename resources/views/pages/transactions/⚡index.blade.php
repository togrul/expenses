<?php

use App\Actions\Transactions\DeleteTransaction;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use App\Models\Transaction;
use App\Support\TransactionFilters;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Əməliyyatlar')] class extends Component {
    use WithPagination;

    #[Url]
    public string $month = '';

    #[Url]
    public string $type = '';

    #[Url(as: 'category')]
    public ?int $categoryId = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $deletingId = null;

    public function mount(): void
    {
        if ($this->month === '' && $this->from === '' && $this->to === '') {
            $this->month = today()->format('Y-m');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true) && ($this->from !== '' || $this->to !== '')) {
            $this->month = '';
        }

        if ($property === 'type') {
            $this->categoryId = null;
        }

        $this->resetPage();
    }

    public function shiftMonth(int $by): void
    {
        $base = $this->month !== '' ? CarbonImmutable::createFromFormat('!Y-m', $this->month) : today();
        $this->month = $base->addMonthsNoOverflow($by)->format('Y-m');
        $this->from = $this->to = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('type', 'categoryId', 'from', 'to', 'search');
        $this->month = today()->format('Y-m');
        $this->resetPage();
    }

    public function filters(): TransactionFilters
    {
        return new TransactionFilters(
            month: $this->month ?: null,
            from: $this->from ?: null,
            to: $this->to ?: null,
            type: $this->type ?: null,
            categoryId: $this->categoryId,
            search: trim($this->search) ?: null,
        );
    }

    /**
     * @return LengthAwarePaginator<int, Transaction>
     */
    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        return $this->filters()->apply(Auth::user())
            ->with(['category', 'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                DebtPayment::class => ['debt'],
                RecurringOccurrence::class => ['recurringPayment'],
            ])])
            ->latest('occurred_on')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * @return array{income: int, expense: int, net: int}
     */
    #[Computed]
    public function summary(): array
    {
        $sums = $this->filters()->apply(Auth::user())
            ->groupBy('type')
            ->selectRaw('type, sum(amount) as total')
            ->pluck('total', 'type');

        $income = (int) ($sums[TransactionType::Income->value] ?? 0);
        $expense = (int) ($sums[TransactionType::Expense->value] ?? 0);

        return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Auth::user()->categories()
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->ordered()
            ->get();
    }

    public function periodLabel(): string
    {
        [$from, $to] = $this->filters()->range();

        if ($this->month !== '' && $from) {
            return ucfirst($from->translatedFormat('F Y'));
        }

        return trim(\App\Support\Preferences::formatDate($from, '…').' — '.\App\Support\Preferences::formatDate($to, '…'));
    }

    public function edit(int $id): void
    {
        $this->dispatch('edit-transaction', id: $id);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        Flux::modal('delete-transaction')->show();
    }

    public function delete(DeleteTransaction $deleteTransaction): void
    {
        $transaction = Auth::user()->transactions()->findOrFail($this->deletingId);
        $this->authorize('delete', $transaction);

        try {
            $deleteTransaction->handle($transaction);
            Flux::toast(text: __('Əməliyyat silindi.'));
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        $this->deletingId = null;
        Flux::modal('delete-transaction')->close();
    }

    #[On('transaction-saved')]
    #[On('payment-updated')]
    public function refreshList(): void
    {
        unset($this->transactions, $this->summary);
    }
}; ?>

@php($rows = $this->transactions->getCollection())
@php($selectEntries = $rows->map(fn ($t) => ['tx-'.$t->id, $t->type === \App\Enums\TransactionType::Income ? $t->amount : -$t->amount, $t->category?->name ?? ''])->values()->all())

<div>
    <x-page-header :eyebrow="__('Pul axını')" :heading="__('Əməliyyatlar')" :subheading="$this->periodLabel()">
        <x-slot name="actions">
            <flux:button icon="arrow-down-tray" variant="ghost" :href="route('transactions.export', array_filter(['month' => $month, 'from' => $from, 'to' => $to, 'type' => $type, 'category' => $categoryId, 'q' => $search]))">
                {{ __('CSV') }}
            </flux:button>
            <flux:button icon="plus" variant="primary" x-on:click="Livewire.dispatch('open-transaction-modal')">{{ __('Əməliyyat') }}</flux:button>
        </x-slot>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card :label="__('Gəlir')" icon="arrow-down-left" tone="positive"><x-money :amount="$this->summary['income']" class="text-positive" /></x-stat-card>
        <x-stat-card :label="__('Xərc')" icon="arrow-up-right" tone="negative"><x-money :amount="$this->summary['expense']" /></x-stat-card>
        <x-stat-card :label="__('Fərq')" icon="scale"><x-money :amount="$this->summary['net']" @class(['text-positive' => $this->summary['net'] > 0, 'text-negative' => $this->summary['net'] < 0]) /></x-stat-card>
    </div>

    <div class="card mb-6 grid grid-cols-2 gap-3 p-4 sm:flex sm:flex-wrap sm:items-end">
        <div class="col-span-2 flex items-center justify-between gap-1 rounded-xl border border-line bg-surface-2 p-1 sm:justify-start">
            <button type="button" wire:click="shiftMonth(-1)" class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted transition hover:bg-surface hover:text-ink" aria-label="{{ __('Əvvəlki ay') }}"><flux:icon name="chevron-left" variant="micro" /></button>
            <input type="month" wire:model.live="month" class="min-w-0 flex-1 bg-transparent px-1 text-center sm:w-36 sm:flex-none text-sm font-medium text-ink outline-none dark:[color-scheme:dark]" aria-label="{{ __('Ay') }}" />
            <button type="button" wire:click="shiftMonth(1)" class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted transition hover:bg-surface hover:text-ink" aria-label="{{ __('Növbəti ay') }}"><flux:icon name="chevron-right" variant="micro" /></button>
        </div>

        <x-picker wire:model.live="type" size="sm" class="sm:w-36" :aria-label="__('Növ')" :options="[['value' => '', 'label' => __('Bütün növlər')], ...collect(\App\Enums\TransactionType::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])->all()]" />

        <x-picker wire:model.live="categoryId" size="sm" class="sm:w-48" :aria-label="__('Kateqoriya')" :options="[['value' => null, 'label' => __('Bütün kateqoriyalar')], ...$this->categories->map(fn ($category) => ['value' => $category->id, 'label' => $category->name, 'color' => $category->color])->all()]" />

        <div class="col-span-2 flex items-center gap-1.5">
            <flux:input type="date" size="sm" wire:model.live="from" class="min-w-0 flex-1 sm:!w-36 sm:flex-none" :aria-label="__('Başlanğıc')" />
            <span class="text-faint">–</span>
            <flux:input type="date" size="sm" wire:model.live="to" class="min-w-0 flex-1 sm:!w-36 sm:flex-none" :aria-label="__('Son')" />
        </div>

        <div class="col-span-2 flex items-center gap-3 sm:contents">
            <flux:input wire:model.live.debounce.400ms="search" size="sm" icon="magnifying-glass" :placeholder="__('Qeyd və ya kateqoriya…')" class="min-w-0 flex-1 sm:!w-52 sm:flex-none" />

            <flux:button variant="subtle" size="sm" wire:click="clearFilters" class="ms-auto">{{ __('Sıfırla') }}</flux:button>
        </div>
    </div>

    @if ($rows->isEmpty())
        <x-empty-state icon="arrows-right-left" :heading="__('Bu dövrdə əməliyyat yoxdur')" :text="__('Filtrləri dəyişin və ya yeni əməliyyat əlavə edin.')">
            <flux:button size="sm" icon="plus" variant="primary" x-on:click="Livewire.dispatch('open-transaction-modal')">{{ __('Əməliyyat əlavə et') }}</flux:button>
        </x-empty-state>
    @else
        <div class="mb-3 flex items-center gap-3 px-4">
            <x-select-all :entries="$selectEntries" />
        </div>

        <div class="card overflow-hidden">
            @foreach ($rows->groupBy(fn ($t) => $t->occurred_on->toDateString()) as $date => $dayRows)
                @php($day = \Carbon\CarbonImmutable::parse($date))
                @php($dayNet = $dayRows->sum(fn ($t) => $t->type === \App\Enums\TransactionType::Income ? $t->amount : -$t->amount))
                <div wire:key="day-{{ $date }}" class="border-b border-line last:border-b-0">
                    <div class="flex items-center justify-between bg-surface-2/70 px-4 py-2">
                        <span class="text-xs font-semibold text-muted">
                            {{ $day->isToday() ? __('Bu gün') : ($day->isYesterday() ? __('Dünən') : \App\Support\Preferences::formatDate($day)) }}
                            <span class="font-normal text-faint">· {{ ucfirst($day->translatedFormat('l')) }}</span>
                        </span>
                        <x-money :amount="$dayNet" class="text-xs font-semibold text-faint" />
                    </div>

                    @foreach ($dayRows as $transaction)
                        @php($isIncome = $transaction->type === \App\Enums\TransactionType::Income)
                        <div
                            wire:key="tx-{{ $transaction->id }}"
                            x-data="swipeRow"
                            x-on:touchstart="touchStart($event)"
                            x-on:touchmove="touchMove($event)"
                            x-on:touchend="touchEnd()"
                            x-on:click.capture="guardClick($event)"
                            class="relative overflow-hidden"
                        >
                            <div x-ref="actions" data-row-actions class="absolute inset-y-0 end-0 flex lg:hidden" x-bind:class="offset < 0 ? 'visible' : 'invisible'">
                                <button type="button" class="row-action bg-zinc-500 dark:bg-zinc-600" wire:click="edit({{ $transaction->id }})" x-on:click="close()">
                                    <flux:icon name="pencil-square" variant="outline" class="size-5" />{{ __('Redaktə') }}
                                </button>
                                @if ($transaction->isLinked())
                                    @if ($transaction->sourceUrl())
                                        <a href="{{ $transaction->sourceUrl() }}" wire:navigate.hover class="row-action bg-[#a8823d]">
                                            <flux:icon name="link" variant="outline" class="size-5" />{{ __('Mənbə') }}
                                        </a>
                                    @endif
                                @else
                                    <button type="button" class="row-action bg-rose-600" wire:click="confirmDelete({{ $transaction->id }})" x-on:click="close()">
                                        <flux:icon name="trash" variant="outline" class="size-5" />{{ __('Sil') }}
                                    </button>
                                @endif
                            </div>
                            <div
                                x-ref="content"
                                x-bind:style="{ transform: offset ? 'translateX(' + offset + 'px)' : '' }"
                                x-bind:class="$store.selection.has('tx-{{ $transaction->id }}') && 'is-selected'"
                                class="swipe-content group relative flex items-center gap-4 bg-surface px-4 py-3.5 hover:bg-surface-2"
                            >
                                <x-select-toggle :key="'tx-'.$transaction->id" :amount="$isIncome ? $transaction->amount : -$transaction->amount" :label="$transaction->category?->name ?? ''" />

                                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" style="background: {{ ($transaction->category->color ?? '#a1a1aa') }}1f; color: {{ $transaction->category->color ?? '#a1a1aa' }}">
                                    <flux:icon :name="$transaction->category->icon ?? ($isIncome ? 'arrow-down-left' : 'arrow-up-right')" variant="mini" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate text-sm font-medium text-ink">{{ $transaction->category->name ?? __('Kateqoriyasız') }}</span>
                                        @if ($transaction->isLinked() && $transaction->sourceUrl())
                                            <a href="{{ $transaction->sourceUrl() }}" wire:navigate.hover class="inline-flex shrink-0 items-center gap-1 rounded-full bg-gold-soft px-2 py-0.5 text-[11px] font-medium text-gold hover:underline">
                                                <flux:icon name="link" variant="micro" class="size-3" />{{ $transaction->sourceLabel() }}
                                            </a>
                                        @endif
                                    </div>
                                    @if ($transaction->note)
                                        <div class="truncate text-xs text-faint">{{ $transaction->note }}</div>
                                    @endif
                                </div>

                                <x-money :amount="$transaction->amount" signed :type="$transaction->type" @class(['text-[15px] font-semibold', 'text-positive' => $isIncome, 'text-ink' => ! $isIncome]) />

                                <flux:dropdown align="end" class="max-lg:hidden">
                                    <flux:button size="sm" variant="subtle" icon="ellipsis-horizontal" :aria-label="__('Əməliyyatlar')" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $transaction->id }})">{{ __('Redaktə et') }}</flux:menu.item>
                                        @if ($transaction->isLinked())
                                            @if ($transaction->sourceUrl())
                                                <flux:menu.item icon="link" :href="$transaction->sourceUrl()" wire:navigate.hover>{{ __('Mənbəyə keç') }}</flux:menu.item>
                                            @endif
                                        @else
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $transaction->id }})">{{ __('Sil') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            <flux:pagination :paginator="$this->transactions" />
        </div>
    @endif

    <flux:modal name="delete-transaction" class="sheet-on-mobile w-full max-w-sm">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Əməliyyat silinsin?') }}</flux:heading>
            <flux:text>{{ __('Silinən əməliyyat statistikadan çıxarılacaq.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Sil') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
