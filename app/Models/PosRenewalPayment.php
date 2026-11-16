<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosRenewalPayment extends Model
{
    protected $fillable = ['paid_on', 'method', 'reference', 'notes'];
    protected $hidden = ['proof_path'];

    protected function casts(): array
    {
        return ['paid_on' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'name', 'role']);
    }
}
