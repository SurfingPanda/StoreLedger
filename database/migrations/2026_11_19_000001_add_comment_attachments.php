<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_renewal_comments', 'internet_soa_comments', 'billing_soa_comments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
                $table->string('attachment_mime', 150)->nullable();
                $table->unsignedBigInteger('attachment_size')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_renewal_comments', 'internet_soa_comments', 'billing_soa_comments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size']));
        }
    }
};
