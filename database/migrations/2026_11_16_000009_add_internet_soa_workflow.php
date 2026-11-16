<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('internet_soas', function (Blueprint $table) {
            $table->string('status', 30)->default('awaiting_voucher')->index();
            $table->foreignId('voucher_action_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voucher_action_at')->nullable();
            $table->string('voucher_reference', 100)->nullable();
        });
        Schema::create('internet_soa_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internet_soa_id')->constrained('internet_soas')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('internet_soa_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internet_soa_id')->unique()->constrained('internet_soas')->restrictOnDelete();
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
        Schema::create('internet_soa_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internet_soa_id')->constrained('internet_soas')->restrictOnDelete();
            $table->date('due_date');
            $table->string('email');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->unique(['internet_soa_id', 'due_date', 'email'], 'internet_soa_reminder_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internet_soa_reminders');
        Schema::dropIfExists('internet_soa_payments');
        Schema::dropIfExists('internet_soa_comments');
        Schema::table('internet_soas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_action_by');
            $table->dropColumn(['status', 'voucher_action_at', 'voucher_reference']);
        });
    }
};
