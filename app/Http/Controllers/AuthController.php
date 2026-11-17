<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function page(Request $request, string $page = 'login'): View
    {
        return view('app', ['appData' => [
            'page' => $page,
            'loggedOut' => $page === 'login' && $request->session()->pull('logged_out', false),
            'renewalId' => $request->route('posRenewal')?->id ?? $request->route('internetSoa')?->id ?? $request->route('billingSoa')?->id,
            'assets' => [
                'mascot' => asset('images/Full Moymoy 2.png'),
                'logo' => asset('images/logo.png'),
            ],
            'email' => $page === 'reset-password' ? (string) $request->query('email', '') : '',
            'token' => $page === 'reset-password' ? (string) $request->route('token') : '',
            'user' => $request->user()?->only('name', 'email', 'role'),
            'urls' => [
                'login' => route('login'),
                'logout' => route('logout'),
                'forgotPassword' => route('password.request'),
                'sendResetLink' => route('password.email'),
                'resetPassword' => route('password.update'),
                'dashboard' => route('dashboard'),
                'dashboardRecords' => route('dashboard.records'),
                'dashboardActivity' => route('dashboard.activity'),
                'pos' => route('utak-pos'),
                'posRecords' => route('utak-pos.records'),
                'internetSoa' => route('internet-soa'),
                'internetSoaRecords' => route('internet-soa.records'),
                'electricity' => route('electricity'),
                'electricityRecords' => route('electricity.records'),
                'rental' => route('rental'),
                'rentalRecords' => route('rental.records'),
                'water' => route('water'),
                'waterRecords' => route('water.records'),
                'settings' => route('settings'),
                'notificationSettings' => route('settings.notifications'),
                'users' => route('users'),
                'userRecords' => route('users.records'),
                'directory' => route('directory'),
                'directoryRecords' => route('directory.records'),
            ],
        ]]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.'], 429);
        }

        if (!Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect. Please try again.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->session_version);
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return response()->json(['redirect' => $request->session()->pull('url.intended', route(in_array($request->user()->role, ['accounting', 'finance'], true) ? 'utak-pos' : 'dashboard'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('logged_out', true);

        return response()->json(['redirect' => route('login')]);
    }

    public function sendResetLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        Password::sendResetLink(['email' => Str::lower(trim($data['email'])), 'is_active' => true]);

        return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent. Check your inbox and spam folder.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'confirmed', PasswordRule::min(12), 'max:128'],
        ]);
        $data['email'] = Str::lower(trim($data['email']));
        $data['is_active'] = true;
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'session_version' => $user->session_version + 1])->save();
            \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Please request a new one.']);
        }

        return response()->json(['message' => 'Your password has been updated. You can now sign in.']);
    }
}
