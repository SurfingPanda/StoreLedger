<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StoreBillingAccount extends Model {
    protected $attributes = ['is_active' => true];
    protected $fillable = ['store_id', 'supplier_id', 'module', 'account_number', 'billing_cycle_months', 'is_active'];
    protected $hidden = ['name_key', 'identity_key'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function store() { return $this->belongsTo(Store::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
}
