<?php

return [
    'directory' => env('BACKUP_DIRECTORY', storage_path('app/backups')),
    'work_directory' => env('BACKUP_WORK_DIRECTORY', base_path('.local/backup-work')),
    'key_file' => env('BACKUP_KEY_FILE', base_path('.local/backup-recovery.key')),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'time' => env('BACKUP_TIME', '02:00'),
    'timezone' => env('BACKUP_TIMEZONE', 'Asia/Manila'),
    'mysqldump' => env('BACKUP_MYSQLDUMP', PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump'),
    'timeout' => (int) env('BACKUP_TIMEOUT', 600),
];
