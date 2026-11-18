<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->text('cancellation_reason')->nullable());
        }
        Schema::create('overdue_mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('module', 20);
            $table->unsignedBigInteger('request_id');
            $table->unsignedInteger('review_cycle');
            $table->string('event', 40);
            $table->string('email', 254);
            $table->date('reminder_date');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['module', 'request_id', 'review_cycle', 'event', 'email', 'reminder_date'], 'overdue_mail_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overdue_mail_deliveries');
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('cancellation_reason'));
        }
    }
};
