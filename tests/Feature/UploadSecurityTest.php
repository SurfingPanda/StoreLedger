<?php

namespace Tests\Feature;

use App\Models\PosRenewal;
use App\Models\User;
use App\Services\SafeUploads;
use App\Services\UploadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['security.uploads.driver' => 'defender', 'security.uploads.defender_binary' => PHP_BINARY]);
    }

    public function test_a_clean_scan_is_required_before_storage_and_scanner_uses_argument_arrays(): void
    {
        Process::fake([Process::result(output: 'No threats.', exitCode: 0)]);
        $file = UploadedFile::fake()->createWithContent('statement with spaces.pdf', '%PDF-clean fixture');
        $path = SafeUploads::store($file, 'test-statements', 'soa');
        Storage::disk('local')->assertExists($path);
        Process::assertRan(fn ($process) => is_array($process->command) && in_array('-DisableRemediation', $process->command, true) && in_array(realpath($file->getPathname()), $process->command, true));
    }

    public function test_a_failed_or_unavailable_scanner_never_stores_the_upload(): void
    {
        Process::fake([Process::result(exitCode: 2)]);
        try {
            SafeUploads::store(UploadedFile::fake()->createWithContent('evidence.txt', 'untrusted contents'), 'comment-attachments', 'attachment');
            $this->fail('Upload should have been rejected.');
        } catch (ValidationException $error) { $this->assertArrayHasKey('attachment', $error->errors()); }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rejected_payment_proofs_cannot_mark_a_voucher_paid_or_create_a_comment(): void
    {
        Process::fake([Process::result(exitCode: 2)]);
        $actor = User::factory()->create(['role' => 'finance']);
        $record = new PosRenewal(['store_name' => 'Scan test', 'renewal_date' => now()->toDateString(), 'amount' => 1500]);
        $record->status = 'vouchered'; $record->created_by = $actor->id; $record->save();
        $this->actingAs($actor)->postJson(route('utak-pos.payment', $record), [
            'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'cash', 'reference' => 'SCAN-TEST', 'proof' => UploadedFile::fake()->image('proof.png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertSame('vouchered', $record->fresh()->status);
        $this->assertNull($record->fresh()->payment);
        $this->postJson(route('utak-pos.comments', $record), ['body' => 'Untrusted attachment', 'attachment' => UploadedFile::fake()->image('evidence.png')])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->assertSame(0, $record->comments()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_test_driver_cannot_bypass_scanning_outside_the_test_environment(): void
    {
        config(['security.uploads.driver' => 'testing']);
        app()->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        app(UploadScanner::class)->scan(UploadedFile::fake()->createWithContent('test.txt', 'data')->getPathname());
    }

    public function test_unknown_or_disabled_scanner_configuration_fails_closed(): void
    {
        config(['security.uploads.driver' => 'off']);
        $this->expectException(ValidationException::class);
        SafeUploads::store(UploadedFile::fake()->image('proof.png'), 'proofs', 'proof');
    }
}
