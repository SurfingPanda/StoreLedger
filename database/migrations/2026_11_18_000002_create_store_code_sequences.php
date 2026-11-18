<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_code_sequences', function (Blueprint $table) {
            $table->string('prefix', 2)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        $last = ['BW' => 0, 'FR' => 0];
        foreach (DB::table('stores')->pluck('code') as $code) {
            if (preg_match('/^(BW|FR)([0-9]+)$/i', $code, $match)) {
                $prefix = strtoupper($match[1]);
                $last[$prefix] = max($last[$prefix], (int) $match[2]);
            }
        }
        foreach ($last as $prefix => $number) {
            DB::table('store_code_sequences')->insert(['prefix' => $prefix, 'last_number' => $number]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_code_sequences');
    }
};
