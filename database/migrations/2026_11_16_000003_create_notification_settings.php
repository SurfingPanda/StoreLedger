<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('notification_settings')) Schema::create('notification_settings', function (Blueprint $table) {
            $table->string('event')->primary();
            $table->string('mode')->default('role');
            $table->json('emails');
            $table->timestamps();
        });
        Schema::table('pos_renewal_reminders', function (Blueprint $table) {
            $table->unique(['pos_renewal_id', 'renewal_date', 'email'], 'pos_reminder_email_unique');
        });
        Schema::table('pos_renewal_reminders', function (Blueprint $table) {
            $table->dropUnique('pos_reminder_recipient_unique');
            $table->foreignId('user_id')->nullable()->change();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        // Keep historical recipient-only delivery records and their nullable user IDs.
    }
};
