<?php

namespace App\Console\Commands;

use App\Models\RecurringSchedule;
use App\Services\RecurringBilling;
use Illuminate\Console\Command;

class GenerateRecurringRequests extends Command
{
    protected $signature = 'billing:generate-recurring';
    protected $description = 'Create due recurring renewals and SOA requests without duplicating billing cycles';
    public function handle(): int
    {
        $count = 0;
        RecurringSchedule::where('active', true)->chunkById(100, function ($schedules) use (&$count) {
            foreach ($schedules as $schedule) $count += RecurringBilling::generate($schedule->id);
        });
        $this->info('Created '.$count.' recurring request(s).');
        return self::SUCCESS;
    }
}
