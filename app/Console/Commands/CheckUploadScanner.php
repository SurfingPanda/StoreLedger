<?php

namespace App\Console\Commands;

use App\Services\UploadScanner;
use Illuminate\Console\Command;
use Throwable;

class CheckUploadScanner extends Command
{
    protected $signature = 'security:check-upload-scanner';
    protected $description = 'Scan a harmless temporary file to verify the upload scanner works for this process account';

    public function handle(UploadScanner $scanner): int
    {
        $path = tempnam(sys_get_temp_dir(), 'bw-scan-');
        try {
            file_put_contents($path, 'BW Operations harmless scanner health check.');
            $scanner->scan($path);
            $this->info('Upload scanner confirmed a clean file ('.config('security.uploads.driver').').');
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Upload scanner unavailable or unable to confirm a clean file. Uploads will be blocked. Check the scanner configuration and process permissions.');
            return self::FAILURE;
        } finally {
            if (is_file($path)) unlink($path);
        }
    }
}
