<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:generate-occurrences')->dailyAt('00:10');
Schedule::command('finance:backup')->dailyAt('03:00');
Schedule::command('finance:send-reminders')->hourly()->withoutOverlapping();
