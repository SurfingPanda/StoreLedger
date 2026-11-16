<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetSoa extends Model
{
    protected $fillable = ['store_name', 'amount', 'due_date'];
    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }
}
