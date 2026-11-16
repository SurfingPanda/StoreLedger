<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private function records(): Builder
    {
        $sources = [
            ['pos_renewals', 'pos_renewal_payments', 'pos_renewal_id', 'renewal_date', 'utak-pos'],
            ['internet_soas', 'internet_soa_payments', 'internet_soa_id', 'due_date', 'internet-soa'],
            ['billing_soas', 'billing_soa_payments', 'billing_soa_id', 'due_date', null],
        ];
        $union = null;
        foreach ($sources as [$table, $payments, $foreign, $date, $module]) {
            $query = DB::table($table.' as r')->leftJoin($payments.' as p', 'p.'.$foreign, '=', 'r.id')
                ->select(['r.id', 'r.store_name', 'r.amount', 'r.status', 'r.created_at', 'r.voucher_action_at'])
                ->selectRaw('r.'.$date.' as due_date, p.created_at as paid_at');
            if ($module) $query->selectRaw('? as module', [$module]);
            else $query->addSelect('r.module')->whereIn('r.module', ['water', 'electricity', 'rental']);
            $union = $union ? $union->unionAll($query) : $query;
        }
        return DB::query()->fromSub($union, 'requests');
    }

    private function present(object $row): array
    {
        $prefix = ['utak-pos' => 'POS', 'internet-soa' => 'NET', 'water' => 'WTR', 'electricity' => 'ELEC', 'rental' => 'RENT'][$row->module];
        return array_merge((array) $row, [
            'key' => $row->module.'-'.$row->id,
            'record_number' => $prefix.'-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT),
            'detail_url' => route($row->module.'.detail', $row->id),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', 'in:30,90,all'],
            'module' => ['sometimes', 'in:all,utak-pos,internet-soa,water,electricity,rental'],
            'status' => ['sometimes', 'in:all,awaiting_voucher,vouchered,paid,cancelled'],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $base = $this->records();
        if (($data['module'] ?? 'all') !== 'all') $base->where('module', $data['module']);
        if (($data['period'] ?? 'all') !== 'all') {
            $today = now('Asia/Manila')->startOfDay();
            $days = (int) $data['period'];
            $base->whereBetween('due_date', [$today->copy()->subDays($days)->toDateString(), $today->copy()->addDays($days)->toDateString()]);
        }
        $today = now('Asia/Manila')->toDateString();
        $summary = (clone $base)->selectRaw("COUNT(*) as total_count,
            SUM(CASE WHEN status = 'awaiting_voucher' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = 'vouchered' THEN 1 ELSE 0 END) as awaiting_payment_count,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN status IN ('awaiting_voucher','vouchered') THEN amount ELSE 0 END) as outstanding_amount,
            SUM(CASE WHEN status IN ('awaiting_voucher','vouchered') AND due_date < ? THEN 1 ELSE 0 END) as overdue_count,
            SUM(CASE WHEN status IN ('awaiting_voucher','vouchered') AND due_date < ? THEN amount ELSE 0 END) as overdue_amount", [$today, $today])->first();
        $summary = collect((array) $summary)->map(fn ($value, $key) => str_ends_with($key, '_amount') ? number_format((float) ($value ?? 0), 2, '.', '') : (int) $value)->all();
        $breakdown = (clone $base)->where('status', '!=', 'cancelled')->selectRaw('module, COUNT(*) as total_count, SUM(amount) as amount')->groupBy('module')->get();
        $due = (clone $base)->whereIn('status', ['awaiting_voucher', 'vouchered'])->orderBy('due_date')->orderBy('module')->orderBy('id')->limit(5)->get()->map(fn ($row) => $this->present($row));
        $register = clone $base;
        if (($data['status'] ?? 'all') !== 'all') $register->where('status', $data['status']);
        if (!empty($data['search'])) $register->where('store_name', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        $records = $register->orderBy('due_date')->orderBy('module')->orderBy('id')->paginate(15);
        $records->through(fn ($row) => $this->present($row));
        return response()->json(['summary' => $summary, 'breakdown' => $breakdown, 'due' => $due, 'records' => $records, 'updated_at' => now()->toIso8601String(), 'today' => $today]);
    }

    public function activity(): JsonResponse
    {
        $base = $this->records();
        $created = (clone $base)->select('*')->selectRaw('created_at as occurred_at, ? as event', ['Request created']);
        $vouchered = (clone $base)->whereNotNull('voucher_action_at')->select('*')->selectRaw("voucher_action_at as occurred_at, CASE WHEN status = 'cancelled' THEN 'Request cancelled' ELSE 'Voucher processed' END as event");
        $paid = (clone $base)->whereNotNull('paid_at')->select('*')->selectRaw('paid_at as occurred_at, ? as event', ['Payment recorded']);
        $events = DB::query()->fromSub($created->unionAll($vouchered)->unionAll($paid), 'events')->orderByDesc('occurred_at')->orderBy('module')->orderByDesc('id')->limit(10)->get();
        return response()->json(['events' => $events->map(fn ($row) => $this->present($row))]);
    }
}
