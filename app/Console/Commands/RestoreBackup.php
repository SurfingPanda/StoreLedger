<?php

namespace App\Console\Commands;

use App\Services\EncryptedBackups;
use Illuminate\Console\Command;
use Throwable;

class RestoreBackup extends Command
{
    protected $signature = 'backup:restore {archive : Path to the encrypted ZIP archive} {--to= : New private staging directory; must not exist}';
    protected $description = 'Restore verified files into a new directory; never overwrite or import into the live database';

    public function handle(EncryptedBackups $backups): int
    {
        if (!$this->option('to')) { $this->error('Specify a new private destination with --to.'); return self::FAILURE; }
        try {
            $result = $backups->restore($this->argument('archive'), $this->option('to'));
            $this->info('Restored and verified '.$result['entries'].' files into '.$result['directory']);
            $this->line('Database snapshot: '.$result['database']);
            $this->warn('Live data was not changed. Import the snapshot into an isolated database first; see README recovery instructions.');
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Restore failed: '.$error->getMessage());
            return self::FAILURE;
        }
    }
}
