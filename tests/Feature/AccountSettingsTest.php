<?php

namespace Tests\Feature;

use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;
    public static function roles(): array { return [['accounting'], ['finance'], ['rso'], ['it'], ['admin']]; }

    #[DataProvider('roles')]
    public function test_all_roles_can_view_and_update_only_their_own_profile(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'Original-password-123']);
        $other = User::factory()->create(['name' => 'Another account']);
        $this->actingAs($user)->withoutVite()->get('/settings')->assertOk()->assertViewHas('appData', fn ($data) => $data['page'] === 'settings');
        $this->getJson('/settings/profile')->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonMissingPath('user.password');
        $this->postJson('/settings/profile', ['name' => 'My updated name', 'email' => $user->email,
            'id' => $other->id, 'user_id' => $other->id, 'role' => $role === 'admin' ? 'finance' : 'admin', 'is_active' => false, 'password' => 'Injected-password-123'])
            ->assertOk()->assertJsonPath('user.name', 'My updated name')->assertJsonPath('user.role', $role);
        $this->assertSame('Another account', $other->fresh()->name);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue(Hash::check('Original-password-123', $user->fresh()->password));
        $this->getJson('/settings/profile')->assertOk()->assertJsonPath('user.name', 'My updated name');
    }

    public function test_changing_email_requires_current_password_and_unique_email(): void
    {
        $user = User::factory()->create(['role' => 'it', 'password' => 'Original-password-123']);
        $other = User::factory()->create();
        $this->actingAs($user);
        $payload = ['name' => $user->name, 'email' => 'new-address@example.com'];
        $this->postJson('/settings/profile', $payload)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/settings/profile', $payload + ['current_password' => 'Incorrect-password'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/settings/profile', ['name' => $user->name, 'email' => $other->email, 'current_password' => 'Original-password-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($user->email, $user->fresh()->email);
        Password::createToken($user);
        $response = $this->postJson('/settings/profile', ['name' => 'New name', 'email' => ' NEW-ADDRESS@example.com ', 'current_password' => 'Original-password-123'])
            ->assertOk()->assertJsonPath('user.email', 'new-address@example.com');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(2, $user->fresh()->session_version);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertSame(session()->token(), $response->json('csrf_token'));
        $this->getJson('/settings/profile')->assertOk()->assertJsonPath('user.email', 'new-address@example.com');
    }

    public function test_email_is_locked_for_rso_accounting_admin_and_finance_even_with_current_password(): void
    {
        foreach (['rso', 'accounting', 'admin', 'finance'] as $role) {
            $user = User::factory()->create(['role' => $role, 'password' => 'Original-password-123']);
            $original = $user->only('name', 'email', 'session_version');
            $this->actingAs($user)->postJson('/settings/profile', ['name' => 'Attempted change', 'email' => $role.'-changed@example.com',
                'current_password' => 'Original-password-123', 'role' => 'it'])->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertSame($original, $user->fresh()->only('name', 'email', 'session_version'));
            $this->postJson('/settings/profile', ['name' => 'Updated name', 'email' => $user->email])->assertOk()->assertJsonPath('user.email', $user->email);
        }
    }

    #[DataProvider('roles')]
    public function test_password_change_verifies_current_password_and_confirmation(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'Original-password-123']);
        $this->actingAs($user);
        $payload = ['current_password' => 'Original-password-123', 'password' => 'Updated-password-456', 'password_confirmation' => 'Updated-password-456'];
        $this->postJson('/settings/password', array_replace($payload, ['current_password' => 'Wrong-password']))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/settings/password', array_replace($payload, ['password_confirmation' => 'Different-password']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/settings/password', array_replace($payload, ['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/settings/password', array_replace($payload, ['password' => 'Original-password-123', 'password_confirmation' => 'Original-password-123']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('Original-password-123', $user->fresh()->password));
        $this->postJson('/settings/password', $payload)->assertOk();
        $this->assertTrue(Hash::check('Updated-password-456', $user->fresh()->password));
        $this->getJson('/settings/profile')->assertOk();
        $this->assertSame(2, session('auth_version'));
    }

    public function test_password_change_revokes_other_sessions_reset_tokens_and_remember_access(): void
    {
        $user = User::factory()->create(['role' => 'finance', 'password' => 'Original-password-123']);
        $other = User::factory()->create();
        Password::createToken($user);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Original-password-123', 'remember' => true])->assertOk();
        $sessionId = session()->getId(); $rememberToken = $user->fresh()->remember_token;
        foreach ([['other-session', $user->id], ['unrelated-session', $other->id]] as [$id, $owner]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        $response = $this->postJson('/settings/password', ['current_password' => 'Original-password-123',
            'password' => 'Updated-password-456', 'password_confirmation' => 'Updated-password-456'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertSame(session()->token(), $response->json('csrf_token'));
        $this->assertAuthenticatedAs($user);
        $this->getJson('/settings/profile')->assertOk();
        $audit = SecurityAuditLog::where('event', 'account.password_changed')->latest('id')->firstOrFail();
        $this->assertTrue($audit->metadata['sessions_revoked']);
        $serialized = SecurityAuditLog::all()->toJson();
        foreach (['Original-password-123', 'Updated-password-456', $user->fresh()->password] as $secret) $this->assertStringNotContainsString($secret, $serialized);
    }

    public function test_guests_and_disabled_accounts_cannot_access_personal_settings(): void
    {
        $this->getJson('/settings/profile')->assertUnauthorized();
        $this->postJson('/settings/profile', [])->assertUnauthorized();
        $this->postJson('/settings/password', [])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'accounting', 'is_active' => false]));
        $this->getJson('/settings/profile')->assertUnauthorized();
    }
}
