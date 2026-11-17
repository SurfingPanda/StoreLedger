<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RequestRevision extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['before' => 'array', 'after' => 'array']; }
    public function actor() { return $this->belongsTo(User::class, 'user_id')->select('id', 'name', 'role'); }
}
