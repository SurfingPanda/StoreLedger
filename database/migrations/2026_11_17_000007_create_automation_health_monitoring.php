<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('automation_heartbeats')) Schema::create('automation_heartbeats', function (Blueprint $table) {
            $table->string('key')->primary(); $table->timestamp('last_seen_at')->nullable(); $table->timestamps();
        });
        DB::table('automation_heartbeats')->insertOrIgnore(['key' => 'scheduler', 'created_at' => now(), 'updated_at' => now()]);
        if (!Schema::hasTable('automation_runs')) Schema::create('automation_runs', function (Blueprint $table) {
            $table->id(); $table->string('command', 100)->index(); $table->string('status', 20)->index();
            $table->timestamp('started_at')->index(); $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable(); $table->integer('exit_code')->nullable();
            $table->json('metrics')->nullable(); $table->string('summary', 500)->nullable(); $table->timestamps();
        });
        if (!Schema::hasTable('automation_issues')) Schema::create('automation_issues', function (Blueprint $table) {
            $table->id(); $table->string('issue_key', 64)->unique(); $table->string('command', 100)->index();
            $table->foreignId('run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->string('module', 20)->nullable(); $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable(); $table->string('recipient', 254)->nullable();
            $table->string('message', 500); $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at'); $table->dateTime('last_seen_at'); $table->timestamp('resolved_at')->nullable()->index(); $table->timestamps();
        });
        if (!Schema::hasTable('automation_retry_requests')) Schema::create('automation_retry_requests', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('delivery_id'); $table->foreignId('user_id')->constrained()->restrictOnDelete(); $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('automation_retry_requests'); Schema::dropIfExists('automation_issues');
        Schema::dropIfExists('automation_runs'); Schema::dropIfExists('automation_heartbeats');
    }
};
