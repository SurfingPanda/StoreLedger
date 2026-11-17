<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id(); $table->string('code', 50)->unique(); $table->string('name', 150);
            $table->string('name_key', 64)->index(); $table->foreignId('assigned_rso_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id(); $table->string('name', 150); $table->string('name_key', 64)->unique();
            $table->string('email', 254)->nullable(); $table->string('phone', 60)->nullable();
            $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('store_billing_accounts', function (Blueprint $table) {
            $table->id(); $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete(); $table->string('module', 30);
            $table->string('account_number', 100)->nullable(); $table->unsignedTinyInteger('billing_cycle_months')->nullable();
            $table->string('identity_key', 64)->unique(); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('billing_account_id')->nullable()->constrained('store_billing_accounts')->restrictOnDelete();
                $table->string('account_number', 100)->nullable();
            });
        }
        $key = fn ($name) => hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($name))));
        $now = now();
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $key, $now) {
                foreach ($rows as $row) {
                    $storeKey = $key($row->store_name);
                    $store = DB::table('stores')->where('name_key', $storeKey)->first();
                    if (!$store) {
                        $id = DB::table('stores')->insertGetId(['code' => 'MIG-'.substr($storeKey, 0, 16), 'name' => trim($row->store_name), 'name_key' => $storeKey, 'created_at' => $now, 'updated_at' => $now]);
                        DB::table('stores')->where('id', $id)->update(['code' => 'ST-'.str_pad($id, 5, '0', STR_PAD_LEFT)]);
                        $store = DB::table('stores')->find($id);
                    }
                    $supplierId = null; $accountId = null;
                    if (trim($row->provider ?? '') !== '') {
                        $supplierKey = $key($row->provider);
                        $supplierId = DB::table('suppliers')->where('name_key', $supplierKey)->value('id');
                        if (!$supplierId) $supplierId = DB::table('suppliers')->insertGetId(['name' => trim($row->provider), 'name_key' => $supplierKey, 'created_at' => $now, 'updated_at' => $now]);
                        $module = $table === 'pos_renewals' ? 'utak-pos' : ($table === 'internet_soas' ? 'internet-soa' : $row->module);
                        $identity = hash('sha256', $store->id.'|'.$supplierId.'|'.$module.'|');
                        $accountId = DB::table('store_billing_accounts')->where('identity_key', $identity)->value('id');
                        if (!$accountId) $accountId = DB::table('store_billing_accounts')->insertGetId(['store_id' => $store->id, 'supplier_id' => $supplierId, 'module' => $module, 'identity_key' => $identity, 'created_at' => $now, 'updated_at' => $now]);
                    }
                    DB::table($table)->where('id', $row->id)->update(['store_id' => $store->id, 'supplier_id' => $supplierId, 'billing_account_id' => $accountId]);
                }
            });
        }
    }
    public function down(): void
    {
        foreach (['pos_renewals', 'internet_soas', 'billing_soas'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                foreach (['store_id', 'supplier_id', 'billing_account_id'] as $column) $table->dropConstrainedForeignId($column);
                $table->dropColumn('account_number');
            });
        }
        Schema::dropIfExists('store_billing_accounts'); Schema::dropIfExists('suppliers'); Schema::dropIfExists('stores');
    }
};
