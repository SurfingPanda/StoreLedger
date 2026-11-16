<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_renewal_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_renewal_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 30);
            $table->string('reference', 100);
            $table->text('notes')->nullable();
            $table->string('proof_path');
            $table->string('proof_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_renewal_payments');
    }
};
