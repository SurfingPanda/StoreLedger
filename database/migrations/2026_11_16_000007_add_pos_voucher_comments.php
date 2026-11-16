<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_renewals', fn (Blueprint $table) => $table->string('voucher_reference', 100)->nullable());
        Schema::create('pos_renewal_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_renewal_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_renewal_comments');
        Schema::table('pos_renewals', fn (Blueprint $table) => $table->dropColumn('voucher_reference'));
    }
};
