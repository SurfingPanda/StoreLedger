<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboxNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array { return ['read_at' => 'datetime', 'resolved_at' => 'datetime']; }
}
