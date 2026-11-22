<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\RecurringOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recurring_payment_id
 * @property CarbonInterface $due_on
 * @property int $amount
 * @property CarbonInterface|null $paid_at
 * @property int|null $paid_amount
 * @property CarbonInterface|null $skipped_at
 * @property int|null $transaction_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read RecurringPayment|null $recurringPayment
 * @property-read Transaction|null $transaction
 */
#[Fillable(['due_on', 'amount', 'paid_at', 'paid_amount', 'skipped_at', 'transaction_id'])]
class RecurringOccurrence extends Model
{
    /** @use HasFactory<RecurringOccurrenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'paid_amount' => MoneyCast::class,
            'due_on' => 'date',
            'paid_at' => 'date',
            'skipped_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecurringPayment, $this>
     */
    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isSkipped(): bool
    {
        return $this->skipped_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && ! $this->isSkipped() && $this->due_on->lt(today());
    }

    public function status(): PaymentStatus
    {
        return match (true) {
            $this->isPaid() => PaymentStatus::Paid,
            $this->isSkipped() => PaymentStatus::Skipped,
            $this->isOverdue() => PaymentStatus::Overdue,
            default => PaymentStatus::Pending,
        };
    }
}
