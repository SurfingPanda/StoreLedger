<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('security_audit_logs', function (Blueprint $table) {
            $table->id(); $table->uuid('request_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 100)->nullable(); $table->string('actor_email', 254)->nullable(); $table->string('actor_role', 20)->nullable();
            $table->string('category', 30)->index(); $table->string('event', 80)->index(); $table->string('outcome', 20)->index();
            $table->string('subject_type', 80)->nullable(); $table->string('subject_id', 100)->nullable();
            $table->string('module', 20)->nullable(); $table->unsignedBigInteger('record_id')->nullable();
            $table->string('ip_address', 45)->nullable()->index(); $table->string('user_agent', 500)->nullable();
            $table->string('method', 10); $table->string('route', 255)->nullable();
            $table->json('before')->nullable(); $table->json('after')->nullable(); $table->json('metadata')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('security_audit_logs'); }
};
