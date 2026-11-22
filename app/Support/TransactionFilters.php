<?php

namespace App\Support;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the transaction list filters; shared by the list page and the CSV export.
 */
final readonly class TransactionFilters
{
    public function __construct(
        public ?string $month = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $type = null,
        public ?int $categoryId = null,
        public ?string $search = null,
    ) {}

    /**
     * The effective [from, to] date range: explicit range wins over the month.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function range(): array
    {
        if ($this->from || $this->to) {
            return [
                $this->from ? CarbonImmutable::parse($this->from) : null,
                $this->to ? CarbonImmutable::parse($this->to) : null,
            ];
        }

        if ($this->month && preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $month = CarbonImmutable::createFromFormat('!Y-m', $this->month);

            return [$month->startOfMonth(), $month->endOfMonth()];
        }

        return [null, null];
    }

    /**
     * @return Builder<Transaction>
     */
    public function apply(User $user): Builder
    {
        [$from, $to] = $this->range();

        return $user->transactions()->getQuery()
            ->when($from, fn (Builder $query) => $query->where('occurred_on', '>=', $from->toDateString()))
            ->when($to, fn (Builder $query) => $query->where('occurred_on', '<=', $to->toDateString()))
            ->when($this->type, fn (Builder $query) => $query->where('type', $this->type))
            ->when($this->categoryId, fn (Builder $query) => $query->where('category_id', $this->categoryId))
            ->when($this->search, function (Builder $query): void {
                $term = '%'.addcslashes((string) $this->search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->whereLike('note', $term)
                    ->orWhereHas('category', fn (Builder $category) => $category->whereLike('name', $term)));
            });
    }
}
