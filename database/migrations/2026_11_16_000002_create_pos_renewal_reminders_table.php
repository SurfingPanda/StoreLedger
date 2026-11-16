<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_renewal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_renewal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('renewal_date');
            $table->string('email', 254);
            $table->timestamp('sent_at')->nullable();
            $table->unique(['pos_renewal_id', 'user_id', 'renewal_date', 'email'], 'pos_reminder_recipient_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('pos_renewal_reminders'); }
};
