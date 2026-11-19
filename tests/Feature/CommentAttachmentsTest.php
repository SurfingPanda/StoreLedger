<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RequestModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommentAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Storage::fake('local'); }
    public static function modules(): array { return [['utak-pos'], ['internet-soa'], ['water'], ['electricity'], ['rental']]; }

    private function record(string $module)
    {
        $record = RequestModule::query($module)->getModel()->newInstance();
        $record->store_name = 'Comment test store'; $record->amount = 100; $record->status = 'awaiting_voucher';
        $record->created_by = User::factory()->create(['role' => 'admin'])->id;
        $record->{RequestModule::dateField($module)} = '2026-12-01';
        if ($module !== 'utak-pos') { $record->attachment_path = 'bill.pdf'; $record->attachment_name = 'bill.pdf'; }
        if (in_array($module, ['water', 'electricity', 'rental'])) $record->module = $module;
        $record->save(); return $record;
    }

    #[DataProvider('modules')]
    public function test_image_comments_are_private_previewable_and_scoped_to_the_request(string $module): void
    {
        $record = $this->record($module);
        $this->actingAs($accountant = User::factory()->create(['role' => 'accounting']));
        $file = UploadedFile::fake()->image('evidence.png', 40, 40);
        $response = $this->postJson(route($module.'.comments', $record), [
            'body' => 'Please review the attached image.', 'attachment' => $file, 'user_id' => 9999, 'attachment_path' => 'forged/path',
        ])->assertCreated()->assertJsonPath('comment.attachment_name', 'evidence.png')
            ->assertJsonPath('comment.attachment_mime', 'image/png')->assertJsonPath('comment.author.name', $accountant->name)
            ->assertJsonMissingPath('comment.attachment_path');
        $comment = $record->comments()->firstOrFail();
        $this->assertSame($accountant->id, $comment->user_id);
        Storage::disk('local')->assertExists($comment->attachment_path);
        $url = $response->json('comment.attachment_url');
        $this->get($url)->assertDownload('evidence.png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($url.'?preview=1')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson(route($module.'.details', $record))->assertOk()
            ->assertJsonPath('comments.data.0.attachment_url', $url)->assertJsonMissingPath('comments.data.0.attachment_path');
        $other = $this->record($module);
        $this->getJson(route($module.'.comment-attachment', [$other->id, $comment->id]))->assertNotFound();
        if (in_array($module, ['water', 'electricity', 'rental'])) {
            $wrongModule = $module === 'water' ? 'rental' : 'water';
            $this->getJson(route($wrongModule.'.comment-attachment', [$record->id, $comment->id]))->assertNotFound();
        }
        $this->actingAs(User::factory()->create(['role' => 'it']))->get($url)->assertDownload('evidence.png');
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
    }

    #[DataProvider('modules')]
    public function test_attachment_only_comments_accept_pdf_and_document_downloads(string $module): void
    {
        $record = $this->record($module);
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $pdf = UploadedFile::fake()->createWithContent('voucher.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $response = $this->postJson(route($module.'.comments', $record), ['attachment' => $pdf])->assertCreated()
            ->assertJsonPath('comment.body', '')->assertJsonPath('comment.attachment_name', 'voucher.pdf');
        $this->get($response->json('comment.attachment_url').'?preview=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = UploadedFile::fake()->createWithContent('notes.txt', 'Supporting payment details.');
        $response = $this->postJson(route($module.'.comments', $record), ['attachment' => $text])->assertCreated();
        $this->get($response->json('comment.attachment_url'))->assertDownload('notes.txt');
        $this->getJson($response->json('comment.attachment_url').'?preview=1')->assertStatus(415);
        $this->postJson(route($module.'.comments', $record), ['body' => 'Text remains supported.'])->assertCreated()->assertJsonPath('comment.attachment_url', null);
        $this->assertSame(3, $record->comments()->count());
    }

    #[DataProvider('modules')]
    public function test_invalid_and_oversized_attachments_leave_no_files_or_comments(string $module): void
    {
        $record = $this->record($module);
        $this->actingAs(User::factory()->create(['role' => 'accounting']));
        $url = route($module.'.comments', $record);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $html = UploadedFile::fake()->createWithContent('source.html', '<html><script>alert(1)</script></html>');
        $spoofedPdf = new UploadedFile($html->getRealPath(), 'renamed.pdf', 'application/pdf', null, true);
        foreach ([
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
            UploadedFile::fake()->createWithContent('script.php', '<?php echo 1; ?>'),
            $spoofedPdf,
            UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ] as $file) {
            $this->postJson($url, ['body' => 'Evidence', 'attachment' => $file])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        }
        $this->assertSame(0, $record->comments()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_attachment_uploads_follow_existing_comment_permissions(): void
    {
        $record = $this->record('water');
        $url = route('water.comments', $record);
        $this->postJson($url, ['attachment' => UploadedFile::fake()->image('photo.png')])->assertUnauthorized();
        foreach (['admin', 'it', 'rso'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson($url, ['attachment' => UploadedFile::fake()->image('photo.png')])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'accounting']))
            ->postJson('/electricity/records/'.$record->id.'/comments', ['attachment' => UploadedFile::fake()->image('photo.png')])->assertNotFound();
        $this->assertSame(0, $record->comments()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
