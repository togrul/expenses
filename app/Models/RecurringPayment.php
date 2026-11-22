<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\Frequency;
use Carbon\CarbonInterface;
use Database\Factories\RecurringPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $amount
 * @property string $currency
 * @property bool $is_variable
 * @property int|null $category_id
 * @property Frequency $frequency
 * @property int $interval
 * @property int|null $day_of_month
 * @property int|null $day_of_week
 * @property int|null $month_of_year
 * @property CarbonInterface $starts_on
 * @property CarbonInterface|null $ends_on
 * @property int $remind_days_before
 * @property bool $is_active
 * @property string|null $note
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Category|null $category
 */
#[Fillable([
    'name', 'amount', 'currency', 'is_variable', 'category_id', 'frequency', 'interval',
    'day_of_month', 'day_of_week', 'month_of_year', 'starts_on', 'ends_on',
    'remind_days_before', 'is_active', 'note',
])]
class RecurringPayment extends Model
{
    /** @use HasFactory<RecurringPaymentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'is_variable' => 'boolean',
            'frequency' => Frequency::class,
            'interval' => 'integer',
            'day_of_month' => 'integer',
            'day_of_week' => 'integer',
            'month_of_year' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'remind_days_before' => 'integer',
            'is_active' => 'boolean',
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
     * @return HasMany<RecurringOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringOccurrence::class);
    }

    /**
     * @param  Builder<RecurringPayment>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The amount converted to a monthly figure in minor units.
     */
    public function monthlyEquivalent(): int
    {
        return (int) round($this->amount * $this->frequency->perYear() / max(1, $this->interval) / 12);
    }

    /**
     * Human readable schedule, e.g. "Hər ay, 25-i".
     */
    public function scheduleLabel(): string
    {
        $every = $this->interval > 1
            ? __('Hər :n', ['n' => $this->interval]).' '
            : __('Hər').' ';

        return match ($this->frequency) {
            Frequency::Weekly => $every.__('həftə').', '.now()->startOfWeek()->addDays(($this->day_of_week ?? 1) - 1)->translatedFormat('l'),
            Frequency::Monthly => $every.__('ay').', '.__(':day-i', ['day' => $this->day_of_month ?? $this->starts_on->day]),
            Frequency::Yearly => $every.__('il').', '.($this->day_of_month ?? $this->starts_on->day).' '.now()->setMonth($this->month_of_year ?? $this->starts_on->month)->translatedFormat('F'),
        };
    }
}
