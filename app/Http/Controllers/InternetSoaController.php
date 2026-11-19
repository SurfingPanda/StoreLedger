<?php

namespace App\Http\Controllers;

use App\Models\InternetSoa;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use App\Services\WorkflowNotifications;
use App\Services\RecurringBilling;
use App\Services\CurrentStatement;
use App\Services\RequestRecordFilters;
use App\Services\DuplicateBills;
use App\Services\DirectoryLinks;
use App\Services\TableSort;
use App\Services\RequestRevisions;
use App\Services\RequestComments;

class InternetSoaController extends Controller
{
    public function returnRequest(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(RequestRevisions::returnRequest($request, $internetSoa));
    }

    public function resubmit(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(RequestRevisions::resubmit($request, $internetSoa));
    }

    public function reopen(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(RequestRevisions::reopen($request, $internetSoa));
    }

    public function cancelDuplicate(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(DuplicateBills::cancel($request, $internetSoa));
    }

    public function billingMetadata(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(DuplicateBills::metadata($request, $internetSoa));
    }

    public function recurrence(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(RecurringBilling::configure($request, $internetSoa));
    }

    public function statement(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(CurrentStatement::upload($request, $internetSoa));
    }

    public function payment(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        abort_unless($request->user()->role === 'finance', 403);
        abort_unless($internetSoa->status === 'vouchered', 409, 'Only processed vouchers awaiting payment can be paid.');
        $data = $request->validate([
            'workflow_version' => ['nullable', 'integer', 'min:1'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'method' => ['required', 'in:bank_transfer,cash,check,e_wallet,other'],
            'reference' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('proof');
        $path = $file->store('internet-payment-proofs', 'local');
        abort_unless($path, 500, 'Could not save the proof of payment. Please try again.');
        try {
            DB::transaction(function () use ($request, $internetSoa, $data, $file, $path) {
                DuplicateBills::lock();
                $record = InternetSoa::whereKey($internetSoa->id)->lockForUpdate()->firstOrFail();
                abort_unless($record->status === 'vouchered', 409, 'This voucher is no longer awaiting payment. Refresh to see the latest details.');
                RequestRevisions::checkVersion($request, $record);
                DuplicateBills::assertClear($record);
                $payment = $record->payment()->make(collect($data)->except('proof')->all());
                $payment->amount = $record->amount;
                $payment->user_id = $request->user()->id;
                $payment->proof_path = $path;
                $payment->proof_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
                $payment->save();
                $record->status = 'paid';
                $record->workflow_version++;
                $record->save();
                WorkflowNotifications::paymentProcessed($record);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return response()->json(['message' => 'Payment recorded and SOA request marked as paid.'], 201);
    }

    public function paymentProof(Request $request, InternetSoa $internetSoa): StreamedResponse
    {
        $payment = $internetSoa->payment;
        abort_unless($payment && Storage::disk('local')->exists($payment->proof_path), 404);
        return \App\Services\PrivateAttachments::respond($request, $payment->proof_path, $payment->proof_name);
    }

    public function details(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        $request->validate([
            'history_page' => ['nullable', 'integer', 'min:1'],
            'revisions_page' => ['nullable', 'integer', 'min:1'],
            'comments_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $history = TableSort::history(DirectoryLinks::history($internetSoa)->with(['creator', 'voucherActor', 'payment.recordedBy']), $request, 'due_date')
            ->paginate($this->pageSize($request, 10, 'history_per_page'), ['*'], 'history_page');
        $history->through(function ($record) {
            if ($record->payment) {
                $record->payment->setAttribute('proof_url', route('internet-soa.payment-proof', $record));
            }
            return array_merge($record->toArray(), ['duplicate_count' => count(DuplicateBills::matches($record)), 'detail_url' => route('internet-soa.detail', $record), 'attachment_url' => route('internet-soa.attachment', $record)]);
        });
        $internetSoa->setAttribute('recurrence', RecurringBilling::scheduleFor($internetSoa));
        $internetSoa->load(['creator', 'voucherActor', 'payment.recordedBy', 'store.assignedRso', 'supplier', 'billingAccount']);
        if ($internetSoa->payment) {
            $internetSoa->payment->setAttribute('proof_url', route('internet-soa.payment-proof', $internetSoa));
        }
        return response()->json([
            'record' => array_merge($internetSoa->toArray(), ['attachment_url' => route('internet-soa.attachment', $internetSoa)]),
            'timeline' => \App\Services\RequestTimeline::history($internetSoa, $request),
            'revisions' => RequestRevisions::history($internetSoa, $request),
            'revision_permissions' => RequestRevisions::permissions($request, $internetSoa),
            'duplicates' => DuplicateBills::matches($internetSoa),
            'history' => $history,
            'comments' => $internetSoa->comments()->with('author')->orderByDesc('id')->paginate(20, ['*'], 'comments_page')->through(fn ($comment) => RequestComments::present($comment, $internetSoa)),
        ]);
    }

    public function comment(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        return response()->json(RequestComments::create($request, $internetSoa), 201);
    }

    public function commentAttachment(Request $request, InternetSoa $internetSoa, int $commentId): StreamedResponse
    {
        return RequestComments::attachment($request, $internetSoa, $commentId);
    }

    public function voucherAction(Request $request, InternetSoa $internetSoa): JsonResponse
    {
        abort_unless($request->user()->role === 'accounting', 403);
        $data = $request->validate([
            'workflow_version' => ['nullable', 'integer', 'min:1'],
            'action' => ['required', 'in:process,cancel'],
            'voucher_reference' => ['required_if:action,process', 'nullable', 'string', 'max:100'],
            'cancellation_reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:5000'],
        ]);
        $status = $data['action'] === 'process' ? 'vouchered' : 'cancelled';
        // Only one accountant can resolve a pending SOA request, including simultaneous requests.
        $updated = DB::transaction(function () use ($request, $internetSoa, $data, $status) {
            DuplicateBills::lock();
            $current = $internetSoa->fresh();
            RequestRevisions::checkVersion($request, $current);
            if ($status === 'vouchered') DuplicateBills::assertClear($current);
            $updated = InternetSoa::whereKey($internetSoa->id)->where('status', 'awaiting_voucher')->update([
            'workflow_version' => DB::raw('workflow_version + 1'),
            'status' => $status,
            'voucher_action_by' => $request->user()->id,
            'voucher_action_at' => now(),
            'voucher_reference' => $status === 'vouchered' ? ($data['voucher_reference'] ?? null) : null,
            'cancellation_reason' => $status === 'cancelled' ? $data['cancellation_reason'] : null,
        ]);
            if ($updated) \App\Services\SecurityAudit::record('billing', $status === 'vouchered' ? 'voucher.processed' : 'request.cancelled',
                $internetSoa->fresh(), \App\Services\SecurityAudit::clean($current->getAttributes()), \App\Services\SecurityAudit::clean($internetSoa->fresh()->getAttributes()));
            if ($updated && $status === 'vouchered') WorkflowNotifications::voucherProcessed($internetSoa->fresh());
            return $updated;
        });
        if (!$updated) {
            return response()->json(['message' => 'This SOA request has already been processed or cancelled. Refresh the queue to see the latest records.'], 409);
        }
        return response()->json([
            'message' => $status === 'vouchered' ? 'Voucher processed and sent to Finance for payment.' : 'SOA request cancelled.',
            'record' => $internetSoa->fresh(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', 'in:all,awaiting_soa,awaiting_voucher,needs_revision,processed,vouchered,paid,cancelled'],
            'sort' => ['sometimes', 'required', 'in:id,store_name,due_date,amount,status,created_at,attachment_name'],
            'direction' => ['sometimes', 'required', 'in:asc,desc'],
        ]);
        $data = array_merge($data, RequestRecordFilters::validate($request));
        $query = InternetSoa::query();
        $status = $data['status'] ?? match ($request->user()->role) {
            'accounting' => 'awaiting_voucher',
            'finance' => 'vouchered',
            default => 'all',
        };
        if ($status === 'processed') {
            $query->whereIn('status', ['vouchered', 'paid']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if (!empty($data['search'])) {
            $query->where('store_name', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        }
        RequestRecordFilters::apply($query, $data, 'due_date');
        $sort = $data['sort'] ?? 'due_date';
        $direction = $data['direction'] ?? 'asc';
        if ($sort === 'status') {
            $query->orderByRaw("CASE status WHEN 'needs_revision' THEN -1 WHEN 'awaiting_soa' THEN 0 WHEN 'awaiting_voucher' THEN 1 WHEN 'vouchered' THEN 2 WHEN 'paid' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END ".$direction);
        } else {
            $query->orderBy($sort, $direction);
        }
        if ($sort !== 'id') $query->orderBy('id', $direction);
        $records = $query->paginate($this->pageSize($request));
        $records->through(fn ($record) => array_merge($record->toArray(), ['duplicate_count' => count(DuplicateBills::matches($record)), 'detail_url' => route('internet-soa.detail', $record), 'attachment_url' => route('internet-soa.attachment', $record)]));
        $totals = InternetSoa::selectRaw('status, COUNT(*) as total, SUM(amount) as amount')->groupBy('status')->get()->keyBy('status');
        return response()->json(array_merge($records->toArray(), ['summary' => [
            'needs_revision_count' => (int) ($totals->get('needs_revision')?->total ?? 0),
            'awaiting_soa_count' => (int) ($totals->get('awaiting_soa')?->total ?? 0),
            'pending_count' => (int) ($totals->get('awaiting_voucher')?->total ?? 0),
            'pending_amount' => number_format((float) ($totals->get('awaiting_voucher')?->amount ?? 0), 2, '.', ''),
            'awaiting_payment_count' => (int) ($totals->get('vouchered')?->total ?? 0),
            'awaiting_payment_amount' => number_format((float) ($totals->get('vouchered')?->amount ?? 0), 2, '.', ''),
            'paid_count' => (int) ($totals->get('paid')?->total ?? 0),
            'paid_amount' => number_format((float) ($totals->get('paid')?->amount ?? 0), 2, '.', ''),
            'overdue_pending_count' => InternetSoa::where('status', 'awaiting_voucher')->where('due_date', '<', now('Asia/Manila')->toDateString())->count(),
        ]]));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(array_merge(DirectoryLinks::rules(), DuplicateBills::rules(), [
            'store_name' => ['required_without:billing_account_id', 'nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'soa' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]));
        $file = $request->file('soa');
        $path = $file->store('internet-soas', 'local');
        abort_unless($path, 500, 'Could not save the SOA attachment. Please try again.');
        try {
            $record = new InternetSoa(collect($data)->except('soa')->all());
            $record->attachment_path = $path;
            $record->attachment_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
            $record->status = 'awaiting_voucher';
            $record->created_by = $request->user()->id;
            DB::transaction(function () use ($record) {
                DuplicateBills::lock();
                DirectoryLinks::assign($record);
                DuplicateBills::assertClear($record);
                $record->save();
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return response()->json(['message' => 'Internet SOA saved and queued for Accounting voucher processing.'], 201);
    }

    public function attachment(Request $request, InternetSoa $internetSoa): StreamedResponse
    {
        abort_unless($internetSoa->attachment_path && Storage::disk('local')->exists($internetSoa->attachment_path), 404);
        return \App\Services\PrivateAttachments::respond($request, $internetSoa->attachment_path, $internetSoa->attachment_name);
    }
}
