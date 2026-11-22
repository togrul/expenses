<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionType;
use Carbon\CarbonInterface;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property TransactionType $type
 * @property int $amount
 * @property string $currency
 * @property int|null $category_id
 * @property CarbonInterface $occurred_on
 * @property string|null $note
 * @property string|null $source_type
 * @property int|null $source_id
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Category|null $category
 * @property-read DebtPayment|RecurringOccurrence|null $source
 */
#[Fillable(['type', 'amount', 'currency', 'category_id', 'occurred_on', 'note', 'source_type', 'source_id'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => MoneyCast::class,
            'occurred_on' => 'date',
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
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The debt payment or recurring occurrence this transaction was created from.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isLinked(): bool
    {
        return $this->source_type !== null;
    }

    /**
     * Short label describing the linked source, e.g. "Borc: Elvin".
     */
    public function sourceLabel(): ?string
    {
        $source = $this->source;

        return match (true) {
            $source instanceof DebtPayment => __('Borc').': '.$source->debt?->counterparty,
            $source instanceof RecurringOccurrence => __('Daimi').': '.$source->recurringPayment?->name,
            default => null,
        };
    }

    public function sourceUrl(): ?string
    {
        $source = $this->source;

        return match (true) {
            $source instanceof DebtPayment => $source->debt->url(),
            $source instanceof RecurringOccurrence => route('recurring.index'),
            default => null,
        };
    }
}
