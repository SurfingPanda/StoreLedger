<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosRenewalComment extends Model
{
    protected $fillable = ['body'];
    protected $hidden = ['attachment_path'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->select(['id', 'name', 'role']);
    }
}
