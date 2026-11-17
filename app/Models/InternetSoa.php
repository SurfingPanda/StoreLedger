<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InternetSoa extends Model
{
    protected $hidden = ['attachment_path'];

    protected $fillable = ['store_id', 'billing_account_id', 'provider', 'billing_period', 'store_name', 'due_date', 'amount'];

    public function store() { return $this->belongsTo(Store::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function billingAccount() { return $this->belongsTo(StoreBillingAccount::class, 'billing_account_id'); }

    public function payment(): HasOne
    {
        return $this->hasOne(InternetSoaPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'name', 'role']);
    }

    public function voucherActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voucher_action_by')->select(['id', 'name', 'role']);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(InternetSoaComment::class);
    }

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'voucher_action_at' => 'datetime', 'duplicate_cancelled_at' => 'datetime', 'returned_at' => 'datetime', 'workflow_version' => 'integer', 'review_cycle' => 'integer'];
    }
}
