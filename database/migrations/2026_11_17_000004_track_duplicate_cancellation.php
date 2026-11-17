<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['internet_soas', 'billing_soas', 'pos_renewals'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('duplicate_cancelled_at')->nullable());
        }
    }
    public function down(): void
    {
        foreach (['internet_soas', 'billing_soas', 'pos_renewals'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('duplicate_cancelled_at'));
        }
    }
};
