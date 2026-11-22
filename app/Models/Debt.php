<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DebtDirection;
use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Enums\InterestMethod;
use App\Enums\LoanScheduleType;
use App\Support\LoanCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property DebtDirection $direction
 * @property DebtKind $kind
 * @property string $counterparty
 * @property string|null $title
 * @property int $total_amount
 * @property int|null $principal
 * @property string|null $interest_rate
 * @property int|null $term_months
 * @property LoanScheduleType|null $schedule_type
 * @property int|null $monthly_payment
 * @property InterestMethod|null $interest_method
 * @property bool $shift_weekends
 * @property string $currency
 * @property CarbonInterface|null $started_on
 * @property CarbonInterface|null $due_on
 * @property string|null $note
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int|string|null $paid_total
 * @property-read Collection<int, DebtPayment> $payments
 */
#[Fillable(['direction', 'kind', 'counterparty', 'title', 'total_amount', 'principal', 'interest_rate', 'term_months', 'schedule_type', 'monthly_payment', 'interest_method', 'shift_weekends', 'currency', 'started_on', 'due_on', 'note', 'closed_at'])]
class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'personal',
        'direction' => 'i_owe',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => DebtDirection::class,
            'kind' => DebtKind::class,
            'total_amount' => MoneyCast::class,
            'principal' => MoneyCast::class,
            'interest_rate' => 'decimal:3',
            'term_months' => 'integer',
            'schedule_type' => LoanScheduleType::class,
            'monthly_payment' => MoneyCast::class,
            'interest_method' => InterestMethod::class,
            'shift_weekends' => 'boolean',
            'started_on' => 'date',
            'due_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DebtPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    /**
     * Installments ordered by due date, undated ones last.
     *
     * @return Collection<int, DebtPayment>
     */
    public function sortedPayments(): Collection
    {
        return $this->payments
            ->sortBy([
                fn (DebtPayment $a, DebtPayment $b): int => ($a->due_on === null) <=> ($b->due_on === null),
                fn (DebtPayment $a, DebtPayment $b): int => ($a->due_on?->getTimestamp() ?? 0) <=> ($b->due_on?->getTimestamp() ?? 0),
                fn (DebtPayment $a, DebtPayment $b): int => $a->sort_order <=> $b->sort_order,
                fn (DebtPayment $a, DebtPayment $b): int => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * @param  Builder<Debt>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * @param  Builder<Debt>  $query
     */
    #[Scope]
    protected function withPaidTotal(Builder $query): void
    {
        $query->withSum(['payments as paid_total' => fn (Builder $payments) => $payments->whereNotNull('paid_at')], 'paid_amount');
    }

    public function isLoan(): bool
    {
        return $this->kind === DebtKind::Loan;
    }

    /**
     * Principal / interest breakdown of a loan, based on the paid installments.
     *
     * payoff = principal left + interest accrued (daily) since the last paid due date —
     * this is what banks usually show as "qalıq" (the amount to close the loan today).
     *
     * @return array{principal: int, interest_total: int, principal_paid: int, interest_paid: int, principal_left: int, interest_left: int, paid_count: int, count: int, next_payment: int|null, payoff: int, accrued: int, remaining_payments: int}
     */
    public function loanSummary(): array
    {
        $payments = $this->payments;
        $paid = $payments->filter(fn (DebtPayment $payment): bool => $payment->isPaid());
        $principal = (int) $this->principal;
        $interestTotal = (int) $payments->sum('interest_amount');
        $principalPaid = (int) $paid->sum('principal_amount');
        $interestPaid = (int) $paid->sum('interest_amount');
        $principalLeft = max(0, $principal - $principalPaid);
        $accrued = $this->accruedInterest($principalLeft);

        return [
            'principal' => $principal,
            'interest_total' => $interestTotal,
            'principal_paid' => $principalPaid,
            'interest_paid' => $interestPaid,
            'principal_left' => $principalLeft,
            'interest_left' => max(0, $interestTotal - $interestPaid),
            'paid_count' => $paid->whereNull('split_from_id')->count(),
            'count' => (int) ($this->term_months ?? $payments->count()),
            'next_payment' => $this->nextPayment()?->amount,
            'payoff' => $principalLeft + $accrued,
            'accrued' => $accrued,
            'remaining_payments' => $this->remaining(),
        ];
    }

    /**
     * Interest accrued since the last paid installment's due date (or the start date).
     */
    private function accruedInterest(int $principalLeft): int
    {
        $today = CarbonImmutable::today();
        $lastPaid = $this->payments->filter(fn (DebtPayment $payment): bool => $payment->isPaid() && $payment->due_on !== null)->max('due_on');
        $since = $lastPaid ?? $this->started_on ?? $this->sortedPayments()->first()?->due_on?->subMonthNoOverflow();

        if ($since === null || $principalLeft === 0 || $this->closed_at !== null) {
            return 0;
        }

        $since = CarbonImmutable::parse($since);

        return $since->gte($today) ? 0 : LoanCalculator::accruedInterest($principalLeft, (float) $this->interest_rate, $since, $today, $this->interest_method?->dayBasis() ?? 365);
    }

    /**
     * Loans live under /loans, personal debts under /debts.
     */
    public function url(string $action = 'show'): string
    {
        return route(($this->isLoan() ? 'loans.' : 'debts.').$action, $this);
    }

    public static function indexUrl(DebtKind|string|null $kind): string
    {
        return route(($kind === DebtKind::Loan || $kind === DebtKind::Loan->value) ? 'loans.index' : 'debts.index');
    }

    public function displayName(): string
    {
        return $this->title ? "{$this->counterparty} — {$this->title}" : $this->counterparty;
    }

    public function paidTotal(): int
    {
        if (array_key_exists('paid_total', $this->attributes)) {
            return (int) $this->attributes['paid_total'];
        }

        if ($this->relationLoaded('payments')) {
            return (int) $this->payments->whereNotNull('paid_at')->sum('paid_amount');
        }

        return (int) $this->payments()->whereNotNull('paid_at')->sum('paid_amount');
    }

    public function remaining(): int
    {
        return max(0, $this->total_amount - $this->paidTotal());
    }

    public function progressPercent(): int
    {
        if ($this->total_amount === 0) {
            return 100;
        }

        return (int) min(100, floor($this->paidTotal() * 100 / $this->total_amount));
    }

    /**
     * Difference between the debt total and the planned schedule
     * (paid amounts + unpaid installment amounts). Positive = under-planned.
     */
    public function unplannedAmount(): int
    {
        $payments = $this->payments;

        $planned = $payments->sum(fn (DebtPayment $payment): int => $payment->isPaid() ? (int) $payment->paid_amount : $payment->amount);

        return $this->total_amount - (int) $planned;
    }

    public function nextPayment(): ?DebtPayment
    {
        return $this->sortedPayments()->first(fn (DebtPayment $payment): bool => ! $payment->isPaid());
    }

    public function isOverdue(): bool
    {
        return $this->closed_at === null
            && $this->payments->contains(fn (DebtPayment $payment): bool => $payment->isOverdue());
    }

    public function status(): DebtStatus
    {
        return match (true) {
            $this->closed_at !== null || $this->remaining() === 0 => DebtStatus::Paid,
            $this->isOverdue() => DebtStatus::Overdue,
            default => DebtStatus::Active,
        };
    }

    /**
     * Keep closed_at in sync with the remaining balance.
     */
    public function syncClosedState(): void
    {
        unset($this->attributes['paid_total']);
        $this->unsetRelation('payments');

        $this->closed_at = $this->remaining() === 0 ? ($this->closed_at ?? now()) : null;
        $this->save();
    }
}
