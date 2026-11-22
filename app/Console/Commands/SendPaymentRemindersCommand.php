<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PaymentReminders;
use App\Support\Preferences;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('finance:send-reminders {--force : Send now, ignoring each user\'s reminder hour}')]
#[Description('Send the daily push reminders to users whose reminder hour is now (run hourly)')]
class SendPaymentRemindersCommand extends Command
{
    public function handle(PaymentReminders $reminders): int
    {
        $now = CarbonImmutable::now(config('finance.timezone'));
        $today = CarbonImmutable::parse($now->toDateString());
        $sent = 0;

        User::query()->whereHas('pushSubscriptions')->each(function (User $user) use ($reminders, $now, $today, &$sent): void {
            if (! $this->option('force') && (int) Preferences::get('notify_hour', $user) !== $now->hour) {
                return;
            }

            if (! $this->option('force') && ! Cache::add("payment-reminders:{$user->id}:{$today->toDateString()}", true, now()->addDay())) {
                return;
            }

            foreach ($reminders->for($user, $today) as $message) {
                $user->notify($message);
                $sent++;
            }
        });

        $this->components->info("{$sent} reminder(s) queued.");

        return self::SUCCESS;
    }
}
