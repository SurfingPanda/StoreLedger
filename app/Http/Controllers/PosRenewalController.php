<?php

namespace App\Http\Controllers;

use App\Models\PosRenewal;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PosRenewalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = PosRenewal::query();
        if ($request->user()->role === 'accounting') {
            $query->where('status', 'awaiting_voucher');
        }
        if (!empty($data['search'])) {
            $query->where('store_name', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        }
        return response()->json($query->orderBy('renewal_date')->orderBy('id')->paginate(15));
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
