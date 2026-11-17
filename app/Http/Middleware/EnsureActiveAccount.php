<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user()?->fresh();
        $version = $request->session()->get('auth_version');
        $mismatch = $user && ($version !== null ? (int) $version !== (int) $user->session_version : $user->session_version > 1 && !Auth::viaRemember());
        if (!$user || !$user->is_active || $mismatch) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return $request->expectsJson()
                ? response()->json(['message' => 'Your account access changed or was disabled. Please sign in again.'], 401)
                : redirect()->route('login');
        }
        Auth::setUser($user);
        $request->session()->put('auth_version', $user->session_version);
        return $next($request);
    }
}
