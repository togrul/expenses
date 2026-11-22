<?php

namespace App\Services;

use App\Enums\DebtDirection;
use App\Enums\DebtKind;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\RecurringPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated numbers for the dashboard. All amounts are minor units.
 */
class StatsService
{
    /**
     * @return array{income: int, expense: int, net: int}
     */
    public function totals(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sums = $user->transactions()
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('type')
            ->selectRaw('type, sum(amount) as total')
            ->pluck('total', 'type');

        $income = (int) ($sums[TransactionType::Income->value] ?? 0);
        $expense = (int) ($sums[TransactionType::Expense->value] ?? 0);

        return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
    }

    /**
     * @return array{income: int, expense: int, net: int}
     */
    public function monthTotals(User $user, CarbonImmutable $month): array
    {
        return $this->totals($user, $month->startOfMonth(), $month->endOfMonth());
    }

    /**
     * Percentage change from $previous to $current, null when there is no base.
     */
    public static function change(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round(($current - $previous) * 100 / abs($previous), 1);
    }

    /**
     * Income vs expense per month for the last $months months (oldest first).
     *
     * Grouped by day in SQL and folded into months in PHP so the query stays portable.
     *
     * @return list<array{month: string, label: string, income: int, expense: int}>
     */
    public function monthlySeries(User $user, int $months = 6, ?CarbonImmutable $until = null): array
    {
        $until = ($until ?? CarbonImmutable::today())->endOfMonth();
        $from = $until->startOfMonth()->subMonthsNoOverflow($months - 1);

        $income = [];
        $expense = [];

        $rows = $user->transactions()
            ->whereBetween('occurred_on', [$from->toDateString(), $until->toDateString()])
            ->groupBy('occurred_on', 'type')
            ->selectRaw('occurred_on, type, sum(amount) as total')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse($row->occurred_on)->format('Y-m');

            if ($row->type === TransactionType::Income->value) {
                $income[$key] = ($income[$key] ?? 0) + (int) $row->total;
            } else {
                $expense[$key] = ($expense[$key] ?? 0) + (int) $row->total;
            }
        }

        $series = [];

        for ($month = $from; $month->lte($until); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');

            $series[] = [
                'month' => $key,
                'label' => $month->translatedFormat('M y'),
                'income' => $income[$key] ?? 0,
                'expense' => $expense[$key] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * Expenses of a month grouped by category, largest first.
     *
     * @return list<array{name: string, color: string, total: int}>
     */
    public function categoryBreakdown(User $user, CarbonImmutable $month): array
    {
        return array_values($user->transactions()
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.type', TransactionType::Expense)
            ->whereBetween('transactions.occurred_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->groupBy('categories.id', 'categories.name', 'categories.color')
            ->selectRaw('categories.name, categories.color, sum(transactions.amount) as total')
            ->orderByDesc('total')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) ($row->name ?? __('Kateqoriyasız')),
                'color' => (string) ($row->color ?? '#a1a1aa'),
                'total' => (int) $row->total,
            ])
            ->all());
    }

    /**
     * Totals over open debts I owe (optionally only personal debts or only loans).
     *
     * @return array{total: int, paid: int, remaining: int, percent: int, count: int}
     */
    public function debtSummary(User $user, ?DebtKind $kind = null): array
    {
        $debts = $user->debts()
            ->open()
            ->where('direction', DebtDirection::IOwe)
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->withPaidTotal()
            ->get();

        $total = (int) $debts->sum('total_amount');
        $paid = (int) $debts->sum(fn (Debt $debt): int => $debt->paidTotal());

        return [
            'total' => $total,
            'paid' => $paid,
            'remaining' => (int) $debts->sum(fn (Debt $debt): int => $debt->remaining()),
            'percent' => $total > 0 ? (int) floor($paid * 100 / $total) : 0,
            'count' => $debts->count(),
        ];
    }

    /**
     * Open bank loans: principal still owed and this month's installments.
     *
     * @return array{principal_left: int, principal: int, percent: int, monthly: int, count: int}
     */
    public function loanSummary(User $user): array
    {
        $loans = $user->debts()
            ->open()
            ->where('kind', DebtKind::Loan)
            ->with('payments')
            ->get();

        $summaries = $loans->map(fn (Debt $debt): array => $debt->loanSummary());
        $principal = (int) $summaries->sum('principal');
        $principalLeft = (int) $summaries->sum('principal_left');

        return [
            'principal_left' => $principalLeft,
            'principal' => $principal,
            'percent' => $principal > 0 ? (int) floor(($principal - $principalLeft) * 100 / $principal) : 0,
            'monthly' => (int) $summaries->sum('next_payment'),
            'count' => $loans->count(),
        ];
    }

