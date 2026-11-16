<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PosRenewal extends Model
{
    protected $fillable = ['store_name', 'renewal_date', 'amount'];

    public function payment(): HasOne
    {
        return $this->hasOne(PosRenewalPayment::class);
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
        return $this->hasMany(PosRenewalComment::class);
    }

    protected function casts(): array
    {
        return ['renewal_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'voucher_action_at' => 'datetime'];
    }
}
