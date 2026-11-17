<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequestRecordFilters
{
    public static function validate(Request $request): array
    {
        return $request->validate([
            'period' => ['sometimes', 'in:all,overdue,next7,next30,this_month'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'amount_min' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'amount_max' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99', Rule::when($request->filled('amount_min'), 'gte:amount_min')],
        ]);
    }

    public static function apply(Builder $query, array $data, string $dateField): void
    {
        $today = now('Asia/Manila')->startOfDay();
        switch ($data['period'] ?? 'all') {
            case 'overdue':
                $query->where($dateField, '<', $today->toDateString())->whereIn('status', ['awaiting_soa', 'awaiting_voucher', 'needs_revision', 'vouchered']);
                break;
            case 'next7':
            case 'next30':
                $days = $data['period'] === 'next7' ? 7 : 30;
                $query->whereBetween($dateField, [$today->toDateString(), $today->copy()->addDays($days)->toDateString()]);
                break;
            case 'this_month':
                $query->whereBetween($dateField, [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()]);
                break;
        }
        if (!empty($data['date_from'])) $query->where($dateField, '>=', $data['date_from']);
        if (!empty($data['date_to'])) $query->where($dateField, '<=', $data['date_to']);
        if (isset($data['amount_min'])) $query->where('amount', '>=', $data['amount_min']);
        if (isset($data['amount_max'])) $query->where('amount', '<=', $data['amount_max']);
    }
}
