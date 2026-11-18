<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Store extends Model {
    protected $attributes = ['is_active' => true];
    protected $fillable = ['code', 'name', 'ownership_type', 'assigned_rso_id', 'is_active'];
    protected $hidden = ['name_key', 'identity_key'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function assignedRso() { return $this->belongsTo(User::class, 'assigned_rso_id')->select('id', 'name', 'email', 'role', 'is_active'); }
}
