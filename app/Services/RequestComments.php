<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RequestComments
{
    public static function create(Request $request, Model $record): array
    {
        abort_unless(in_array($request->user()->role, ['accounting', 'finance'], true), 403);
        $data = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt', 'max:10240'],
        ]);
        $path = null;
        try {
            $comment = $record->comments()->make(['body' => $data['body'] ?? '']);
            $comment->user_id = $request->user()->id;
            if ($file = $request->file('attachment')) {
                $path = $file->store('comment-attachments', 'local');
                abort_unless($path, 500, 'Could not save the attachment. Please try again.');
                $comment->attachment_path = $path;
                $comment->attachment_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
                $comment->attachment_mime = $file->getMimeType();
                $comment->attachment_size = $file->getSize();
            }
            DB::transaction(fn () => $comment->save());
        } catch (Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        return ['message' => 'Comment added.', 'comment' => self::present($comment->load('author'), $record)];
    }

    public static function present(Model $comment, Model $record): array
    {
        return array_merge($comment->toArray(), ['attachment_url' => $comment->attachment_path
            ? route(RequestModule::key($record).'.comment-attachment', [$record->id, $comment->id]) : null]);
    }

    public static function attachment(Request $request, Model $record, int $commentId)
    {
        $comment = $record->comments()->findOrFail($commentId);
        abort_unless($comment->attachment_path, 404);
        return PrivateAttachments::respond($request, $comment->attachment_path, $comment->attachment_name);
    }
}
