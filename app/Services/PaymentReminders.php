<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PushMessage;
use App\Support\Money;
use App\Support\Preferences;
use App\Support\UpcomingItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the daily push reminders for a user: due today, due tomorrow, overdue
 * and (on the 1st) last month's summary. Each type can be switched off.
 */
class PaymentReminders
{
    public function __construct(
        private UpcomingService $upcoming,
        private StatsService $stats,
    ) {}

    /**
     * @return list<PushMessage>
     */
    public function for(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $items = $this->upcoming->items($user, $today->addDay(), includeUndated: false);

        $overdue = $items->filter(fn (UpcomingItem $item): bool => $item->dueOn->lt($today));
        $dueToday = $items->filter(fn (UpcomingItem $item): bool => $item->dueOn->eq($today));
        $dueTomorrow = $items->filter(fn (UpcomingItem $item): bool => $item->dueOn->eq($today->addDay()));
        $badge = $overdue->count() + $dueToday->count();
        $upcomingUrl = route('upcoming.index', absolute: false);

        $messages = [];

        if ($dueToday->isNotEmpty() && Preferences::get('notify_today', $user)) {
            $messages[] = new PushMessage(
                title: __('Bu gün ödənilməli: :amount', ['amount' => Money::format((int) $dueToday->sum('amount'))]),
                body: $this->describe($dueToday),
                url: $dueToday->count() === 1 ? $this->relative($dueToday->first()->url) : $upcomingUrl,
                tag: 'due-today',
                badge: $badge,
            );
        }

        if ($dueTomorrow->isNotEmpty() && Preferences::get('notify_tomorrow', $user)) {
            $messages[] = new PushMessage(
                title: __('Sabah ödəniş var: :amount', ['amount' => Money::format((int) $dueTomorrow->sum('amount'))]),
                body: $this->describe($dueTomorrow),
                url: $dueTomorrow->count() === 1 ? $this->relative($dueTomorrow->first()->url) : $upcomingUrl,
                tag: 'due-tomorrow',
                badge: $badge,
            );
        }

        if ($overdue->isNotEmpty() && Preferences::get('notify_overdue', $user)) {
            $messages[] = new PushMessage(
                title: trans_choice('{1} 1 gecikmiş ödəniş|[2,*] :count gecikmiş ödəniş', $overdue->count(), ['count' => $overdue->count()]),
                body: __('Cəmi :amount. :items', ['amount' => Money::format((int) $overdue->sum('amount')), 'items' => $this->describe($overdue)]),
                url: $upcomingUrl,
                tag: 'overdue',
                badge: $badge,
            );
        }

        if ($today->day === 1 && Preferences::get('notify_monthly', $user)) {
            $month = $today->subMonthNoOverflow();
            $totals = $this->stats->monthTotals($user, $month);

            if ($totals['income'] > 0 || $totals['expense'] > 0) {
                $messages[] = new PushMessage(
                    title: __(':month yekunu', ['month' => mb_convert_case($month->translatedFormat('F'), MB_CASE_TITLE)]),
                    body: __('Gəlir :income · Xərc :expense · Qalıq :net', [
                        'income' => Money::format($totals['income']),
                        'expense' => Money::format($totals['expense']),
                        'net' => Money::format($totals['net']),
                    ]),
                    url: route('dashboard', absolute: false),
                    tag: 'monthly-summary',
                    badge: $badge,
                );
            }
        }

        return $messages;
    }

    /**
     * "Access Bank 867,00 ₼, İnternet 30,00 ₼ +2"
     *
     * @param  Collection<int, UpcomingItem>  $items
     */
    private function describe(Collection $items): string
    {
        $listed = $items->take(3)
            ->map(fn (UpcomingItem $item): string => $item->title.' '.Money::format($item->amount))
            ->implode(', ');

        return $items->count() > 3 ? $listed.' +'.($items->count() - 3) : $listed;
    }

    private function relative(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) ?: '/';
    }
}
