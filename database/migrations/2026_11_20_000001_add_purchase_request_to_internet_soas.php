<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_soas', function (Blueprint $table) {
            $table->string('purchase_request_path')->nullable();
            $table->string('purchase_request_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('internet_soas', function (Blueprint $table) {
            $table->dropColumn(['purchase_request_path', 'purchase_request_name']);
        });
    }
};
