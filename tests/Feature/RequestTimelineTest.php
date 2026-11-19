<?php

namespace Tests\Feature;

use App\Models\RequestRevision;
use App\Models\User;
use App\Services\RequestModule;
use App\Services\RequestRevisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequestTimelineTest extends TestCase
{
    use RefreshDatabase;

    public static function modules(): array { return [['utak-pos'], ['internet-soa'], ['water'], ['electricity'], ['rental']]; }

    private function record(string $module, User $actor)
    {
        $record = RequestModule::query($module)->getModel()->newInstance();
        $record->forceFill(['store_name' => 'Timeline store', 'amount' => '100.00', 'status' => 'awaiting_voucher',
            'created_by' => $actor->id, RequestModule::dateField($module) => '2026-12-01', 'created_at' => '2026-11-01 00:00:00']);
        if ($module !== 'utak-pos') $record->forceFill(['attachment_path' => 'private/initial.pdf', 'attachment_name' => 'initial.pdf']);
        if (in_array($module, ['water', 'electricity', 'rental'])) $record->module = $module;
        $record->save();
        return $record;
    }

    #[DataProvider('modules')]
    public function test_all_events_share_one_ordered_paginated_sequence_without_private_paths(string $module): void
    {
        $actor = User::factory()->create(['role' => 'accounting']);
        $record = $this->record($module, $actor);
        $comment = $record->comments()->make(['body' => 'Check the attached image.']);
        $comment->forceFill(['user_id' => $actor->id, 'attachment_path' => 'private/evidence.png',
            'attachment_name' => 'evidence.png', 'attachment_mime' => 'image/png', 'attachment_size' => 100,
            'created_at' => '2026-11-01 01:00:00']);
        $comment->save();
        $record->forceFill(['status' => 'vouchered', 'voucher_reference' => 'FIRST',
            'voucher_action_by' => $actor->id, 'voucher_action_at' => '2026-11-01 02:00:00']);
        $before = RequestRevisions::snapshot($record);
        $record->status = 'needs_revision';
        $returned = RequestRevisions::snapshot($record);
        RequestRevision::create(['module' => $module, 'request_id' => $record->id, 'user_id' => $actor->id,
            'action' => 'returned', 'reason' => 'Correct the voucher.', 'before' => $before, 'after' => $returned,
            'created_at' => '2026-11-01 03:00:00']);
        $record->forceFill(['status' => 'paid', 'voucher_reference' => 'SECOND', 'voucher_action_at' => '2026-11-01 04:00:00']);
        $record->save();
        RequestRevision::create(['module' => $module, 'request_id' => $record->id, 'user_id' => $actor->id,
            'action' => 'resubmitted', 'reason' => 'Voucher corrected.', 'before' => $returned, 'after' => RequestRevisions::snapshot($record),
            'created_at' => '2026-11-01 04:00:00']);
        $payment = $record->payment()->make(['paid_on' => '2026-11-01', 'method' => 'cash', 'reference' => 'PAY-1']);
        $payment->forceFill(['user_id' => $actor->id, 'amount' => '100.00', 'proof_path' => 'private/proof.pdf',
            'proof_name' => 'proof.pdf', 'created_at' => '2026-11-01 05:00:00']);
        $payment->save();
        $other = $this->record($module, $actor);
        $otherComment = $other->comments()->make(['body' => 'Do not expose another request.']);
        $otherComment->user_id = $actor->id; $otherComment->save();

        $this->actingAs($actor);
        $url = route($module.'.details', $record);
        $response = $this->getJson($url.'?timeline_direction=asc&timeline_per_page=100')->assertOk()
            ->assertJsonPath('timeline.total', 7)->assertJsonPath('timeline.data.0.type', 'created')
            ->assertJsonPath('timeline.data.1.type', 'comment')
            ->assertJsonPath('timeline.data.1.attachments.0.attachment_url', route($module.'.comment-attachment', [$record->id, $comment->id]))
            ->assertJsonPath('timeline.data.6.type', 'payment');
        $events = $response->json('timeline.data');
        $this->assertSame(['FIRST', 'SECOND'], collect($events)->where('type', 'voucher')->map(fn ($event) => str_contains($event['body'], 'FIRST') ? 'FIRST' : 'SECOND')->values()->all());
        $this->assertStringNotContainsString('private/', json_encode($events));
        $this->assertStringNotContainsString('Do not expose', json_encode($events));
        $this->assertStringNotContainsString($actor->email, json_encode($events));
        $ids = [];
        for ($page = 1; $page <= 4; $page++) {
            $part = $this->getJson($url.'?timeline_per_page=2&timeline_page='.$page)->assertOk()->assertJsonPath('timeline.total', 7)->json('timeline.data');
            $ids = array_merge($ids, array_column($part, 'id'));
        }
        $this->assertCount(7, array_unique($ids));
        $this->getJson($url.'?timeline_per_page=2')->assertJsonPath('timeline.data.0.type', 'payment');
        foreach (['timeline_per_page=101', 'timeline_page=0', 'timeline_direction=invalid'] as $query) $this->getJson($url.'?'.$query)->assertUnprocessable();
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_current_statement_is_archived_in_the_timeline_once_with_its_uploader(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['role' => 'admin']);
        $record = $this->record('water', $actor);
        $record->forceFill(['status' => 'awaiting_soa', 'attachment_path' => null, 'attachment_name' => null]); $record->save();
        $this->actingAs($actor)->postJson(route('water.statement', $record), ['amount' => '123.00',
            'soa' => UploadedFile::fake()->createWithContent('current.pdf', "%PDF-1.4\n%%EOF")])->assertOk();
        $events = $this->getJson(route('water.details', $record))->assertOk()->json('timeline.data');
        $uploads = collect($events)->where('title', 'SOA attached')->values();
        $this->assertCount(1, $uploads);
        $this->assertSame($actor->name, $uploads[0]['actor']['name']);
        $this->assertSame('current.pdf', $uploads[0]['attachments'][0]['attachment_name']);
        $this->get($uploads[0]['attachments'][0]['attachment_url'])->assertDownload('current.pdf');
    }
}
