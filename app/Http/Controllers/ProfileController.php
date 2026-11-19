<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->only(['name', 'email', 'role'])])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users')->ignore($request->user()->id)],
            'current_password' => ['nullable', 'string', 'max:1024'],
        ]);
        [$user, $changedEmail] = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active, 403);
            $oldEmail = $user->email;
            $changedEmail = Str::lower($oldEmail) !== $data['email'];
            if ($changedEmail) {
                if (in_array($user->role, ['rso', 'accounting', 'admin', 'finance'], true)) {
                    throw ValidationException::withMessages(['email' => 'Your email address cannot be changed from account settings.']);
                }
                $this->checkPassword($user, $data['current_password'] ?? '');
                $user->email_verified_at = null;
                $this->revokeOtherAccess($request, $user, [$oldEmail, $data['email']]);
            }
            $user->fill(['name' => $data['name'], 'email' => $data['email']])->save();
            return [$user, $changedEmail];
        });
        $this->refreshCurrentSession($request, $user, $changedEmail);
        return response()->json(['message' => $changedEmail ? 'Profile updated. Other sessions have been signed out.' : 'Profile updated.',
            'user' => $user->only(['name', 'email', 'role']), 'csrf_token' => $request->session()->token()]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12), 'max:128'],
        ]);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active, 403);
            $this->checkPassword($user, $data['current_password']);
            $user->password = $data['password'];
            $this->revokeOtherAccess($request, $user, [$user->email]);
            $user->save();
            return $user;
        });
        $this->refreshCurrentSession($request, $user, true);
        return response()->json(['message' => 'Password changed. Other sessions have been signed out.', 'csrf_token' => $request->session()->token()]);
    }

    private function checkPassword(User $user, string $password): void
    {
        if (!Hash::check($password, $user->password)) throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
    }

    private function revokeOtherAccess(Request $request, User $user, array $emails): void
    {
        $user->session_version++;
        $user->remember_token = Str::random(60);
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
    }

    private function refreshCurrentSession(Request $request, User $user, bool $regenerate): void
    {
        Auth::setUser($user);
        if ($regenerate) {
            $request->session()->regenerate();
            Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));
        }
        $request->session()->put('auth_version', $user->session_version);
    }
}
