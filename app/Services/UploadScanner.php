<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class UploadScanner
{
    public function scan(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Upload is missing or unreadable.');
        $before = hash_file('sha256', $path);
        $driver = config('security.uploads.driver');
        if ($driver === 'testing' && app()->environment('testing')) return;
        if ($driver === 'defender') {
            $result = Process::timeout(config('security.uploads.timeout'))->run([
                $this->defenderBinary(), '-Scan', '-ScanType', '3', '-File', realpath($path), '-DisableRemediation',
            ]);
            // DisableRemediation prevents an infected-but-remediated exit 0 from being treated as clean.
            if (!$result->successful()) throw new RuntimeException('Antivirus did not confirm a clean file.');
        } elseif ($driver === 'clamd') {
            $this->scanWithClamd($path);
        } else {
            throw new RuntimeException('No supported upload scanner is configured.');
        }
        clearstatcache(true, $path);
        if (!is_file($path) || !hash_equals($before, hash_file('sha256', $path))) {
            throw new RuntimeException('Upload changed or was removed during scanning.');
        }
    }

    public function validate(string $path, string $field): void
    {
        try {
            $this->scan($path);
        } catch (Throwable $error) {
            // Never log the contents, original name, or external scanner output.
            Log::warning('Upload rejected by security scanner.', ['driver' => config('security.uploads.driver'), 'error_type' => $error::class]);
            throw ValidationException::withMessages([$field => 'The file could not pass the security scan. It was not saved. Try a different file or contact your administrator if the problem continues.']);
        }
    }

    private function defenderBinary(): string
    {
        if ($binary = config('security.uploads.defender_binary')) {
            if (!is_file($binary)) throw new RuntimeException('Configured Defender executable is unavailable.');
            return $binary;
        }
        $platform = (getenv('ProgramData') ?: 'C:/ProgramData').'/Microsoft/Windows Defender/Platform';
        $versions = glob($platform.'/*/MpCmdRun.exe') ?: [];
        usort($versions, fn ($a, $b) => version_compare(basename(dirname($b)), basename(dirname($a))));
        foreach ([...$versions, (getenv('ProgramFiles') ?: 'C:/Program Files').'/Windows Defender/MpCmdRun.exe'] as $candidate) {
            if (is_file($candidate)) return $candidate;
        }
        throw new RuntimeException('Microsoft Defender executable was not found.');
    }

    private function scanWithClamd(string $path): void
    {
        $timeout = max(1, (int) config('security.uploads.timeout'));
        $socket = @stream_socket_client(config('security.uploads.clamd_address'), $code, $message, $timeout);
        if (!$socket) throw new RuntimeException('ClamAV is unavailable.');
        $input = fopen($path, 'rb');
        try {
            stream_set_timeout($socket, $timeout);
            $this->writeAll($socket, "zINSTREAM\0");
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) throw new RuntimeException('Could not read upload.');
                if ($chunk !== '') $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
            }
            $this->writeAll($socket, pack('N', 0));
            $reply = stream_get_line($socket, 4096, "\0");
            if ($reply === false || trim($reply) !== 'stream: OK' || stream_get_meta_data($socket)['timed_out']) {
                throw new RuntimeException('ClamAV did not confirm a clean file.');
            }
        } finally {
            if (is_resource($input)) fclose($input);
            fclose($socket);
        }
    }

    private function writeAll($stream, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($stream, $data);
            if (!$written) throw new RuntimeException('Scanner connection was interrupted.');
            $data = substr($data, $written);
        }
    }
}
