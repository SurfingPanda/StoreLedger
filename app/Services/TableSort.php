<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableSort
{
    public static function history($query, Request $request, string $date)
    {
        $model = $query->getModel();
        $payment = $model->payment();
        $paidOn = $payment->getRelated()->newQuery()->select('paid_on')
            ->whereColumn($payment->getForeignKeyName(), $model->getTable().'.id')->limit(1);

        return self::apply($query, $request, [
            'id' => 'id', 'date' => $date, 'amount' => 'amount',
            'voucher_action_at' => 'voucher_action_at', 'paid_on' => $paidOn, 'status' => 'status',
        ], 'date', 'desc', 'history_');
    }

    // Column names/expressions are supplied by the application, never by the client.
    public static function apply($query, Request $request, array $columns, string $default, string $direction = 'asc', string $prefix = '', array $ties = ['id'])
    {
        $sortKey = $prefix.'sort';
        $directionKey = $prefix.'direction';
        $data = $request->validate([
            $sortKey => ['sometimes', 'required', Rule::in(array_keys($columns))],
            $directionKey => ['sometimes', 'required', Rule::in(['asc', 'desc'])],
        ]);
        $column = $columns[$data[$sortKey] ?? $default];
        $order = $data[$directionKey] ?? $direction;
        $query->orderBy($column, $order);
        foreach ($ties as $tie) if ($tie !== $column) $query->orderBy($tie, $order);

        return $query;
    }
}
