<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflow_mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('module', 20);
            $table->unsignedBigInteger('request_id');
            $table->string('event', 40);
            $table->string('email', 254);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['module', 'request_id', 'event', 'email'], 'workflow_mail_recipient_unique');
        });
        Schema::create('recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('module', 20);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('store_name', 150);
            $table->decimal('amount', 12, 2);
            $table->unsignedTinyInteger('interval_months');
            $table->unsignedTinyInteger('lead_days')->default(15);
            $table->date('anchor_date');
            $table->unsignedInteger('occurrence')->default(1);
            $table->date('next_due_date')->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['module', 'source_id']);
        });
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('recurring_schedule_id')->nullable()->constrained('recurring_schedules')->restrictOnDelete();
                $table->date('recurring_due_date')->nullable();
                $table->unique(['recurring_schedule_id', 'recurring_due_date']);
            });
        }
        foreach (['internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('attachment_path')->nullable()->change();
                $table->string('attachment_name')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['recurring_schedule_id', 'recurring_due_date']);
                $table->dropConstrainedForeignId('recurring_schedule_id');
                $table->dropColumn('recurring_due_date');
            });
        }
        Schema::dropIfExists('recurring_schedules');
        Schema::dropIfExists('workflow_mail_deliveries');
    }
};
