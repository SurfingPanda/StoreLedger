<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_billing_accounts', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_billing_accounts', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
