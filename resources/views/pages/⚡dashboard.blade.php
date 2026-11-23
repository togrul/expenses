<?php

use App\Actions\Recurring\GenerateOccurrences;
use App\Enums\DebtDirection;
use App\Enums\DebtKind;
use App\Models\Debt;
use App\Models\Transaction;
use App\Services\StatsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Panel')] class extends Component {
    public int $months = 6;

    public function mount(GenerateOccurrences $generateOccurrences): void
    {
        $generateOccurrences->ensureForUser(Auth::user());
    }

    #[On('transaction-saved')]
    #[On('payment-updated')]
    public function refreshStats(): void
    {
        unset($this->stats, $this->charts, $this->debts, $this->recentTransactions);
    }

    public function updatedMonths(): void
    {
        $this->months = in_array((int) $this->months, [6, 12], true) ? (int) $this->months : 6;
        unset($this->charts);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function stats(): array
    {
        $stats = app(StatsService::class);
        $user = Auth::user();
        $month = CarbonImmutable::today();

        $current = $stats->monthTotals($user, $month);
        $previous = $stats->monthTotals($user, $month->subMonthNoOverflow());

        return [
            'current' => $current,
            'previous' => $previous,
            'debt' => $stats->debtSummary($user, DebtKind::Personal),
            'loan' => $stats->loanSummary($user),
            'due' => $stats->dueInMonth($user, $month),
            'overdue' => $stats->overdue($user),
            'fixed' => $stats->fixedMonthlyCommitments($user),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function charts(): array
    {
        $stats = app(StatsService::class);
        $user = Auth::user();

        $series = $stats->monthlySeries($user, $this->months);
        $categories = $stats->categoryBreakdown($user, CarbonImmutable::today());
        $history = $stats->debtBalanceHistory($user, 12);

        $toMajor = fn (int $minor): float => round($minor / 100, 2);

        return [
            'cashflow' => [
                'chart' => ['type' => 'bar'],
                'series' => [
                    ['name' => __('Gəlir'), 'data' => array_map(fn ($row) => $toMajor($row['income']), $series)],
                    ['name' => __('Xərc'), 'data' => array_map(fn ($row) => $toMajor($row['expense']), $series)],
                ],
                'colors' => ['#34d399', '#d9b877'],
                'xaxis' => ['categories' => array_column($series, 'label'), 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
                'plotOptions' => ['bar' => ['columnWidth' => '48%', 'borderRadius' => 6, 'borderRadiusApplication' => 'end']],
                'legend' => ['position' => 'top', 'horizontalAlign' => 'right'],
                'states' => ['hover' => ['filter' => ['type' => 'lighten', 'value' => 0.05]]],
            ],
            'categories' => [
                'chart' => ['type' => 'donut'],
                'series' => array_map(fn ($row) => $toMajor($row['total']), $categories),
                'labels' => array_column($categories, 'name'),
                'colors' => array_column($categories, 'color'),
                'legend' => ['show' => false],
                'stroke' => ['width' => 0],
                'plotOptions' => ['pie' => ['donut' => ['size' => '74%', 'labels' => ['show' => true, 'name' => ['offsetY' => 18], 'value' => ['offsetY' => -12, 'fontSize' => '22px', 'fontWeight' => 600], 'total' => ['show' => true, 'label' => __('Cəmi'), 'fontSize' => '12px']]]]],
            ],
            'debtHistory' => [
                'chart' => ['type' => 'area'],
                'series' => [['name' => __('Qalıq borc'), 'data' => array_map(fn ($row) => $toMajor($row['remaining']), $history)]],
                'xaxis' => ['categories' => array_column($history, 'label')],
                'colors' => ['#d9b877'],
                'stroke' => ['curve' => 'smooth', 'width' => 2.5],
                'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.3, 'opacityTo' => 0]],
                'markers' => ['size' => 0, 'hover' => ['size' => 5]],
            ],
            'categoryList' => array_slice($categories, 0, 5),
            'hasCategories' => $categories !== [],
            'hasHistory' => $history !== [],
        ];
    }

    /**
     * @return Collection<int, Debt>
     */
    #[Computed]
    public function debts(): Collection
    {
        return Auth::user()->debts()
            ->open()
            ->where('direction', DebtDirection::IOwe)
            ->withPaidTotal()
            ->with('payments')
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function recentTransactions(): Collection
    {
        return Auth::user()->transactions()
            ->with('category')
            ->latest('occurred_on')
            ->latest('id')
            ->limit(5)
            ->get();
    }
}; ?>

@php($s = $this->stats)
@php($categoryTotal = array_sum(array_column($this->charts['categoryList'], 'total')) ?: 1)

<div class="space-y-6">
    <x-page-header :eyebrow="ucfirst(today()->translatedFormat('l, j F'))" :heading="__('Xoş gəldin, :name', ['name' => str(auth()->user()->name)->before(' ')])">
        <x-slot name="actions">
            <flux:button icon="plus" variant="primary" x-on:click="Livewire.dispatch('open-transaction-modal')">{{ __('Əməliyyat') }}</flux:button>
        </x-slot>
    </x-page-header>

    {{-- Hero --}}
    <div class="grid gap-5 xl:grid-cols-3">
        <div class="card gold-glow relative overflow-hidden p-6 sm:p-8 xl:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <div class="eyebrow">{{ __('Bu ay qalan') }} · {{ ucfirst(today()->translatedFormat('F')) }}</div>
                    <x-money :amount="$s['current']['net']" @class(['mt-3 block text-4xl font-semibold sm:text-5xl', 'text-ink' => $s['current']['net'] >= 0, 'text-negative' => $s['current']['net'] < 0]) />
                    <div class="mt-2 text-xs text-muted">{{ __('Keçən ay') }}: <x-money :amount="$s['previous']['net']" /></div>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:min-w-80">
                    <div class="rounded-2xl border border-line bg-surface/70 p-4 backdrop-blur">
                        <div class="flex items-center gap-2 text-xs text-muted"><span class="size-2 rounded-full bg-positive"></span>{{ __('Gəlir') }}</div>
                        <x-money :amount="$s['current']['income']" class="mt-2 block text-lg font-semibold text-ink" />
                        <div class="mt-1.5 text-[11px] text-faint"><x-change-badge :change="\App\Services\StatsService::change($s['current']['income'], $s['previous']['income'])" /></div>
                    </div>
                    <div class="rounded-2xl border border-line bg-surface/70 p-4 backdrop-blur">
                        <div class="flex items-center gap-2 text-xs text-muted"><span class="size-2 rounded-full bg-gold"></span>{{ __('Xərc') }}</div>
                        <x-money :amount="$s['current']['expense']" class="mt-2 block text-lg font-semibold text-ink" />
                        <div class="mt-1.5 text-[11px] text-faint"><x-change-badge :change="\App\Services\StatsService::change($s['current']['expense'], $s['previous']['expense'])" inverse /></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card flex flex-col divide-y divide-line">
            <a href="{{ route('debts.index') }}" wire:navigate.hover class="group flex-1 p-6 transition-colors hover:bg-surface-2/50">
                <div class="flex items-center justify-between">
                    <span class="eyebrow">{{ __('Şəxsi borclar') }}</span>
                    <span class="flex size-8 items-center justify-center rounded-lg bg-gold-soft text-gold"><flux:icon name="banknotes" variant="micro" /></span>
                </div>
                <x-money :amount="$s['debt']['remaining']" class="mt-2 block text-2xl font-semibold text-ink" />
                <div class="mt-3 flex items-center gap-3">
                    <x-meter :value="$s['debt']['percent']" />
                    <span class="shrink-0 text-[11px] text-muted num">{{ $s['debt']['percent'] }}%</span>
                </div>
            </a>
            <a href="{{ route('loans.index') }}" wire:navigate.hover class="group flex-1 p-6 transition-colors hover:bg-surface-2/50">
                <div class="flex items-center justify-between">
                    <span class="eyebrow">{{ __('Kreditlər — əsas borc') }}</span>
                    <span class="flex size-8 items-center justify-center rounded-lg bg-gold-soft text-gold"><flux:icon name="landmark" variant="micro" /></span>
                </div>
                <x-money :amount="$s['loan']['principal_left']" class="mt-2 block text-2xl font-semibold text-ink" />
                <div class="mt-3 flex items-center justify-between text-[11px] text-muted">
                    <span>{{ __('aylıq') }} <x-money :amount="$s['loan']['monthly']" class="font-semibold text-gold" /></span>
                    <span class="num">{{ $s['loan']['percent'] }}% {{ __('ödənilib') }}</span>
                </div>
            </a>
        </div>
    </div>

    <div class="grid gap-5 sm:grid-cols-3">
        <x-stat-card :label="__('Bu ay ödənilməli')" icon="calendar-days" :href="route('upcoming.index')">
            <x-money :amount="$s['due']['left']" />
            <x-slot name="footer">
                {{ trans_choice(':count ödəniş qalıb', $s['due']['left_count'], ['count' => $s['due']['left_count']]) }} ·
                <span class="text-positive"><x-money :amount="$s['due']['paid']" /> {{ __('ödənilib') }}</span>
            </x-slot>
        </x-stat-card>

        <x-stat-card :label="__('Gecikmiş')" icon="exclamation-triangle" :tone="$s['overdue']['count'] > 0 ? 'negative' : 'positive'" :href="route('upcoming.index')">
            <span @class(['text-negative' => $s['overdue']['count'] > 0])><x-money :amount="$s['overdue']['amount']" /></span>
            <x-slot name="footer">
                {{ $s['overdue']['count'] > 0 ? trans_choice(':count ödəniş gecikir', $s['overdue']['count'], ['count' => $s['overdue']['count']]) : __('Hər şey vaxtında ödənilib') }}
            </x-slot>
        </x-stat-card>

        <x-stat-card :label="__('Aylıq sabit öhdəlik')" icon="arrow-path" :href="route('recurring.index')">
            <x-money :amount="$s['fixed']" />
            <x-slot name="footer">{{ __('Aktiv daimi ödənişlərin aylıq cəmi') }}</x-slot>
        </x-stat-card>
    </div>

    {{-- Charts --}}
    <div class="grid gap-5 xl:grid-cols-3">
        <div class="card p-6 xl:col-span-2">
            <div class="mb-2 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('Gəlir və xərc') }}</h2>
                    <p class="text-xs text-faint">{{ __('Son :n ay', ['n' => $months]) }}</p>
                </div>
                <x-segmented model="months" :value="$months" :options="[6 => __('6 ay'), 12 => __('12 ay')]" />
            </div>
            <x-chart :options="$this->charts['cashflow']" :height="300" />
        </div>

        <div class="card p-6">
            <h2 class="text-base font-semibold text-ink">{{ __('Bu ay xərclər') }}</h2>
            <p class="text-xs text-faint">{{ __('Kateqoriyalar üzrə') }}</p>
            @if ($this->charts['hasCategories'])
                <x-chart :options="$this->charts['categories']" :height="220" class="-mx-2 mt-2" />
                <div class="mt-4 space-y-3">
                    @foreach ($this->charts['categoryList'] as $row)
                        <div wire:key="cat-row-{{ $loop->index }}">
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <span class="flex items-center gap-2 text-muted"><span class="size-2 rounded-full" style="background: {{ $row['color'] }}"></span>{{ $row['name'] }}</span>
                                <x-money :amount="$row['total']" class="font-medium text-ink" />
                            </div>
                            <div class="h-1 overflow-hidden rounded-full bg-line"><div class="h-full rounded-full" style="width: {{ round($row['total'] * 100 / $categoryTotal) }}%; background: {{ $row['color'] }}"></div></div>
                        </div>
                    @endforeach
                </div>
            @else
                <x-empty-state icon="chart-pie" :heading="__('Bu ay xərc yoxdur')" class="mt-4 border-none" />
            @endif
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <div class="card p-6">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('Növbəti 7 gün') }}</h2>
                    <p class="text-xs text-faint">{{ __('Checkbox — ödə, kvadrat — cəmlə') }}</p>
                </div>
                <flux:link :href="route('upcoming.index')" wire:navigate.hover class="text-sm !text-gold">{{ __('Hamısı') }} →</flux:link>
            </div>
            <livewire:upcoming-list :days="7" :include-undated="false" />
        </div>

        <div class="card p-6">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold text-ink">{{ __('Son əməliyyatlar') }}</h2>
                <flux:link :href="route('transactions.index')" wire:navigate.hover class="text-sm !text-gold">{{ __('Hamısı') }} →</flux:link>
            </div>

            @forelse ($this->recentTransactions as $transaction)
                @php($isIncome = $transaction->type === \App\Enums\TransactionType::Income)
                <div wire:key="recent-{{ $transaction->id }}" class="flex items-center gap-3.5 border-b border-line py-3 last:border-0">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" style="background: {{ $transaction->category->color ?? '#a1a1aa' }}1f; color: {{ $transaction->category->color ?? '#a1a1aa' }}">
                        <flux:icon :name="$transaction->category->icon ?? ($isIncome ? 'arrow-down-left' : 'arrow-up-right')" variant="mini" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-ink">{{ $transaction->category->name ?? __('Kateqoriyasız') }}</div>
                        <div class="truncate text-xs text-faint">{{ \App\Support\Preferences::formatDate($transaction->occurred_on) }}@if ($transaction->note) · {{ $transaction->note }}@endif</div>
                    </div>
                    <x-money :amount="$transaction->amount" signed :type="$transaction->type" @class(['text-sm font-semibold', 'text-positive' => $isIncome, 'text-ink' => ! $isIncome]) />
                </div>
            @empty
                <x-empty-state icon="arrows-right-left" :heading="__('Hələ əməliyyat yoxdur')" class="border-none">
                    <flux:button size="sm" icon="plus" variant="primary" x-on:click="Livewire.dispatch('open-transaction-modal')">{{ __('Əməliyyat əlavə et') }}</flux:button>
                </x-empty-state>
            @endforelse
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <div class="card p-6">
            <h2 class="text-base font-semibold text-ink">{{ __('Borc qalığı') }}</h2>
            <p class="text-xs text-faint">{{ __('Son 12 ay') }}</p>
            @if ($this->charts['hasHistory'])
                <x-chart :options="$this->charts['debtHistory']" :height="240" class="mt-2" />
            @else
                <x-empty-state icon="chart-bar" :heading="__('Borc məlumatı yoxdur')" class="mt-4 border-none" />
            @endif
        </div>

        <div class="card p-6">
            @foreach ([['personal', __('Şəxsi borclar'), 'debts.index'], ['loan', __('Kreditlər'), 'loans.index']] as [$kindValue, $kindLabel, $kindRoute])
                @php($group = $this->debts->filter(fn ($d) => $d->kind->value === $kindValue))
                <div wire:key="dash-group-{{ $kindValue }}" @class(['mt-7' => ! $loop->first])>
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-base font-semibold text-ink">{{ $kindLabel }}</h2>
                        <flux:link :href="route($kindRoute)" wire:navigate.hover class="text-sm !text-gold">{{ __('Hamısı') }} →</flux:link>
                    </div>
                    <div class="space-y-5">
                        @forelse ($group as $debt)
                            @php($amountLeft = $debt->isLoan() ? $debt->loanSummary()['principal_left'] : $debt->remaining())
                            <a href="{{ $debt->url() }}" wire:navigate.hover wire:key="dash-debt-{{ $debt->id }}" class="group flex items-center gap-3.5">
                                <x-initials :name="$debt->counterparty" />
                                <div class="min-w-0 flex-1">
                                    <div class="mb-2 flex items-baseline justify-between gap-2 text-sm">
                                        <span class="truncate font-medium text-ink group-hover:text-gold">{{ $debt->displayName() }}</span>
                                        <x-money :amount="$amountLeft" class="shrink-0 text-xs font-semibold text-muted" />
                                    </div>
                                    <x-meter :value="$debt->progressPercent()" />
                                </div>
                            </a>
                        @empty
                            <p class="text-sm text-faint">{{ $kindValue === 'loan' ? __('Aktiv kredit yoxdur') : __('Aktiv borc yoxdur') }}</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
