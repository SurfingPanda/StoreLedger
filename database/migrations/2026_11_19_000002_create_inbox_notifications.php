<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbox_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('notification_key', 64);
            $table->string('module', 20);
            $table->unsignedBigInteger('request_id');
            $table->string('kind', 16);
            $table->string('title', 150);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'notification_key']);
            $table->index(['user_id', 'role', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('inbox_notifications'); }
};
