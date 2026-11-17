<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('workflow_version')->default(1);
                $table->unsignedInteger('review_cycle')->default(1);
                $table->string('revision_target', 20)->nullable();
                $table->text('revision_reason')->nullable();
                $table->foreignId('returned_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('returned_at')->nullable();
            });
        }
        Schema::create('request_revisions', function (Blueprint $table) {
            $table->id(); $table->string('module', 20); $table->unsignedBigInteger('request_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); $table->string('action', 40);
            $table->text('reason'); $table->json('before'); $table->json('after'); $table->timestamps();
            $table->index(['module', 'request_id', 'id']);
        });
        Schema::table('workflow_mail_deliveries', function (Blueprint $table) {
            $table->unsignedInteger('review_cycle')->default(1);
            $table->dropUnique('workflow_mail_recipient_unique');
            $table->unique(['module', 'request_id', 'event', 'email', 'review_cycle'], 'workflow_mail_cycle_unique');
        });
    }
    public function down(): void {
        if (DB::table('workflow_mail_deliveries')->select('module', 'request_id', 'event', 'email')
            ->groupBy('module', 'request_id', 'event', 'email')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multiple notification review cycles exist. Preserve their history before rolling back this migration.');
        }
        Schema::table('workflow_mail_deliveries', function (Blueprint $table) {
            $table->dropUnique('workflow_mail_cycle_unique'); $table->dropColumn('review_cycle');
            $table->unique(['module', 'request_id', 'event', 'email'], 'workflow_mail_recipient_unique');
        });
        Schema::dropIfExists('request_revisions');
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('returned_by');
                $table->dropColumn(['workflow_version', 'review_cycle', 'revision_target', 'revision_reason', 'returned_at']);
            });
        }
    }
};
