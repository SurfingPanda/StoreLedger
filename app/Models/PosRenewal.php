<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosRenewal extends Model
{
    protected $fillable = ['store_name', 'renewal_date', 'amount'];

    protected function casts(): array
    {
        return ['renewal_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }
}
