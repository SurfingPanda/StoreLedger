<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class RememberSignInTest extends TestCase
{
    use RefreshDatabase;
    private function restartBrowser(): void
    {
        app('session.store')->flush();
        app('session.store')->migrate(true);
        Auth::forgetGuards(); Cookie::flushQueuedCookies();
        $this->defaultCookies = []; $this->unencryptedCookies = [];
    }

    public function test_remember_cookie_restores_a_fresh_session_after_browser_restart(): void
    {
        $user = User::factory()->create(['password' => 'remember-test-password', 'session_version' => 3]);
        $name = Auth::guard()->getRecallerName();
        $response = $this->postJson('/login', ['email' => $user->email, 'password' => 'remember-test-password', 'remember' => true])->assertOk();
        $cookie = $response->getCookie($name, false);
        $this->assertNotNull($cookie); $this->assertTrue($cookie->isHttpOnly()); $this->assertGreaterThan(time(), $cookie->getExpiresTime());
        $this->restartBrowser();
        $this->withUnencryptedCookie($name, $cookie->getValue())->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(3, session('auth_version'));
    }

    public function test_unchecked_signin_does_not_issue_a_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => 'remember-test-password']); $name = Auth::guard()->getRecallerName();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'remember-test-password', 'remember' => false])->assertOk()->assertCookieExpired($name);
        $this->restartBrowser(); $this->get('/dashboard')->assertRedirect('/login');
    }
    public function test_remember_cookie_has_a_thirty_day_lifetime_and_logout_revokes_it(): void
    {
        $user = User::factory()->create(['password' => 'remember-test-password']); $name = Auth::guard()->getRecallerName();
        $response = $this->postJson('/login', ['email' => $user->email, 'password' => 'remember-test-password', 'remember' => true])->assertOk();
        $cookie = $response->getCookie($name, false);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $cookie->getExpiresTime(), 5);
        $this->withUnencryptedCookie($name, $cookie->getValue())->withCredentials()->postJson('/logout')->assertOk()->assertCookieExpired($name);
        $this->restartBrowser();
        $this->withUnencryptedCookie($name, $cookie->getValue())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_unchecked_login_clears_an_old_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => 'remember-test-password']); $name = Auth::guard()->getRecallerName();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'remember-test-password', 'remember' => false])->assertOk()->assertCookieExpired($name);
        $this->restartBrowser(); $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_disabled_accounts_and_changed_passwords_reject_old_remember_cookies(): void
    {
        $user = User::factory()->create(['password' => 'remember-test-password']); $name = Auth::guard()->getRecallerName();
        $cookie = $this->postJson('/login', ['email' => $user->email, 'password' => 'remember-test-password', 'remember' => true])->assertOk()->getCookie($name, false);
        $user->is_active = false; $user->save(); $this->restartBrowser();
        $this->withUnencryptedCookie($name, $cookie->getValue())->get('/dashboard')->assertRedirect('/login'); $this->assertGuest();
        $user->is_active = true; $user->password = 'changed-test-password'; $user->save(); $this->restartBrowser();
        $this->withUnencryptedCookie($name, $cookie->getValue())->get('/dashboard')->assertRedirect('/login'); $this->assertGuest();
    }

    public function test_admin_security_changes_revoke_remembered_access(): void
    {
        $user = User::factory()->create(['password' => 'remember-test-password']); $name = Auth::guard()->getRecallerName();
        $cookie = $this->postJson('/login', ['email' => $user->email, 'password' => 'remember-test-password', 'remember' => true])->assertOk()->getCookie($name, false);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/users/records/'.$user->id, ['name' => $user->name, 'email' => $user->email, 'role' => 'finance'])->assertOk();
        $this->restartBrowser();
        $this->withUnencryptedCookie($name, $cookie->getValue())->get('/dashboard')->assertRedirect('/login'); $this->assertGuest();
    }

}
