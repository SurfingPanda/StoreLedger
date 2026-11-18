<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\RequestRevision;
use App\Models\SecurityAuditLog;
use App\Services\RequestModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); Storage::fake('local'); }
    public static function modules(): array { return array_map(fn ($module) => [$module], array_keys(RequestModule::LABELS)); }
    private function record(string $module)
    {
        $record = RequestModule::query($module)->getModel()->newInstance();
        $record->store_name = 'Preview Store'; $record->amount = 1500; $record->status = 'paid';
        $record->{RequestModule::dateField($module)} = '2026-12-15';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        if (!in_array($module, ['utak-pos', 'internet-soa'])) $record->module = $module;
        if ($module !== 'utak-pos') { $record->attachment_name = 'statement.pdf'; $record->attachment_path = 'private/statement.pdf'; }
        $record->save(); return $record;
    }
    private function pdf(): string { return "%PDF-1.4\n1 0 obj<</Type /Catalog>>endobj\n%%EOF"; }
    private function payment($record, string $path = 'private/proof.pdf', string $name = 'proof.pdf'): void
    {
        $payment = $record->payment()->make(); $payment->forceFill(['user_id' => $record->created_by, 'amount' => 1500,
            'paid_on' => now()->toDateString(), 'method' => 'cash', 'reference' => 'PREVIEW-TEST', 'proof_path' => $path, 'proof_name' => $name])->save();
    }

    #[DataProvider('modules')]
    public function test_soas_and_payment_proofs_support_private_inline_pdf_and_keep_download_behavior(string $module): void
    {
        $record = $this->record($module); $this->payment($record);
        Storage::disk('local')->put('private/proof.pdf', $this->pdf());
        if ($module !== 'utak-pos') Storage::disk('local')->put('private/statement.pdf', $this->pdf());
        $this->actingAs(User::find($record->created_by));
        $routes = [route($module.'.payment-proof', $record)];
        if ($module !== 'utak-pos') $routes[] = route($module.'.attachment', $record);
        foreach ($routes as $url) {
            $response = $this->get($url.'?preview=1', ['Accept' => 'application/json'])->assertOk()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
            $this->assertSame($this->pdf(), $response->streamedContent());
            $this->get($url)->assertOk()->assertDownload();
        }
        $this->assertSame(count($routes), SecurityAuditLog::where('event', 'attachment.preview_requested')->count());
        $this->assertSame(count($routes), SecurityAuditLog::where('event', 'attachment.download_requested')->count());
        $this->assertStringNotContainsString('private/proof.pdf', SecurityAuditLog::all()->toJson());
    }

    #[DataProvider('modules')]
    public function test_valid_images_are_previewed_using_detected_type_not_the_filename(string $module): void
    {
        $record = $this->record($module); $this->payment($record, 'private/image.dat', 'misleading.pdf');
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII=');
        Storage::disk('local')->put('private/image.dat', $png);
        $this->get(route($module.'.payment-proof', $record).'?preview=1')->assertOk()->assertHeader('Content-Type', 'image/png');
        Storage::disk('local')->put('private/image.dat', "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9");
        $this->get(route($module.'.payment-proof', $record).'?preview=1')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    #[DataProvider('modules')]
    public function test_preview_requests_require_signin_and_reject_disabled_users_missing_files_and_unsafe_types(string $module): void
    {
        $record = $this->record($module); $this->payment($record);
        $url = route($module.'.payment-proof', $record).'?preview=1';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'rso', 'is_active' => false]))->getJson($url)->assertUnauthorized();
        $this->actingAs(User::find($record->created_by))->getJson($url)->assertNotFound();
        Storage::disk('local')->put('private/proof.pdf', '<html><script>alert(1)</script></html>');
        $this->getJson($url)->assertStatus(415);
        $this->get(route($module.'.payment-proof', $record))->assertDownload('proof.pdf');
        $this->getJson(route($module.'.payment-proof', $record).'?preview=not-a-boolean')->assertUnprocessable();
        $this->assertSame(0, SecurityAuditLog::where('event', 'attachment.preview_requested')->count());
    }

    public function test_revision_previews_are_authenticated_scoped_and_audited_without_exposing_paths(): void
    {
        $record = $this->record('water'); Storage::disk('local')->put('private/archived.pdf', $this->pdf());
        $event = RequestRevision::create(['module' => 'water', 'request_id' => $record->id, 'user_id' => $record->created_by,
            'action' => 'resubmitted', 'reason' => 'Corrected.', 'before' => ['attachment_path' => 'private/archived.pdf', 'attachment_name' => 'old.pdf'], 'after' => []]);
        $url = route('request-revisions.attachment', [$event, 'before']);
        $this->getJson($url.'?preview=1')->assertUnauthorized();
        $this->actingAs(User::find($record->created_by));
        $this->get($url.'?preview=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson(route('request-revisions.attachment', [$event, 'invalid']).'?preview=1')->assertNotFound();
        $this->getJson(route('request-revisions.attachment', [$event, 'after']).'?preview=1')->assertNotFound();
        $this->getJson('/rental/'.$record->id.'/attachment?preview=1')->assertNotFound();
        $audit = SecurityAuditLog::where('event', 'attachment.preview_requested')->firstOrFail();
        $this->assertSame('water', $audit->module); $this->assertSame('before', $audit->metadata['side']);
    }
}
