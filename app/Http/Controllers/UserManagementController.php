<?php

namespace App\Http\Controllers;

use App\Services\TableSort;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin' && $request->user()->is_active, 403);
    }

    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) $request->merge(['email' => Str::lower(trim($request->input('email')))]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'], 'role' => ['sometimes', Rule::in(['all', ...User::ROLES])],
            'status' => ['sometimes', Rule::in(['all', 'active', 'disabled'])], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = User::query();
        if (!empty($data['search'])) {
            $pattern = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $pattern)->orWhere('email', 'like', $pattern));
        }
        if (($data['role'] ?? 'all') !== 'all') $query->where('role', $data['role']);
        if (($data['status'] ?? 'all') !== 'all') $query->where('is_active', $data['status'] === 'active');
        TableSort::apply($query, $request, ['name' => 'name', 'role' => 'role', 'is_active' => 'is_active', 'last_login_at' => 'last_login_at', 'created_at' => 'created_at'], 'name', 'asc');
        $users = $query->select(['id', 'name', 'email', 'role', 'is_active', 'created_at', 'last_login_at'])->paginate($this->pageSize($request));
        return response()->json(['users' => $users, 'summary' => [
            'total' => User::count(), 'active' => User::where('is_active', true)->count(),
            'disabled' => User::where('is_active', false)->count(), 'admins' => User::where('role', 'admin')->where('is_active', true)->count(),
        ], 'current_user_id' => $request->user()->id]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request); $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users')],
            'role' => ['required', Rule::in(User::ROLES)], 'password' => ['required', 'confirmed', Password::min(12), 'max:128'],
        ]);
        $user = new User(collect($data)->only(['name', 'email', 'password'])->all());
        $user->role = $data['role']; $user->is_active = true; DB::transaction(fn () => $user->save());
        return response()->json(['message' => 'Account created. Share the login details with the user securely.'], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request); $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', Rule::in(User::ROLES)],
        ]);
        return $this->change($request, $user, $data, 'Account details updated.');
    }

    public function status(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        return $this->change($request, $user, $data, $data['is_active'] ? 'Account enabled.' : 'Account disabled and access revoked.');
    }

    public function password(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(12), 'max:128']]);
        return $this->change($request, $user, ['password' => $data['password']], 'Password reset. Existing sessions were signed out.');
    }

    private function change(Request $request, User $user, array $data, string $message): JsonResponse
    {
        $ownSecurityChange = false;
        DB::transaction(function () use ($request, $user, $data, &$ownSecurityChange) {
            // Lock the admin set in a consistent order to protect the final admin under concurrent changes.
            $admins = User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
            $actor = $admins->firstWhere('id', $request->user()->id);
            abort_unless($actor?->is_active, 403);
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $role = $data['role'] ?? $target->role;
            $active = isset($data['is_active']) ? (bool) $data['is_active'] : $target->is_active;
            if ($target->role === 'admin' && $target->is_active && ($role !== 'admin' || !$active) && $admins->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'At least one active admin must remain.']);
            }
            if ($target->id === $actor->id && ($role !== 'admin' || !$active)) {
                throw ValidationException::withMessages(['role' => 'You cannot disable your own account or change your own role. Ask another admin.']);
            }
            $oldEmail = $target->email;
            $target->forceFill($data);
            $securityChange = $target->isDirty(['email', 'role', 'is_active', 'password']);
            if ($securityChange) {
                $target->session_version++;
                $target->remember_token = Str::random(60);
            }
            $target->save();
            if ($securityChange) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
                DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, $target->email])->delete();
            }
            $ownSecurityChange = $securityChange && $target->id === $actor->id;
        });
        if ($ownSecurityChange) {
            Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        }
        return response()->json(['message' => $message, 'redirect' => $ownSecurityChange ? route('login') : null, 'reload' => !$ownSecurityChange && $user->id === $request->user()?->id]);
    }
}
