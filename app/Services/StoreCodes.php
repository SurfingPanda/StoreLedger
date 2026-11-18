<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\DB;

class StoreCodes
{
    public static function preview(string $ownership): string
    {
        $prefix = $ownership === 'corporate_owned' ? 'BW' : 'FR';
        $number = DB::table('store_code_sequences')->where('prefix', $prefix)->value('last_number');

        return self::available($prefix, (int) $number);
    }

    private static function available(string $prefix, int $number): string
    {
        do {
            $code = $prefix.str_pad((string) ++$number, 4, '0', STR_PAD_LEFT);
        } while (Store::where('code', $code)->exists());

        return $code;
    }

    // Called within the directory write transaction, after the shared write lock.
    public static function next(string $ownership): string
    {
        $prefix = $ownership === 'corporate_owned' ? 'BW' : 'FR';
        $sequence = DB::table('store_code_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
        $code = self::available($prefix, (int) $sequence->last_number);
        DB::table('store_code_sequences')->where('prefix', $prefix)->update(['last_number' => (int) substr($code, 2)]);

        return $code;
    }
}
