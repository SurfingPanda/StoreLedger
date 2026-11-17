<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;
class SecurityAuditLog extends Model {
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['before' => 'array', 'after' => 'array', 'metadata' => 'array', 'occurred_at' => 'datetime']; }
    protected static function booted(): void {
        static::updating(fn () => throw new LogicException('Security audit records are append-only.'));
        static::deleting(fn () => throw new LogicException('Security audit records cannot be deleted through the application.'));
    }
}
