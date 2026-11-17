<?php
namespace App\Services;

use App\Models\Store;
use App\Models\Supplier;
use App\Models\StoreBillingAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class DirectoryLinks
{
    public static function key(string $name): string
    {
        return hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($name))));
    }

    public static function rules(): array
    {
        return ['billing_account_id' => ['nullable', 'integer', 'exists:store_billing_accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id']];
    }

    // Within the bill transaction, after the shared duplicate-detection lock.
    public static function assign(Model $record): void
    {
        if ($record->billing_account_id) {
            $account = StoreBillingAccount::with(['store', 'supplier'])->findOrFail($record->billing_account_id);
            if (!$account->is_active || !$account->store->is_active || !$account->supplier->is_active || $account->module !== RequestModule::key($record)
                || ($record->store_id && (int) $record->store_id !== $account->store_id)) {
                throw ValidationException::withMessages(['billing_account_id' => 'Select an active billing account for this store and module.']);
            }
            $record->store_id = $account->store_id;
            $record->supplier_id = $account->supplier_id;
            $record->store_name = $account->store->name;
            $record->provider = $account->supplier->name;
            $record->account_number = $account->account_number;
            return;
        }
        // Compatibility for existing integrations: resolve names once, then retain IDs.
        if ($record->store_id) {
            $store = Store::findOrFail($record->store_id);
        } else {
            $stores = Store::where('name_key', self::key($record->store_name))->get();
            if ($stores->count() > 1) throw ValidationException::withMessages(['store_id' => 'More than one store has this name. Select a saved billing account.']);
            $store = $stores->first();
            if (!$store) {
                $store = new Store(['code' => 'AUTO-'.bin2hex(random_bytes(12)), 'name' => trim($record->store_name)]);
                $store->name_key = self::key($store->name); $store->save();
                $store->code = 'ST-'.str_pad($store->id, 5, '0', STR_PAD_LEFT); $store->save();
            }
        }
        if (!$store->is_active) throw ValidationException::withMessages(['store_id' => 'This store is inactive.']);
        $record->store_id = $store->id; $record->store_name = $store->name;
        if (trim($record->provider ?? '') !== '') {
            $supplier = Supplier::where('name_key', self::key($record->provider))->first();
            if (!$supplier) {
                $supplier = new Supplier(['name' => trim($record->provider)]);
                $supplier->name_key = self::key($supplier->name); $supplier->save();
            }
            if (!$supplier->is_active) throw ValidationException::withMessages(['provider' => 'This supplier is inactive.']);
            $record->supplier_id = $supplier->id;
            $module = RequestModule::key($record);
            $identity = hash('sha256', $store->id.'|'.$supplier->id.'|'.$module.'|');
            $account = StoreBillingAccount::where('identity_key', $identity)->first();
            if (!$account) {
                $account = new StoreBillingAccount(['store_id' => $store->id, 'supplier_id' => $supplier->id, 'module' => $module]);
                $account->identity_key = $identity; $account->save();
            }
            if (!$account->is_active) throw ValidationException::withMessages(['billing_account_id' => 'This billing account is inactive.']);
            $record->billing_account_id = $account->id;
        }
    }

    public static function history(Model $record)
    {
        $query = RequestModule::query(RequestModule::key($record));
        return $record->store_id ? $query->where('store_id', $record->store_id) : $query->whereNull('store_id')->where('store_name', $record->store_name);
    }
}
