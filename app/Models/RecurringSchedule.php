<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringSchedule extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['active' => 'boolean', 'anchor_date' => 'date:Y-m-d', 'next_due_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }
}
