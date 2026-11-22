<?php

namespace App\Services;

use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Support\Preferences;
use App\Support\UpcomingItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Merges unpaid debt installments and recurring occurrences into one list.
 */
class UpcomingService
{
    public const array Groups = ['overdue', 'today', 'this_week', 'this_month', 'later', 'undated'];

    /**
     * Unpaid items due up to $until (overdue included), undated installments optionally.
     *
     * @return Collection<int, UpcomingItem>
     */
    public function items(User $user, ?CarbonImmutable $until = null, bool $includeUndated = true): Collection
    {
        $until ??= CarbonImmutable::today()->addDays((int) config('finance.occurrence_horizon_days'));

        $debtPayments = $user->debtPayments()
            ->whereNull('debt_payments.paid_at')
            ->whereNull('debts.closed_at')
            ->where(function (Builder $query) use ($until, $includeUndated): void {
                $query->where('debt_payments.due_on', '<=', $until);

                if ($includeUndated) {
                    $query->orWhereNull('debt_payments.due_on');
                }
            })
            ->with('debt')
            ->get()
            ->map(fn (DebtPayment $payment): UpcomingItem => UpcomingItem::fromDebtPayment($payment));

        $occurrences = $user->recurringOccurrences()
            ->whereNull('recurring_occurrences.paid_at')
            ->whereNull('recurring_occurrences.skipped_at')
            ->where('recurring_occurrences.due_on', '<=', $until)
            ->with('recurringPayment.category')
            ->get()
            ->map(fn (RecurringOccurrence $occurrence): UpcomingItem => UpcomingItem::fromOccurrence($occurrence));

        return $debtPayments->toBase()
            ->merge($occurrences->toBase())
            ->sortBy([
                fn (UpcomingItem $a, UpcomingItem $b): int => ($a->dueOn === null) <=> ($b->dueOn === null),
                fn (UpcomingItem $a, UpcomingItem $b): int => ($a->dueOn?->getTimestamp() ?? 0) <=> ($b->dueOn?->getTimestamp() ?? 0),
                fn (UpcomingItem $a, UpcomingItem $b): int => $a->title <=> $b->title,
            ])
            ->values();
    }

    /**
     * Items grouped as overdue / today / this_week / this_month / later / undated.
     *
     * @return array<string, Collection<int, UpcomingItem>>
     */
    public function grouped(User $user, ?CarbonImmutable $until = null, bool $includeUndated = true): array
    {
        $today = CarbonImmutable::today();
        $weekStartsOn = Preferences::weekStartsOn($user);
        $endOfWeek = $today->startOfWeek($weekStartsOn % 7)->addDays(6);
        $endOfMonth = $today->endOfMonth()->startOfDay();

        $groups = array_map(fn (): Collection => collect(), array_flip(self::Groups));

        foreach ($this->items($user, $until, $includeUndated) as $item) {
            $group = match (true) {
                $item->dueOn === null => 'undated',
                $item->dueOn->lt($today) => 'overdue',
                $item->dueOn->eq($today) => 'today',
                $item->dueOn->lte($endOfWeek) => 'this_week',
                $item->dueOn->lte($endOfMonth) => 'this_month',
                default => 'later',
            };

            $groups[$group]->push($item);
        }

        return $groups;
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'overdue' => __('Gecikmiş'),
            'today' => __('Bu gün'),
            'this_week' => __('Bu həftə'),
            'this_month' => __('Bu ay'),
            'later' => __('Sonra'),
            default => __('Tarixsiz'),
        };
    }
}
