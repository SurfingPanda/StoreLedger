<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSoaComment extends Model
{
    protected $fillable = ['body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'name', 'role']);
    }
}
