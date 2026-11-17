<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Supplier extends Model {
    protected $attributes = ['is_active' => true];
    protected $fillable = ['name', 'email', 'phone', 'is_active'];
    protected $hidden = ['name_key', 'identity_key'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    
}
