<?php

namespace App\Console\Commands;

use App\Services\EncryptedBackups;
use Illuminate\Console\Command;
use Throwable;

class VerifyBackup extends Command
{
    protected $signature = 'backup:verify {archive : Path to the encrypted ZIP archive}';
    protected $description = 'Decrypt and verify every database and attachment entry without changing live data';

    public function handle(EncryptedBackups $backups): int
    {
        try {
            $manifest = $backups->verify($this->argument('archive'));
            $this->info('Verified '.count($manifest['files']).' encrypted entries. Created: '.$manifest['created_at']);
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Backup verification failed: '.$error->getMessage());
            return self::FAILURE;
        }
    }
}
