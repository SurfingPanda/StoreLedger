<?php

namespace App\Services;

use App\Models\BillingSoa;
use App\Models\InternetSoa;
use App\Models\PosRenewal;
use Illuminate\Database\Eloquent\Model;

class RequestModule
{
    public const LABELS = ['utak-pos' => 'UTAK POS renewal', 'internet-soa' => 'Internet SOA', 'water' => 'Water SOA', 'electricity' => 'Electricity SOA', 'rental' => 'Rental SOA'];

    public static function key(Model $record): string
    {
        return match (true) { $record instanceof PosRenewal => 'utak-pos', $record instanceof InternetSoa => 'internet-soa', default => $record->module };
    }

    public static function query(string $module)
    {
        return match ($module) {
            'utak-pos' => PosRenewal::query(), 'internet-soa' => InternetSoa::query(),
            'water', 'electricity', 'rental' => BillingSoa::where('module', $module),
            default => throw new \InvalidArgumentException('Unknown request module'),
        };
    }

    public static function dateField(string $module): string
    {
        return $module === 'utak-pos' ? 'renewal_date' : 'due_date';
    }
}
