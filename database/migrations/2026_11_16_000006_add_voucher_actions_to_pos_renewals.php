<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_renewals', function (Blueprint $table) {
            $table->foreignId('voucher_action_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voucher_action_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_renewals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_action_by');
            $table->dropColumn('voucher_action_at');
        });
    }
};
