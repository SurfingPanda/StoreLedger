<?php

namespace App\Http\Controllers;

use App\Models\PosRenewal;
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

class PosRenewalController extends Controller
{
    public function returnRequest(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        return response()->json(RequestRevisions::returnRequest($request, $posRenewal));
    }

    public function resubmit(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        return response()->json(RequestRevisions::resubmit($request, $posRenewal));
    }

    public function reopen(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        return response()->json(RequestRevisions::reopen($request, $posRenewal));
    }

    public function cancelDuplicate(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        return response()->json(DuplicateBills::cancel($request, $posRenewal));
    }

    public function billingMetadata(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        return response()->json(DuplicateBills::metadata($request, $posRenewal));
    }

    public function recurrence(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        return response()->json(RecurringBilling::configure($request, $posRenewal));
    }

    public function payment(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        abort_unless($request->user()->role === 'finance', 403);
        abort_unless($posRenewal->status === 'vouchered', 409, 'Only processed vouchers awaiting payment can be paid.');
        $data = $request->validate([
            'workflow_version' => ['nullable', 'integer', 'min:1'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'method' => ['required', 'in:bank_transfer,cash,check,e_wallet,other'],
            'reference' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('proof');
        $path = $file->store('pos-payment-proofs', 'local');
        abort_unless($path, 500, 'Could not save the proof of payment. Please try again.');
        try {
            DB::transaction(function () use ($request, $posRenewal, $data, $file, $path) {
                DuplicateBills::lock();
                $record = PosRenewal::whereKey($posRenewal->id)->lockForUpdate()->firstOrFail();
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
        return response()->json(['message' => 'Payment recorded and renewal marked as paid.'], 201);
    }

    public function paymentProof(Request $request, PosRenewal $posRenewal): StreamedResponse
    {
        $payment = $posRenewal->payment;
        abort_unless($payment && Storage::disk('local')->exists($payment->proof_path), 404);
        return \App\Services\PrivateAttachments::respond($request, $payment->proof_path, $payment->proof_name);
    }

    public function details(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        $request->validate([
            'history_page' => ['nullable', 'integer', 'min:1'],
            'revisions_page' => ['nullable', 'integer', 'min:1'],
            'comments_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $history = TableSort::history(DirectoryLinks::history($posRenewal)->with(['creator', 'voucherActor', 'payment.recordedBy']), $request, 'renewal_date')
            ->paginate($this->pageSize($request, 10, 'history_per_page'), ['*'], 'history_page');
        $history->through(function ($record) {
            if ($record->payment) {
                $record->payment->setAttribute('proof_url', route('utak-pos.payment-proof', $record));
            }
            return array_merge($record->toArray(), ['duplicate_count' => count(DuplicateBills::matches($record)), 'detail_url' => route('utak-pos.detail', $record)]);
        });
        $posRenewal->setAttribute('recurrence', RecurringBilling::scheduleFor($posRenewal));
        $posRenewal->load(['creator', 'voucherActor', 'payment.recordedBy', 'store.assignedRso', 'supplier', 'billingAccount']);
        if ($posRenewal->payment) {
            $posRenewal->payment->setAttribute('proof_url', route('utak-pos.payment-proof', $posRenewal));
        }
        return response()->json([
            'record' => $posRenewal,
            'revisions' => RequestRevisions::history($posRenewal, $request),
            'revision_permissions' => RequestRevisions::permissions($request, $posRenewal),
            'duplicates' => DuplicateBills::matches($posRenewal),
            'history' => $history,
            'comments' => $posRenewal->comments()->with('author')->orderByDesc('id')->paginate(20, ['*'], 'comments_page')->through(fn ($comment) => RequestComments::present($comment, $posRenewal)),
        ]);
    }

    public function comment(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        return response()->json(RequestComments::create($request, $posRenewal), 201);
    }

    public function commentAttachment(Request $request, PosRenewal $posRenewal, int $commentId): StreamedResponse
    {
        return RequestComments::attachment($request, $posRenewal, $commentId);
    }

    public function voucherAction(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        abort_unless($request->user()->role === 'accounting', 403);
        $data = $request->validate([
            'workflow_version' => ['nullable', 'integer', 'min:1'],
            'action' => ['required', 'in:process,cancel'],
            'voucher_reference' => ['required_if:action,process', 'nullable', 'string', 'max:100'],
            'cancellation_reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:5000'],
        ]);
        $status = $data['action'] === 'process' ? 'vouchered' : 'cancelled';
        // Only one accountant can resolve a pending renewal, including simultaneous requests.
        $updated = DB::transaction(function () use ($request, $posRenewal, $data, $status) {
            DuplicateBills::lock();
            $current = $posRenewal->fresh();
            RequestRevisions::checkVersion($request, $current);
            if ($status === 'vouchered') DuplicateBills::assertClear($current);
            $updated = PosRenewal::whereKey($posRenewal->id)->where('status', 'awaiting_voucher')->update([
            'workflow_version' => DB::raw('workflow_version + 1'),
            'status' => $status,
            'voucher_action_by' => $request->user()->id,
            'voucher_action_at' => now(),
            'voucher_reference' => $status === 'vouchered' ? ($data['voucher_reference'] ?? null) : null,
            'cancellation_reason' => $status === 'cancelled' ? $data['cancellation_reason'] : null,
        ]);
            if ($updated) \App\Services\SecurityAudit::record('billing', $status === 'vouchered' ? 'voucher.processed' : 'request.cancelled',
                $posRenewal->fresh(), \App\Services\SecurityAudit::clean($current->getAttributes()), \App\Services\SecurityAudit::clean($posRenewal->fresh()->getAttributes()));
            if ($updated && $status === 'vouchered') WorkflowNotifications::voucherProcessed($posRenewal->fresh());
            return $updated;
        });
        if (!$updated) {
            return response()->json(['message' => 'This renewal has already been processed or cancelled. Refresh the queue to see the latest records.'], 409);
        }
        return response()->json([
            'message' => $status === 'vouchered' ? 'Voucher processed and sent to Finance for payment.' : 'Renewal cancelled.',
            'record' => $posRenewal->fresh(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', 'in:all,awaiting_soa,awaiting_voucher,needs_revision,processed,vouchered,paid,cancelled'],
            'sort' => ['sometimes', 'required', 'in:id,store_name,renewal_date,amount,status,created_at'],
            'direction' => ['sometimes', 'required', 'in:asc,desc'],
        ]);
        $data = array_merge($data, RequestRecordFilters::validate($request));
        $query = PosRenewal::query();
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
        RequestRecordFilters::apply($query, $data, 'renewal_date');
        $sort = $data['sort'] ?? 'renewal_date';
        $direction = $data['direction'] ?? 'asc';
        if ($sort === 'status') {
            $query->orderByRaw("CASE status WHEN 'needs_revision' THEN -1 WHEN 'awaiting_soa' THEN 0 WHEN 'awaiting_voucher' THEN 1 WHEN 'vouchered' THEN 2 WHEN 'paid' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END ".$direction);
        } else {
            $query->orderBy($sort, $direction);
        }
        if ($sort !== 'id') $query->orderBy('id', $direction);
        $records = $query->paginate($this->pageSize($request));
        $records->through(fn ($record) => array_merge($record->toArray(), ['duplicate_count' => count(DuplicateBills::matches($record)), 'detail_url' => route('utak-pos.detail', $record)]));
        $totals = PosRenewal::selectRaw('status, COUNT(*) as total, SUM(amount) as amount')->groupBy('status')->get()->keyBy('status');
        return response()->json(array_merge($records->toArray(), ['summary' => [
            'needs_revision_count' => (int) ($totals->get('needs_revision')?->total ?? 0),
            'awaiting_soa_count' => (int) ($totals->get('awaiting_soa')?->total ?? 0),
            'pending_count' => (int) ($totals->get('awaiting_voucher')?->total ?? 0),
            'pending_amount' => number_format((float) ($totals->get('awaiting_voucher')?->amount ?? 0), 2, '.', ''),
            'awaiting_payment_count' => (int) ($totals->get('vouchered')?->total ?? 0),
            'awaiting_payment_amount' => number_format((float) ($totals->get('vouchered')?->amount ?? 0), 2, '.', ''),
            'paid_count' => (int) ($totals->get('paid')?->total ?? 0),
            'paid_amount' => number_format((float) ($totals->get('paid')?->amount ?? 0), 2, '.', ''),
            'overdue_pending_count' => PosRenewal::where('status', 'awaiting_voucher')->where('renewal_date', '<', now('Asia/Manila')->toDateString())->count(),
        ]]));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(array_merge(DirectoryLinks::rules(), DuplicateBills::rules(), [
            'store_name' => ['required_without:billing_account_id', 'nullable', 'string', 'max:150'],
            'renewal_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ]));
        $renewal = new PosRenewal($data);
        $renewal->status = 'awaiting_voucher';
        $renewal->created_by = $request->user()->id;
        DB::transaction(function () use ($renewal) {
            DuplicateBills::lock();
            DirectoryLinks::assign($renewal);
            DuplicateBills::assertClear($renewal);
            $renewal->save();
        });

        return response()->json(['message' => 'Renewal added and queued for Accounting voucher processing.', 'record' => $renewal], 201);
    }
}
