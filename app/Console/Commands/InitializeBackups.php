<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InitializeBackups extends Command
{
    protected $signature = 'backup:initialize';
    protected $description = 'Create a separate backup recovery key without printing it or overwriting an existing key';

    public function handle(): int
    {
        $path = config('backup.key_file');
        if (file_exists($path)) {
            $this->info('A recovery key already exists. It was not changed.');
            return self::SUCCESS;
        }
        File::ensureDirectoryExists(dirname($path), 0700);
        file_put_contents(dirname($path).'/.htaccess', "Require all denied\n");
        $file = fopen($path, 'xb');
        if (!$file) { $this->error('Could not create the recovery key.'); return self::FAILURE; }
        try { fwrite($file, bin2hex(random_bytes(32))."\n"); } finally { fclose($file); }
        @chmod($path, 0600);
        $this->info('Recovery key created: '.$path);
        $this->warn('Keep a separate secure copy of this key. It is not included in backups; losing it makes the backups unrecoverable.');
        return self::SUCCESS;
    }
}
