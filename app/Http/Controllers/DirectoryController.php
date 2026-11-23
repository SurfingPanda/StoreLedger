<?php
namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Supplier;
use App\Models\StoreBillingAccount;
use App\Models\User;
use App\Services\DirectoryLinks;
use App\Services\DuplicateBills;
use App\Services\RequestModule;
use App\Services\StoreCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DirectoryController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'stores' => Store::with('assignedRso')->orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'accounts' => StoreBillingAccount::with(['store.assignedRso', 'supplier'])->orderBy('id')->get(),
            'rsos' => User::where('role', 'rso')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'next_store_codes' => [
                'corporate_owned' => StoreCodes::preview('corporate_owned'),
                'franchise' => StoreCodes::preview('franchise'),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, string $kind, ?int $id = null)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $class = match ($kind) { 'stores' => Store::class, 'suppliers' => Supplier::class, 'accounts' => StoreBillingAccount::class, default => abort(404) };
        $rules = match ($kind) {
            'stores' => ['code' => ['sometimes', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
                'name' => ['required', 'string', 'max:150'],
                'ownership_type' => [Rule::requiredIf(!$request->filled('code') && !$id || $request->exists('ownership_type')), Rule::in(['corporate_owned', 'franchise'])],
                'assigned_rso_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'rso')->where('is_active', true)]],
            'suppliers' => ['name' => ['required', 'string', 'max:150'], 'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:60']],
            'accounts' => ['store_id' => ['required', 'integer', 'exists:stores,id'], 'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
                'module' => ['required', Rule::in(array_keys(RequestModule::LABELS))], 'account_number' => ['nullable', 'string', 'max:100'],
                'amount' => ['exclude_unless:module,internet-soa', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                'billing_cycle_months' => ['required', 'integer', 'in:1,3,6,12']],
        };
        $data = $request->validate(array_merge($rules, ['is_active' => ['required', 'boolean']]));
        return DB::transaction(function () use ($request, $kind, $id, $class, $data) {
            DuplicateBills::lock();
            $record = $id ? $class::whereKey($id)->lockForUpdate()->firstOrFail() : new $class;
            if ($kind === 'stores') {
                if (isset($data['ownership_type'])) {
                    $prefix = $data['ownership_type'] === 'corporate_owned' ? 'BW' : 'FR';
                    $data['code'] = !$record->exists || $record->ownership_type !== $data['ownership_type'] || !preg_match('/^'.$prefix.'[0-9]{4,}$/', $record->code)
                        ? StoreCodes::next($data['ownership_type']) : $record->code;
                } else {
                    // Retain compatibility with older directory integrations.
                    $data['code'] = $record->exists ? $record->code : strtoupper($data['code']);
                }
                if (Store::where('code', $data['code'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) throw ValidationException::withMessages(['code' => 'This store ID is already in use.']);
            }
            if ($kind === 'suppliers' && Supplier::where('name_key', DirectoryLinks::key($data['name']))->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['name' => 'This supplier is already in the directory.']);
            }
            if ($kind === 'accounts') {
                $data['account_number'] = trim($data['account_number'] ?? '') ?: null;
                if ($data['module'] !== 'internet-soa') {
                    $data['amount'] = null;
                }
                $key = hash('sha256', $data['store_id'].'|'.$data['supplier_id'].'|'.$data['module'].'|'.mb_strtolower($data['account_number'] ?? ''));
                if (StoreBillingAccount::where('identity_key', $key)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) throw ValidationException::withMessages(['account_number' => 'This store already has this supplier account for the selected module.']);
                if ($id && ($record->store_id != $data['store_id'] || $record->supplier_id != $data['supplier_id'] || $record->module !== $data['module'])) {
                    foreach (array_keys(RequestModule::LABELS) as $module) {
                        if (RequestModule::query($module)->where('billing_account_id', $id)->exists()) throw ValidationException::withMessages(['store_id' => 'This account has bill history. Create another account to change its store, supplier or module.']);
                    }
                }
                if ($data['is_active'] && (!Store::findOrFail($data['store_id'])->is_active || !Supplier::findOrFail($data['supplier_id'])->is_active)) throw ValidationException::withMessages(['store_id' => 'Activate the store and supplier before activating this account.']);
                $record->identity_key = $key;
            } else {
                $record->name_key = DirectoryLinks::key($data['name']);
            }
            $record->fill($data)->save();
            return response()->json(['message' => 'Directory entry saved.', 'record' => $record], $id ? 200 : 201);
        });
    }
}
