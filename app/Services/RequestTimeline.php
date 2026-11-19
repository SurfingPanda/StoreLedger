<?php

namespace App\Services;

use App\Models\RequestRevision;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequestTimeline
{
    public static function history(Model $record, Request $request)
    {
        $data = $request->validate([
            'timeline_page' => ['sometimes', 'integer', 'min:1'],
            'timeline_per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'timeline_direction' => ['sometimes', 'in:asc,desc'],
        ]);
        $module = RequestModule::key($record);
        $record->loadMissing(['creator', 'voucherActor', 'payment.recordedBy']);
        $revisions = RequestRevision::where('module', $module)->where('request_id', $record->id)
            ->with('actor')->orderBy('id')->get();
        $events = [];
        $add = function (string $key, string $type, string $title, $at, $actor, string $body = '', array $files = [], ?int $revision = null) use (&$events) {
            if (!$at) return;
            $events[$key] = ['id' => $key, 'type' => $type, 'title' => $title,
                'occurred_at' => Carbon::parse($at)->utc()->toIso8601String(),
                'actor' => $actor ? collect($actor)->only(['name', 'role'])->all() : null,
                'body' => $body, 'attachments' => $files, 'revision_id' => $revision];
        };
        $file = fn ($snapshot, $revision, $side) => !empty($snapshot['attachment_path'])
            ? [['attachment_name' => $snapshot['attachment_name'], 'attachment_url' => route('request-revisions.attachment', [$revision, $side])]] : [];

        $original = $revisions->first()?->before;
        $initialFiles = $record->recurring_schedule_id ? [] : ($original !== null ? $file($original, $revisions->first(), 'before')
            : ($record->attachment_path && !$record->recurring_schedule_id
                ? [['attachment_name' => $record->attachment_name, 'attachment_url' => route($module.'.attachment', $record->id)]] : []));
        $add('created', 'created', 'Request created', $record->created_at, $record->creator,
            'Request submitted for processing.', $initialFiles);

        // Revision snapshots retain earlier voucher processing even after corrections clear it.
        $snapshots = [];
        foreach ($revisions as $revision) {
            $titles = ['returned' => 'Returned for revision', 'reopened' => 'Request reopened',
                'resubmitted' => 'Corrections resubmitted', 'metadata_corrected' => 'Billing details corrected',
                'statement_added' => 'SOA attached'];
            $changedFile = ($revision->before['attachment_path'] ?? null) !== ($revision->after['attachment_path'] ?? null);
            $add('revision-'.$revision->id, 'revision', $titles[$revision->action] ?? 'Request revised',
                $revision->created_at, $revision->actor, $revision->reason,
                $changedFile ? $file($revision->after, $revision, 'after') : [], $revision->id);
            $snapshots[] = $revision->before;
            $snapshots[] = $revision->after;
        }
        $snapshots[] = $record->toArray();
        $actorIds = collect($snapshots)->pluck('voucher_action_by')->filter()->unique();
        $actors = User::whereIn('id', $actorIds)->get(['id', 'name', 'role'])->keyBy('id');
        foreach ($snapshots as $snapshot) {
            $at = $snapshot['voucher_action_at'] ?? null;
            $cancelled = ($snapshot['status'] ?? '') === 'cancelled';
            if ($at && (!$cancelled || !empty($snapshot['duplicate_cancelled_at']))) {
                $add('voucher-'.sha1(Carbon::parse($at)->toIso8601String().'|'.($snapshot['voucher_reference'] ?? '')), 'voucher', 'Voucher processed', $at,
                    $actors->get($snapshot['voucher_action_by'] ?? null),
                    'Sent to Finance. Voucher reference: '.($snapshot['voucher_reference'] ?? 'Not provided'));
            }
            if ($cancelled) {
                $cancelAt = $snapshot['duplicate_cancelled_at'] ?? $at;
                if ($cancelAt) $add('cancelled-'.Carbon::parse($cancelAt)->timestamp, 'cancelled', 'Request cancelled',
                    $cancelAt, empty($snapshot['duplicate_cancelled_at']) ? $actors->get($snapshot['voucher_action_by'] ?? null) : null,
                    $snapshot['cancellation_reason'] ?? 'Request removed from the active workflow.');
            }
        }

        // Earlier statement uploads were recorded in the audit log. Expose only this workflow event,
        // never audit metadata such as IP addresses, emails, or unrelated account activity.
        foreach (SecurityAuditLog::where('module', $module)->where('record_id', $record->id)
            ->where('event', 'request.statement_added')->where('outcome', 'success')->orderBy('id')->get() as $upload) {
            if ($revisions->contains(fn ($revision) => $revision->action === 'statement_added')) continue;
            $archived = $revisions->first(fn ($revision) => $revision->created_at->gte($upload->occurred_at) && !empty($revision->before['attachment_path']));
            $files = $archived ? $file($archived->before, $archived, 'before')
                : ($record->attachment_path ? [['attachment_name' => $record->attachment_name, 'attachment_url' => route($module.'.attachment', $record->id)]] : []);
            $add('statement-'.$upload->id, 'attachment', 'SOA attached', $upload->occurred_at,
                ['name' => $upload->actor_name ?? 'System', 'role' => $upload->actor_role], 'Current statement submitted to Accounting.', $files);
        }
        if ($payment = $record->payment) {
            $add('payment-'.$payment->id, 'payment', 'Payment recorded', $payment->created_at, $payment->recordedBy,
                'PHP '.$payment->amount.' paid on '.$payment->paid_on->format('Y-m-d').'. Reference: '.$payment->reference
                .($payment->notes ? "\n".$payment->notes : ''),
                $payment->proof_name ? [['attachment_name' => $payment->proof_name, 'attachment_url' => route($module.'.payment-proof', $record->id)]] : []);
        }

        // Paginate the combined sequence in SQL so comments and workflow events share page boundaries.
        $comments = $record->comments();
        $table = $comments->getRelated()->getTable();
        $sequence = $comments->reorder()->selectRaw('id as comment_id, created_at as occurred_at')->toBase();
        $sequence->selectRaw('? as event_key, 1 as event_rank', ['comment']);
        foreach ($events as $key => $event) {
            $rank = $event['type'] === 'created' ? 0 : ($event['type'] === 'payment' ? 2 : 1);
            $sequence->unionAll(DB::query()->selectRaw('NULL as comment_id, ? as occurred_at, ? as event_key, ? as event_rank',
                [Carbon::parse($event['occurred_at'])->format('Y-m-d H:i:s'), $key, $rank]));
        }
        $direction = $data['timeline_direction'] ?? 'desc';
        $page = DB::query()->fromSub($sequence, 'timeline')->orderBy('occurred_at', $direction)
            ->orderBy('event_rank', $direction)->orderBy('event_key', $direction)->orderBy('comment_id', $direction)
            ->paginate((int) ($data['timeline_per_page'] ?? 15), ['*'], 'timeline_page');
        $commentModels = $record->comments()->with('author')->whereIn($table.'.id', $page->getCollection()->pluck('comment_id')->filter())->get()->keyBy('id');
        return $page->through(function ($item) use ($events, $commentModels, $record) {
            if ($item->event_key !== 'comment') return $events[$item->event_key];
            $comment = $commentModels->get($item->comment_id);
            $present = RequestComments::present($comment, $record);
            return ['id' => 'comment-'.$comment->id, 'type' => 'comment',
                'title' => $comment->body ? 'Comment added' : 'Attachment added',
                'occurred_at' => $comment->created_at->toIso8601String(), 'actor' => $comment->author,
                'body' => $comment->body, 'attachments' => $comment->attachment_name ? [collect($present)->only(['attachment_url', 'attachment_name', 'attachment_mime', 'attachment_size'])->all()] : [],
                'revision_id' => null];
        });
    }
}
