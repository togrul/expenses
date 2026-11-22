<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonInterface;
use Database\Factories\DebtPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property int $id
 * @property int $debt_id
 * @property int $amount
 * @property int|null $principal_amount
 * @property int|null $interest_amount
 * @property int|null $installment_number
 * @property CarbonInterface|null $due_on
 * @property CarbonInterface|null $paid_at
 * @property int|null $paid_amount
 * @property int|null $transaction_id
 * @property int|null $split_from_id
 * @property string|null $note
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Debt|null $debt
 * @property-read Transaction|null $transaction
 */
#[Fillable(['amount', 'principal_amount', 'interest_amount', 'installment_number', 'due_on', 'paid_at', 'paid_amount', 'transaction_id', 'split_from_id', 'note', 'sort_order'])]
class DebtPayment extends Model
{
    /** @use HasFactory<DebtPaymentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'principal_amount' => MoneyCast::class,
            'interest_amount' => MoneyCast::class,
            'installment_number' => 'integer',
            'paid_amount' => MoneyCast::class,
            'due_on' => 'date',
            'paid_at' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return MorphOne<Transaction, $this>
     */
    public function sourcedTransaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'source');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_on !== null && $this->due_on->lt(today());
    }
}
