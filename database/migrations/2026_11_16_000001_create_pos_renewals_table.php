<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('it');
        });
        // All accounts provisioned before roles existed were workspace admins.
        DB::table('users')->update(['role' => 'admin']);
        Schema::create('pos_renewals', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 150);
            $table->date('renewal_date')->index();
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('awaiting_voucher')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('pos_renewals');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
