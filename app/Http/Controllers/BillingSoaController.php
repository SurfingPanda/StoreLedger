<?php

namespace App\Http\Controllers;

use App\Models\BillingSoa;
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
use App\Services\RequestRevisions;

class BillingSoaController extends Controller
{
    public function returnRequest(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        return response()->json(RequestRevisions::returnRequest($request, $billingSoa));
    }

    public function resubmit(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        return response()->json(RequestRevisions::resubmit($request, $billingSoa));
    }

    public function reopen(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        return response()->json(RequestRevisions::reopen($request, $billingSoa));
    }

    public function cancelDuplicate(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        return response()->json(DuplicateBills::cancel($request, $billingSoa));
    }

    public function billingMetadata(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        return response()->json(DuplicateBills::metadata($request, $billingSoa));
    }

    public function recurrence(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        return response()->json(RecurringBilling::configure($request, $billingSoa));
    }

    public function statement(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        return response()->json(CurrentStatement::upload($request, $billingSoa));
    }

    public function payment(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        abort_unless($request->user()->role === 'finance', 403);
        abort_unless($billingSoa->status === 'vouchered', 409, 'Only processed vouchers awaiting payment can be paid.');
        $data = $request->validate([
            'workflow_version' => ['nullable', 'integer', 'min:1'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', 'in:bank_transfer,cash,check,e_wallet,other'],
            'reference' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('proof');
        $path = $file->store('billing-payment-proofs', 'local');
        abort_unless($path, 500, 'Could not save the proof of payment. Please try again.');
        try {
            DB::transaction(function () use ($request, $billingSoa, $data, $file, $path) {
                DuplicateBills::lock();
                $record = BillingSoa::where('module', $request->route('module'))->whereKey($billingSoa->id)->lockForUpdate()->firstOrFail();
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

    public function paymentProof(Request $request, BillingSoa $billingSoa): StreamedResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        $payment = $billingSoa->payment;
        abort_unless($payment && Storage::disk('local')->exists($payment->proof_path), 404);
        return Storage::disk('local')->download($payment->proof_path, $payment->proof_name, [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function details(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        $request->validate([
            'history_page' => ['nullable', 'integer', 'min:1'],
            'revisions_page' => ['nullable', 'integer', 'min:1'],
            'comments_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $history = DirectoryLinks::history($billingSoa)
            ->with(['creator', 'voucherActor', 'payment.recordedBy'])->orderByDesc('due_date')->orderByDesc('id')
            ->paginate(10, ['*'], 'history_page');
        $history->through(function ($record) use ($request) {
            if ($record->payment) {
                $record->payment->setAttribute('proof_url', route($request->route('module').'.payment-proof', $record));
            }
            return array_merge($record->toArray(), ['duplicate_count' => count(DuplicateBills::matches($record)), 'detail_url' => route($request->route('module').'.detail', $record), 'attachment_url' => route($request->route('module').'.attachment', $record)]);
        });
        $billingSoa->setAttribute('recurrence', RecurringBilling::scheduleFor($billingSoa));
        $billingSoa->load(['creator', 'voucherActor', 'payment.recordedBy', 'store.assignedRso', 'supplier', 'billingAccount']);
        if ($billingSoa->payment) {
            $billingSoa->payment->setAttribute('proof_url', route($request->route('module').'.payment-proof', $billingSoa));
        }
        return response()->json([
            'record' => array_merge($billingSoa->toArray(), ['attachment_url' => route($request->route('module').'.attachment', $billingSoa)]),
            'revisions' => RequestRevisions::history($billingSoa),
            'revision_permissions' => RequestRevisions::permissions($request, $billingSoa),
            'duplicates' => DuplicateBills::matches($billingSoa),
            'history' => $history,
            'comments' => $billingSoa->comments()->with('author')->orderByDesc('id')->paginate(20, ['*'], 'comments_page'),
        ]);
    }

    public function comment(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        abort_unless(in_array($request->user()->role, ['accounting', 'finance'], true), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $comment = $billingSoa->comments()->make($data);
        $comment->user_id = $request->user()->id;
        $comment->save();
        return response()->json(['message' => 'Comment added.', 'comment' => $comment->load('author')], 201);
    }

    public function voucherAction(Request $request, BillingSoa $billingSoa): JsonResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        abort_unless($request->user()->role === 'accounting', 403);
        $data = $request->validate([
            'workflow_version' => ['nullable', 'integer', 'min:1'],
            'action' => ['required', 'in:process,cancel'],
            'voucher_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $data['action'] === 'process' ? 'vouchered' : 'cancelled';
        // Only one accountant can resolve a pending SOA request, including simultaneous requests.
        $updated = DB::transaction(function () use ($request, $billingSoa, $data, $status) {
            DuplicateBills::lock();
            $current = $billingSoa->fresh();
            RequestRevisions::checkVersion($request, $current);
            if ($status === 'vouchered') DuplicateBills::assertClear($current);
            $updated = BillingSoa::where('module', $request->route('module'))->whereKey($billingSoa->id)->where('status', 'awaiting_voucher')->update([
            'workflow_version' => DB::raw('workflow_version + 1'),
            'status' => $status,
            'voucher_action_by' => $request->user()->id,
            'voucher_action_at' => now(),
            'voucher_reference' => $status === 'vouchered' ? ($data['voucher_reference'] ?? null) : null,
        ]);
            if ($updated) \App\Services\SecurityAudit::record('billing', $status === 'vouchered' ? 'voucher.processed' : 'request.cancelled',
                $billingSoa->fresh(), \App\Services\SecurityAudit::clean($current->getAttributes()), \App\Services\SecurityAudit::clean($billingSoa->fresh()->getAttributes()));
            if ($updated && $status === 'vouchered') WorkflowNotifications::voucherProcessed($billingSoa->fresh());
            return $updated;
        });
        if (!$updated) {
            return response()->json(['message' => 'This SOA request has already been processed or cancelled. Refresh the queue to see the latest records.'], 409);
        }
        return response()->json([
            'message' => $status === 'vouchered' ? 'Voucher processed and sent to Finance for payment.' : 'SOA request cancelled.',
            'record' => $billingSoa->fresh(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', 'in:all,awaiting_soa,awaiting_voucher,needs_revision,processed,vouchered,paid,cancelled'],
            'sort' => ['sometimes', 'required', 'in:id,store_name,due_date,amount,status,created_at'],
            'direction' => ['sometimes', 'required', 'in:asc,desc'],
        ]);
        $data = array_merge($data, RequestRecordFilters::validate($request));
        $query = BillingSoa::where('module', $request->route('module'));
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
        $records = $query->paginate(15);
        $records->through(fn ($record) => array_merge($record->toArray(), ['duplicate_count' => count(DuplicateBills::matches($record)), 'detail_url' => route($request->route('module').'.detail', $record), 'attachment_url' => route($request->route('module').'.attachment', $record)]));
        $totals = BillingSoa::where('module', $request->route('module'))->selectRaw('status, COUNT(*) as total, SUM(amount) as amount')->groupBy('status')->get()->keyBy('status');
        return response()->json(array_merge($records->toArray(), ['summary' => [
            'needs_revision_count' => (int) ($totals->get('needs_revision')?->total ?? 0),
            'awaiting_soa_count' => (int) ($totals->get('awaiting_soa')?->total ?? 0),
            'pending_count' => (int) ($totals->get('awaiting_voucher')?->total ?? 0),
            'pending_amount' => number_format((float) ($totals->get('awaiting_voucher')?->amount ?? 0), 2, '.', ''),
            'awaiting_payment_count' => (int) ($totals->get('vouchered')?->total ?? 0),
            'awaiting_payment_amount' => number_format((float) ($totals->get('vouchered')?->amount ?? 0), 2, '.', ''),
            'paid_count' => (int) ($totals->get('paid')?->total ?? 0),
            'paid_amount' => number_format((float) ($totals->get('paid')?->amount ?? 0), 2, '.', ''),
            'overdue_pending_count' => BillingSoa::where('module', $request->route('module'))->where('status', 'awaiting_voucher')->where('due_date', '<', now('Asia/Manila')->toDateString())->count(),
        ]]));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(\App\Services\SubmissionAccess::allows($request->user()->role, $request->route('module')), 403);
        $data = $request->validate(array_merge(DirectoryLinks::rules(), DuplicateBills::rules(), [
            'store_name' => ['required_without:billing_account_id', 'nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'soa' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]));
        $file = $request->file('soa');
        $path = $file->store('billing-soas/'.$request->route('module'), 'local');
        abort_unless($path, 500, 'Could not save the SOA attachment. Please try again.');
        try {
            $record = new BillingSoa(collect($data)->except('soa')->all());
            $record->attachment_path = $path;
            $record->attachment_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
            $record->module = $request->route('module');
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
        return response()->json(['message' => ucfirst($request->route('module')).' SOA saved and queued for Accounting voucher processing.'], 201);
    }

    public function attachment(Request $request, BillingSoa $billingSoa): StreamedResponse
    {
        abort_unless($billingSoa->module === $request->route('module'), 404);
        abort_unless($billingSoa->attachment_path && Storage::disk('local')->exists($billingSoa->attachment_path), 404);
        return Storage::disk('local')->download($billingSoa->attachment_path, $billingSoa->attachment_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
