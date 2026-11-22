<?php

namespace App\Console\Commands;

use App\Actions\Recurring\GenerateOccurrences;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:generate-occurrences')]
#[Description('Generate upcoming occurrences for all active recurring payments (idempotent)')]
class GenerateRecurringOccurrencesCommand extends Command
{
    public function handle(GenerateOccurrences $generateOccurrences): int
    {
        $created = $generateOccurrences->handle();

        $this->components->info("{$created} occurrence(s) created.");

        return self::SUCCESS;
    }
}
