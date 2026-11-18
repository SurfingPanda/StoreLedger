<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CurrentStatement
{
    public static function upload(Request $request, Model $record): array
    {
        abort_unless(SubmissionAccess::allows($request->user()->role, RequestModule::key($record)), 403);
        abort_unless($record->status === 'awaiting_soa', 409, 'This request is not awaiting a statement.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'soa' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('soa');
        $path = $file->store('recurring-soas', 'local');
        abort_unless($path, 500, 'Could not store the SOA. Please try again.');
        try {
            DB::transaction(function () use ($record, $data, $path, $file) {
                DuplicateBills::lock();
                $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_unless($record->status === 'awaiting_soa', 409, 'A statement has already been added to this request.');
                DuplicateBills::assertClear($record);
                $record->amount = $data['amount'];
                $record->attachment_path = $path;
                $record->attachment_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
                $record->status = 'awaiting_voucher';
                $record->workflow_version++;
                $record->save();
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return ['message' => 'Current SOA attached and amount confirmed. The request is now in Accounting’s voucher queue.'];
    }
}
