<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class EncryptedBackups
{
    public function create(bool $scheduled = false): array
    {
        $password = $this->password();
        $directory = $this->privateDirectory(config('backup.directory'));
        $lock = fopen($directory.'/.backup.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('A backup is already running.');
        $work = null;
        $partial = null;
        try {
            if ($scheduled) {
                $archives = glob($directory.'/bw-backup-*.zip') ?: [];
                rsort($archives);
                if ($archives) {
                    try {
                        $latest = $this->verify($archives[0]);
                        if (\Carbon\Carbon::parse($latest['created_at'])->timezone(config('backup.timezone'))->isToday()) {
                            return ['archive' => $archives[0], 'entries' => count($latest['files']), 'bytes' => filesize($archives[0]), 'created_at' => $latest['created_at'], 'skipped' => true];
                        }
                    } catch (Throwable) {
                        // A damaged older backup must not stop creation of a fresh, verified copy.
                        \Illuminate\Support\Facades\Log::warning('Previous backup failed verification; creating a new backup.');
                    }
                }
            }
            $workRoot = $this->privateDirectory(config('backup.work_directory'));
            $work = $workRoot.'/'.Str::uuid();
            File::makeDirectory($work, 0700);
            $database = $this->snapshotDatabase($work);
            $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'database' => $database['entry'], 'driver' => $database['driver'], 'files' => []];
            $name = 'bw-backup-'.now('UTC')->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.zip';
            $partial = $directory.'/'.$name.'.partial';
            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create backup archive.');
            try {
                $zip->setPassword($password);
                $this->add($zip, $database['path'], $database['entry'], $manifest);
                $root = Storage::disk('local')->path('');
                if (is_dir($root)) {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                    foreach ($iterator as $file) {
                        if ($file->isLink()) throw new RuntimeException('Attachment storage contains a symbolic link; backup stopped.');
                        if (!$file->isFile() || in_array($file->getFilename(), ['.gitignore', '.htaccess'], true)) continue;
                        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1));
                        $this->add($zip, $file->getPathname(), 'attachments/'.$relative, $manifest);
                    }
                }
                if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))
                    || !$zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256)) throw new RuntimeException('Cannot encrypt backup manifest.');
            } finally {
                if (!$zip->close()) throw new RuntimeException('Cannot finish backup archive.');
            }
            @chmod($partial, 0600);
            // Read and hash every encrypted entry before promoting or pruning anything.
            $verified = $this->verify($partial);
            $final = $directory.'/'.$name;
            if (!rename($partial, $final)) throw new RuntimeException('Cannot publish verified backup.');
            $partial = null;
            $this->prune($directory, $final);
            return ['archive' => $final, 'entries' => count($verified['files']), 'bytes' => filesize($final), 'created_at' => $verified['created_at']];
        } finally {
            if ($partial && is_file($partial)) unlink($partial);
            if ($work && is_dir($work)) File::deleteDirectory($work);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function verify(string $archive): array
    {
        $zip = $this->open($archive);
        try {
            $manifest = $this->manifest($zip);
            foreach ($manifest['files'] as $entry => $expected) {
                $stream = $zip->getStream($entry);
                if (!$stream) throw new RuntimeException('Backup entry cannot be decrypted.');
                try {
                    $hash = hash_init('sha256');
                    $bytes = hash_update_stream($hash, $stream);
                    if ($bytes !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash))) {
                        throw new RuntimeException('Backup integrity check failed.');
                    }
                } finally { fclose($stream); }
            }
            return $manifest;
        } finally { $zip->close(); }
    }

    public function restore(string $archive, string $destination): array
    {
        $manifest = $this->verify($archive);
        if (file_exists($destination) || is_link($destination)) throw new RuntimeException('Restore destination must not already exist.');
        $parent = $this->privateDirectory(dirname($destination));
        $destination = $parent.'/'.basename($destination);
        if (in_array(basename($destination), ['.', '..', ''], true)) throw new RuntimeException('Invalid restore destination.');
        File::makeDirectory($destination, 0700);
        $zip = $this->open($archive);
        try {
            // Never extractTo(): explicitly allowlisted paths only, into a new directory.
            foreach (array_keys($manifest['files']) as $entry) {
                $path = $destination.'/'.$entry;
                File::ensureDirectoryExists(dirname($path), 0700);
                $input = $zip->getStream($entry);
                $output = fopen($path, 'xb');
                try {
                    if (!$input || !$output || stream_copy_to_stream($input, $output) !== $manifest['files'][$entry]['size']) {
                        throw new RuntimeException('Restore write failed.');
                    }
                } finally {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                }
                @chmod($path, 0600);
                if (!hash_equals($manifest['files'][$entry]['sha256'], hash_file('sha256', $path))) throw new RuntimeException('Restored file failed verification.');
            }
            file_put_contents($destination.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            file_put_contents($destination.'/.htaccess', "Require all denied\n");
            return ['directory' => $destination, 'database' => $destination.'/'.$manifest['database'], 'entries' => count($manifest['files'])];
        } catch (Throwable $error) {
            File::deleteDirectory($destination);
            throw $error;
        } finally { $zip->close(); }
    }

    private function snapshotDatabase(string $work): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $path = $work.'/database.sqlite';
            $connection->getPdo()->exec("VACUUM INTO '".str_replace("'", "''", $path)."'");
            return ['path' => $path, 'entry' => 'database/database.sqlite', 'driver' => $driver];
        }
        if (!in_array($driver, ['mysql', 'mariadb'], true)) throw new RuntimeException('Backups support MySQL, MariaDB, and SQLite.');
        $config = $connection->getConfig();
        $quote = fn ($value) => '"'.strtr((string) $value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r']).'"';
        $defaults = $work.'/mysql-client.cnf';
        $options = "[client]\n";
        foreach (['host', 'port', 'username' => 'user', 'password', 'unix_socket' => 'socket'] as $key => $option) {
            $key = is_int($key) ? $option : $key;
            if (($config[$key] ?? '') !== '') $options .= $option.'='.$quote($config[$key])."\n";
        }
        file_put_contents($defaults, $options);
        @chmod($defaults, 0600);
        $path = $work.'/database.sql';
        try {
            $process = new Process([config('backup.mysqldump'), '--defaults-extra-file='.$defaults, '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--default-character-set=utf8mb4', '--result-file='.$path, '--', $config['database']], timeout: config('backup.timeout'));
            $process->disableOutput();
            if ($process->run() !== 0 || !is_file($path) || filesize($path) === 0) throw new RuntimeException('Database export failed. Check the dump executable and database permissions.');
        } finally { if (is_file($defaults)) unlink($defaults); }
        @chmod($path, 0600);
        return ['path' => $path, 'entry' => 'database/database.sql', 'driver' => $driver];
    }

    private function add(ZipArchive $zip, string $path, string $entry, array &$manifest): void
    {
        $this->validateEntry($entry);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size === false || $hash === false || !$zip->addFile($path, $entry) || !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
            throw new RuntimeException('Cannot read or encrypt backup file.');
        }
        $manifest['files'][$entry] = ['size' => $size, 'sha256' => $hash];
    }

    private function open(string $archive): ZipArchive
    {
        if (!is_file($archive) || is_link($archive)) throw new RuntimeException('Backup archive was not found.');
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open backup archive.');
        $zip->setPassword($this->password());
        return $zip;
    }

    private function manifest(ZipArchive $zip): array
    {
        if ($zip->numFiles < 2 || $zip->numFiles > 200000) throw new RuntimeException('Invalid backup entry count.');
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (($stat['encryption_method'] ?? null) !== ZipArchive::EM_AES_256 || isset($seen[strtolower($name)])) throw new RuntimeException('Backup has unencrypted or duplicate entries.');
            $seen[strtolower($name)] = true;
            if ($name !== 'manifest.json') $this->validateEntry($name);
            elseif ($stat['size'] > 32 * 1024 * 1024) throw new RuntimeException('Backup manifest is too large.');
        }
        $json = @$zip->getFromName('manifest.json');
        if ($json === false) throw new RuntimeException('Backup key is incorrect or the manifest is damaged.');
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== 1 || !is_array($manifest['files'] ?? null) || !isset($manifest['files'][$manifest['database'] ?? ''])
            || !in_array($manifest['database'], ['database/database.sql', 'database/database.sqlite'], true)
            || count($manifest['files']) !== $zip->numFiles - 1) throw new RuntimeException('Invalid backup manifest.');
        foreach ($manifest['files'] as $entry => $expected) {
            $this->validateEntry($entry);
            $stat = $zip->statName($entry);
            if (!$stat || !is_int($expected['size'] ?? null) || $expected['size'] < 0 || $expected['size'] !== $stat['size']
                || !is_string($expected['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $expected['sha256'])) throw new RuntimeException('Invalid backup file metadata.');
        }
        return $manifest;
    }

    private function validateEntry(string $entry): void
    {
        if (!preg_match('~^(attachments/[^\\\\:\x00-\x1f]+|database/database\.(sql|sqlite))$~D', $entry)
            || preg_match('~(^|/)(\.{1,2})(/|$)~', $entry) || str_ends_with($entry, '/')
            || preg_match('~[. ](/|$)~', $entry)) throw new RuntimeException('Unsafe backup entry path.');
    }

    private function password(): string
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) throw new RuntimeException('ZIP AES-256 support is required.');
        $file = config('backup.key_file');
        if (!is_file($file) || is_link($file)) throw new RuntimeException('Backup recovery key is missing. Run backup:initialize.');
        $key = trim(file_get_contents($file));
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Invalid backup recovery key.');
        return $key;
    }

    private function privateDirectory(string $path): string
    {
        if (!str_starts_with($path, '/') && !str_starts_with($path, '\\') && !preg_match('~^[a-z]:[\\\\/]~i', $path)) $path = base_path($path);
        // Resolve the existing ancestor first, so symlinks cannot bypass the public-root check.
        $ancestor = $path;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
        $resolved = realpath($ancestor);
        if (!$resolved || $this->within($resolved, public_path()) || $this->within($resolved, Storage::disk('local')->path(''))) {
            throw new RuntimeException('Backup and restore directories must be outside public and attachment storage.');
        }
        if (is_link($path)) throw new RuntimeException('Backup directory must not be a symbolic link.');
        File::ensureDirectoryExists($path, 0700);
        $real = realpath($path);
        if ($this->within($real, public_path()) || $this->within($real, Storage::disk('local')->path(''))) throw new RuntimeException('Unsafe backup directory.');
        file_put_contents($real.'/.htaccess', "Require all denied\n");
        @chmod($real, 0700);
        return str_replace('\\', '/', $real);
    }

    private function within(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        $root = strtolower(str_replace('\\', '/', rtrim($root, '/\\')));
        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function prune(string $directory, string $latest): void
    {
        $cutoff = now()->subDays(max(1, (int) config('backup.retention_days')))->timestamp;
        foreach (glob($directory.'/bw-backup-*.zip') ?: [] as $path) {
            if ($path !== $latest && !is_link($path) && preg_match('/^bw-backup-\d{8}-\d{6}-[a-z0-9]{8}\.zip$/D', basename($path)) && filemtime($path) < $cutoff) unlink($path);
        }
    }
}
