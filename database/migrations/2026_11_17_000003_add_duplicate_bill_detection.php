<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['internet_soas', 'billing_soas', 'pos_renewals'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('provider', 150)->nullable();
                $table->string('billing_period', 7)->nullable()->index();
            });
        }
        Schema::create('bill_detection_locks', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); });
        DB::table('bill_detection_locks')->insert(['id' => 1]);
    }
    public function down(): void
    {
        Schema::dropIfExists('bill_detection_locks');
        foreach (['internet_soas', 'billing_soas', 'pos_renewals'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['billing_period']);
                $table->dropColumn(['provider', 'billing_period']);
            });
        }
    }
};
