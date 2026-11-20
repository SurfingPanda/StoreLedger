<?php

return [
    'uploads' => [
        // No permissive production fallback: scanner failures reject the upload.
        'driver' => env('UPLOAD_SCAN_DRIVER', PHP_OS_FAMILY === 'Windows' ? 'defender' : 'clamd'),
        'defender_binary' => env('UPLOAD_SCAN_BINARY'),
        'timeout' => (int) env('UPLOAD_SCAN_TIMEOUT', 60),
        'clamd_address' => env('UPLOAD_CLAMD_ADDRESS', 'tcp://127.0.0.1:3310'),
    ],
];