    /**
     * Unpaid installments and occurrences whose due date has passed.
     *
     * @return array{count: int, amount: int}
     */
    public function overdue(User $user): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $debt = $user->debtPayments()
            ->whereNull('debts.closed_at')
            ->whereNull('debt_payments.paid_at')
            ->where('debt_payments.due_on', '<', $today)
            ->toBase()
            ->selectRaw('count(*) as count, coalesce(sum(debt_payments.amount), 0) as amount')
            ->first();

        $recurring = $user->recurringOccurrences()
            ->whereNull('recurring_occurrences.paid_at')
            ->whereNull('recurring_occurrences.skipped_at')
            ->where('recurring_occurrences.due_on', '<', $today)
            ->toBase()
            ->selectRaw('count(*) as count, coalesce(sum(recurring_occurrences.amount), 0) as amount')
            ->first();

        return [
            'count' => (int) ($debt->count ?? 0) + (int) ($recurring->count ?? 0),
            'amount' => (int) ($debt->amount ?? 0) + (int) ($recurring->amount ?? 0),
        ];
    }

    /**
     * Installments + occurrences due in $month: what is paid and what is left.
     *
     * @return array{paid: int, paid_count: int, left: int, left_count: int}
     */
    public function dueInMonth(User $user, CarbonImmutable $month): array
    {
        $range = [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()];

        $debt = $user->debtPayments()
            ->whereBetween('debt_payments.due_on', $range)
            ->toBase()
            ->selectRaw('
                coalesce(sum(case when debt_payments.paid_at is not null then debt_payments.paid_amount else 0 end), 0) as paid,
                sum(case when debt_payments.paid_at is not null then 1 else 0 end) as paid_count,
                coalesce(sum(case when debt_payments.paid_at is null then debt_payments.amount else 0 end), 0) as left_amount,
                sum(case when debt_payments.paid_at is null then 1 else 0 end) as left_count
            ')
            ->first();

        $recurring = $user->recurringOccurrences()
            ->whereNull('recurring_occurrences.skipped_at')
            ->whereBetween('recurring_occurrences.due_on', $range)
            ->toBase()
            ->selectRaw('
                coalesce(sum(case when recurring_occurrences.paid_at is not null then recurring_occurrences.paid_amount else 0 end), 0) as paid,
                sum(case when recurring_occurrences.paid_at is not null then 1 else 0 end) as paid_count,
                coalesce(sum(case when recurring_occurrences.paid_at is null then recurring_occurrences.amount else 0 end), 0) as left_amount,
                sum(case when recurring_occurrences.paid_at is null then 1 else 0 end) as left_count
            ')
            ->first();

        return [
            'paid' => (int) ($debt->paid ?? 0) + (int) ($recurring->paid ?? 0),
            'paid_count' => (int) ($debt->paid_count ?? 0) + (int) ($recurring->paid_count ?? 0),
            'left' => (int) ($debt->left_amount ?? 0) + (int) ($recurring->left_amount ?? 0),
            'left_count' => (int) ($debt->left_count ?? 0) + (int) ($recurring->left_count ?? 0),
        ];
    }

    /**
     * Sum of all active recurring payments converted to a monthly amount,
     * plus this month's bank loan installments.
     */
    public function fixedMonthlyCommitments(User $user): int
    {
        $today = CarbonImmutable::today();

        $recurring = (int) $user->recurringPayments()
            ->active()
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->get()
            ->sum(fn (RecurringPayment $payment): int => $payment->monthlyEquivalent());

        $loans = (int) $user->debtPayments()
            ->where('debts.kind', DebtKind::Loan)
            ->whereNull('debts.closed_at')
            ->whereBetween('debt_payments.due_on', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
            ->sum('debt_payments.amount');

        return $recurring + $loans;
    }

    /**
     * Outstanding balance of debts I owe at the end of each of the last $months months.
     *
     * @return list<array{label: string, remaining: int}>
     */
    public function debtBalanceHistory(User $user, int $months = 12): array
    {
        $debts = $user->debts()
            ->where('direction', DebtDirection::IOwe)
            ->get(['id', 'total_amount', 'started_on', 'created_at']);

        if ($debts->isEmpty()) {
            return [];
        }

        $payments = DB::table('debt_payments')
            ->whereIn('debt_id', $debts->pluck('id'))
            ->whereNotNull('paid_at')
            ->get(['paid_at', 'paid_amount']);

        $end = CarbonImmutable::today()->endOfMonth();
        $history = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = $end->subMonthsNoOverflow($i)->endOfMonth();

            $owed = $debts
                ->filter(fn (Debt $debt): bool => CarbonImmutable::parse($debt->started_on ?? $debt->created_at)->lte($monthEnd))
                ->sum('total_amount');

            $paid = $payments
                ->filter(fn (object $payment): bool => CarbonImmutable::parse($payment->paid_at)->lte($monthEnd))
                ->sum('paid_amount');

            $history[] = [
                'label' => $monthEnd->translatedFormat('M y'),
                'remaining' => max(0, (int) $owed - (int) $paid),
            ];
        }

        return $history;
    }
}
