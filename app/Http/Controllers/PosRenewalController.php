<?php

namespace App\Http\Controllers;

use App\Models\PosRenewal;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PosRenewalController extends Controller
{
    public function payment(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        abort_unless($request->user()->role === 'finance', 403);
        abort_unless($posRenewal->status === 'vouchered', 409, 'Only processed vouchers awaiting payment can be paid.');
        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
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
                $record = PosRenewal::whereKey($posRenewal->id)->lockForUpdate()->firstOrFail();
                abort_unless($record->status === 'vouchered', 409, 'This voucher is no longer awaiting payment. Refresh to see the latest details.');
                $payment = $record->payment()->make(collect($data)->except('proof')->all());
                $payment->amount = $record->amount;
                $payment->user_id = $request->user()->id;
                $payment->proof_path = $path;
                $payment->proof_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
                $payment->save();
                $record->status = 'paid';
                $record->save();
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return response()->json(['message' => 'Payment recorded and renewal marked as paid.'], 201);
    }

    public function paymentProof(PosRenewal $posRenewal): StreamedResponse
    {
        $payment = $posRenewal->payment;
        abort_unless($payment && Storage::disk('local')->exists($payment->proof_path), 404);
        return Storage::disk('local')->download($payment->proof_path, $payment->proof_name, [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function details(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        $request->validate([
            'history_page' => ['nullable', 'integer', 'min:1'],
            'comments_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $history = PosRenewal::where('store_name', $posRenewal->store_name)
            ->with(['creator', 'voucherActor', 'payment.recordedBy'])->orderByDesc('renewal_date')->orderByDesc('id')
            ->paginate(10, ['*'], 'history_page');
        $history->through(function ($record) {
            if ($record->payment) {
                $record->payment->setAttribute('proof_url', route('utak-pos.payment-proof', $record));
            }
            return array_merge($record->toArray(), ['detail_url' => route('utak-pos.detail', $record)]);
        });
        $posRenewal->load(['creator', 'voucherActor', 'payment.recordedBy']);
        if ($posRenewal->payment) {
            $posRenewal->payment->setAttribute('proof_url', route('utak-pos.payment-proof', $posRenewal));
        }
        return response()->json([
            'record' => $posRenewal,
            'history' => $history,
            'comments' => $posRenewal->comments()->with('author')->orderByDesc('id')->paginate(20, ['*'], 'comments_page'),
        ]);
    }

    public function comment(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['accounting', 'finance'], true), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $comment = $posRenewal->comments()->make($data);
        $comment->user_id = $request->user()->id;
        $comment->save();
        return response()->json(['message' => 'Comment added.', 'comment' => $comment->load('author')], 201);
    }

    public function voucherAction(Request $request, PosRenewal $posRenewal): JsonResponse
    {
        abort_unless($request->user()->role === 'accounting', 403);
        $data = $request->validate([
            'action' => ['required', 'in:process,cancel'],
            'voucher_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $data['action'] === 'process' ? 'vouchered' : 'cancelled';
        // Only one accountant can resolve a pending renewal, including simultaneous requests.
        $updated = PosRenewal::whereKey($posRenewal->id)->where('status', 'awaiting_voucher')->update([
            'status' => $status,
            'voucher_action_by' => $request->user()->id,
            'voucher_action_at' => now(),
            'voucher_reference' => $status === 'vouchered' ? ($data['voucher_reference'] ?? null) : null,
        ]);
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
            'status' => ['sometimes', 'required', 'in:all,awaiting_voucher,processed,vouchered,paid,cancelled'],
            'sort' => ['sometimes', 'required', 'in:id,store_name,renewal_date,amount,status,created_at'],
            'direction' => ['sometimes', 'required', 'in:asc,desc'],
        ]);
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
        $sort = $data['sort'] ?? 'renewal_date';
        $direction = $data['direction'] ?? 'asc';
        if ($sort === 'status') {
            $query->orderByRaw("CASE status WHEN 'awaiting_voucher' THEN 1 WHEN 'vouchered' THEN 2 WHEN 'paid' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END ".$direction);
        } else {
            $query->orderBy($sort, $direction);
        }
        if ($sort !== 'id') $query->orderBy('id', $direction);
        $records = $query->paginate(15);
        $records->through(fn ($record) => array_merge($record->toArray(), ['detail_url' => route('utak-pos.detail', $record)]));
        return response()->json($records);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:150'],
            'renewal_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ]);
        $renewal = new PosRenewal($data);
        $renewal->status = 'awaiting_voucher';
        $renewal->created_by = $request->user()->id;
        $renewal->save();

        return response()->json(['message' => 'Renewal added and queued for Accounting voucher processing.', 'record' => $renewal], 201);
    }
}
