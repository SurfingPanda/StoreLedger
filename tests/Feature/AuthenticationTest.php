<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_can_open_login_and_recovery_pages(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('BW Superbakeshop')->assertSee('csrf-token');
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/token?email=person@example.com')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_user_can_sign_in_with_normalized_email_and_be_remembered(): void
    {
        $user = User::factory()->create(['email' => 'person@example.com', 'password' => 'a-long-test-password']);
        $this->postJson('/login', [
            'email' => 'PERSON@example.com', 'password' => 'a-long-test-password', 'remember' => true,
        ])->assertOk()->assertJson(['redirect' => route('dashboard')])->assertCookie(auth()->guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertSee($user->email);
        $this->get('/login')->assertRedirect('/dashboard');
    }

    public function test_invalid_credentials_never_authenticate_a_user(): void
    {
        User::factory()->create(['email' => 'person@example.com']);
        $this->postJson('/login', ['email' => 'person@example.com', 'password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->postJson('/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_repeated_invalid_sign_ins_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', ['email' => 'locked@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => 'locked@example.com', 'password' => 'wrong'])
            ->assertStatus(429)->assertJsonPath('message', fn ($value) => str_contains($value, 'Too many attempts'));
        $this->assertGuest();
    }

    public function test_user_can_sign_out_and_protected_routes_are_inaccessible_afterwards(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/logout')->assertOk()->assertJson(['redirect' => route('login')]);
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_reset_email_is_sent_without_disclosing_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
        $unknown = $this->postJson('/forgot-password', ['email' => 'unknown@example.com'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_valid_reset_link_changes_the_password_and_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-long-password', 'password_confirmation' => 'new-long-password'];
        $this->postJson('/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-long-password', $user->fresh()->password));
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->postJson('/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_reset_requires_a_valid_token_and_a_confirmed_long_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;
        $this->postJson('/reset-password', ['email' => $user->email, 'token' => 'invalid', 'password' => 'new-long-password', 'password_confirmation' => 'new-long-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/reset-password', ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'short', 'password_confirmation' => 'different'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_accounts_can_be_provisioned_with_the_console_command(): void
    {
        $this->artisan('app:create-user')
            ->expectsQuestion('Full name', 'Workspace Admin')
            ->expectsQuestion('Email address', 'ADMIN@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'a-secure-test-password')
            ->expectsQuestion('Confirm password', 'a-secure-test-password')
            ->expectsOutput('Account created. You can now sign in.')
            ->assertSuccessful();
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-secure-test-password', $user->password));
    }
}
