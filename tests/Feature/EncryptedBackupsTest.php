<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EncryptedBackups;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class EncryptedBackupsTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        // VACUUM needs a connection outside a transaction; this uses only PHPUnit's in-memory database.
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('database.default'));
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('local');
        $this->workspace = storage_path('framework/testing/backup-'.Str::uuid());
        File::ensureDirectoryExists($this->workspace);
        file_put_contents($this->workspace.'/recovery.key', bin2hex(random_bytes(32)));
        config(['backup.directory' => $this->workspace.'/archives', 'backup.key_file' => $this->workspace.'/recovery.key', 'backup.work_directory' => $this->workspace.'/work']);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) File::deleteDirectory($this->workspace);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_database_and_attachments_can_be_decrypted_restored_and_opened_without_changing_live_records(): void
    {
        $user = User::factory()->create(['name' => 'Backup recovery fixture']);
        Storage::disk('local')->put('billing-soas/nested/example.pdf', 'private statement bytes');
        $service = app(EncryptedBackups::class);
        $result = $service->create();
        $manifest = $service->verify($result['archive']);
        $this->assertSame(2, count($manifest['files']));
        $zip = new ZipArchive; $zip->open($result['archive']);
        $this->assertFalse(@$zip->getFromName('manifest.json'));
        for ($i = 0; $i < $zip->numFiles; $i++) $this->assertSame(ZipArchive::EM_AES_256, $zip->statIndex($i)['encryption_method']);
        $this->assertFalse($zip->locateName('recovery.key')); $zip->close();
        $restored = $service->restore($result['archive'], $this->workspace.'/restore');
        $this->assertSame('private statement bytes', file_get_contents($restored['directory'].'/attachments/billing-soas/nested/example.pdf'));
        $database = new PDO('sqlite:'.$restored['database']);
        $this->assertSame($user->name, $database->query('SELECT name FROM users')->fetchColumn());
        $database = null;
        $this->assertSame(1, User::count());
    }

    public function test_wrong_key_cannot_restore_and_does_not_create_a_destination(): void
    {
        $service = app(EncryptedBackups::class); $archive = $service->create()['archive'];
        file_put_contents($this->workspace.'/wrong.key', bin2hex(random_bytes(32)));
        config(['backup.key_file' => $this->workspace.'/wrong.key']);
        try { $service->restore($archive, $this->workspace.'/restore'); $this->fail('Wrong key must fail.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('key', $error->getMessage()); }
        $this->assertDirectoryDoesNotExist($this->workspace.'/restore');
        $this->assertFileExists($archive);
    }

    public function test_tampered_attachment_and_unsafe_paths_are_rejected(): void
    {
        Storage::disk('local')->put('proofs/test.txt', 'original');
        $service = app(EncryptedBackups::class); $archive = $service->create()['archive'];
        $key = trim(file_get_contents(config('backup.key_file')));
        $zip = new ZipArchive; $zip->open($archive); $zip->setPassword($key);
        $zip->addFromString('attachments/proofs/test.txt', 'tampered'); $zip->setEncryptionName('attachments/proofs/test.txt', ZipArchive::EM_AES_256); $zip->close();
        try { $service->verify($archive); $this->fail('Tampering must fail.'); } catch (RuntimeException $error) { $this->assertNotEmpty($error->getMessage()); }
        $zip = new ZipArchive; $zip->open($archive); $zip->setPassword($key);
        $zip->addFromString('attachments/../../escape.txt', 'unsafe'); $zip->setEncryptionName('attachments/../../escape.txt', ZipArchive::EM_AES_256); $zip->close();
        $this->expectException(RuntimeException::class);
        $service->restore($archive, $this->workspace.'/unsafe-restore');
    }

    public function test_restore_cannot_overwrite_existing_files_or_target_public_storage(): void
    {
        $service = app(EncryptedBackups::class); $archive = $service->create()['archive'];
        File::makeDirectory($this->workspace.'/existing'); file_put_contents($this->workspace.'/existing/keep.txt', 'keep');
        try { $service->restore($archive, $this->workspace.'/existing'); $this->fail('Existing directory must be rejected.'); } catch (RuntimeException) {}
        $this->assertSame('keep', file_get_contents($this->workspace.'/existing/keep.txt'));
        $this->expectException(RuntimeException::class);
        $service->restore($archive, public_path('unsafe-restore-'.Str::uuid()));
    }

    public function test_failed_backup_does_not_prune_and_success_prunes_only_owned_expired_archives(): void
    {
        $service = app(EncryptedBackups::class); $old = $service->create()['archive']; touch($old, time() - 40 * 86400);
        $unrelated = config('backup.directory').'/unrelated.zip'; file_put_contents($unrelated, 'keep'); touch($unrelated, time() - 40 * 86400);
        $key = config('backup.key_file'); config(['backup.key_file' => $this->workspace.'/missing.key']);
        try { $service->create(); $this->fail('Missing key must fail.'); } catch (RuntimeException) {}
        $this->assertFileExists($old);
        config(['backup.key_file' => $key]); $latest = $service->create();
        $this->assertFileDoesNotExist($old); $this->assertFileExists($unrelated); $this->assertFileExists($latest['archive']);
        $this->assertSame([], glob($this->workspace.'/work/*') ?: []);
    }

    public function test_scheduled_runs_skip_a_verified_daily_backup_but_replace_a_damaged_copy(): void
    {
        $service = app(EncryptedBackups::class);
        $first = $service->create(true);
        $second = $service->create(true);
        $this->assertTrue($second['skipped']);
        $this->assertSame($first['archive'], $second['archive']);
        file_put_contents($first['archive'], 'damaged archive');
        $replacement = $service->create(true);
        $this->assertNotSame($first['archive'], $replacement['archive']);
        $this->assertSame('sqlite', $service->verify($replacement['archive'])['driver']);
        $this->assertFileExists($first['archive']);
    }
}
