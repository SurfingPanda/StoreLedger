<?php

namespace App\Console\Commands;

use App\Models\RecurringSchedule;
use App\Services\RecurringBilling;
use Throwable;
use Illuminate\Console\Command;
use App\Services\AutomationMonitor;

class GenerateRecurringRequests extends Command
{
    protected $signature = 'billing:generate-recurring';
    protected $description = 'Create due recurring renewals and SOA requests without duplicating billing cycles';
    public function handle(): int
    {
        return AutomationMonitor::run($this->getName(), fn () => $this->executeAutomation());
    }

    private function executeAutomation(): int
    {
        $count = 0; $failed = 0;
        RecurringSchedule::where('active', true)->chunkById(100, function ($schedules) use (&$count, &$failed) {
            foreach ($schedules as $schedule) {
                try {
                    $count += RecurringBilling::generate($schedule->id);
                    AutomationMonitor::resolve('recurring:'.$schedule->id);
                } catch (Throwable $error) {
                    $failed++;
                    AutomationMonitor::issue('recurring:'.$schedule->id, 'Recurring schedule could not be generated. The next hourly run will retry it.',
                        ['module' => $schedule->module, 'schedule_id' => $schedule->id, 'request_id' => $schedule->source_id]);
                    report($error);
                    $this->error('Schedule '.$schedule->id.' failed. Other schedules will continue.');
                }
            }
        });
        AutomationMonitor::metrics(['created' => $count, 'failed' => $failed]);
        $this->info('Created '.$count.' recurring request(s).');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
