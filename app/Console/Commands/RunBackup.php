<?php

namespace App\Console\Commands;

use App\Services\AutomationMonitor;
use App\Services\EncryptedBackups;
use Illuminate\Console\Command;
use Throwable;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--scheduled : Skip if a verified backup already exists for today}';
    protected $description = 'Create and verify an AES-256 encrypted database and attachment backup, then apply retention';

    public function handle(EncryptedBackups $backups): int
    {
        return AutomationMonitor::run('backup:run', function () use ($backups) {
            try {
                $result = $backups->create((bool) $this->option('scheduled'));
                AutomationMonitor::metrics(['files' => $result['entries'], 'bytes' => $result['bytes']]);
                $this->info(($result['skipped'] ?? false) ? 'A verified backup already exists for today.' : 'Encrypted backup created and every file verified.');
                $this->line('Archive: '.$result['archive']);
                $this->line('Entries: '.$result['entries'].'; bytes: '.$result['bytes']);
                return self::SUCCESS;
            } catch (Throwable $error) {
                report($error);
                AutomationMonitor::issue('command:backup:run', 'Backup failed. Check the recovery key, destination space, dump executable, and application logs. Existing backups were retained.');
                $this->error('Backup failed: '.$error->getMessage());
                return self::FAILURE;
            }
        });
    }
}
