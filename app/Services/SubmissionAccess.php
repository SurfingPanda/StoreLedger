<?php
namespace App\Services;

class SubmissionAccess
{
    public static function allows(string $role, string $module): bool
    {
        return $role === 'admin' || ($role === 'rso' && in_array($module, ['rental', 'electricity', 'water'], true));
    }
}
