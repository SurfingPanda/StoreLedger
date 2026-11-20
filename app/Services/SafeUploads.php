<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class SafeUploads
{
    public static function store(UploadedFile $file, string $directory, string $field): string|false
    {
        app(UploadScanner::class)->validate($file->getPathname(), $field);
        return $file->store($directory, 'local');
    }
}
