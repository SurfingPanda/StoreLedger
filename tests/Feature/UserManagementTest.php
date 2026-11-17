<?php

namespace Tests\Feature;

use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user); return $user;
    }

    private function details(string $role = 'finance'): array
    {
        return ['name' => 'New Finance', 'email' => 'NEW@example.com', 'role' => $role, 'password' => 'a-new-secure-password', 'password_confirmation' => 'a-new-secure-password'];
    }

    public function test_only_admins_can_open_manage_and_list_accounts(): void
    {
        $target = User::factory()->create(['role' => 'finance']);
        $this->get('/users')->assertRedirect('/login');
        $this->getJson('/users/records')->assertUnauthorized();
        foreach (['accounting', 'finance', 'it', 'rso'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/users')->assertForbidden();
            $this->getJson('/users/records')->assertForbidden();
            $this->postJson('/users/records', $this->details())->assertForbidden();
            $this->postJson('/users/records/'.$target->id, $this->details())->assertForbidden();
            $this->postJson('/users/records/'.$target->id.'/status', ['is_active' => false])->assertForbidden();
            $this->postJson('/users/records/'.$target->id.'/password', $this->details())->assertForbidden();
        }
        $this->admin();
        $this->withoutVite()->get('/users')->assertOk()->assertViewHas('appData', fn ($data) => $data['page'] === 'users');
    }

    public function test_admin_creates_active_accounts_with_hashed_passwords_and_validated_roles(): void
    {
        $this->admin();
        foreach (User::ROLES as $index => $role) {
            $data = array_replace($this->details($role), ['email' => 'USER'.$index.'@example.com']);
            $this->postJson('/users/records', $data + ['is_active' => false, 'session_version' => 999])->assertCreated();
            $user = User::where('email', 'user'.$index.'@example.com')->firstOrFail();
            $this->assertSame($role, $user->role); $this->assertTrue($user->is_active);
            $this->assertTrue(Hash::check($data['password'], $user->password));
            $this->assertSame(1, $user->session_version);
        }
        $this->postJson('/users/records', array_replace($this->details(), ['email' => 'USER0@EXAMPLE.COM']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/users/records', array_replace($this->details(), ['role' => 'owner']))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/users/records', array_replace($this->details(), ['password' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_editing_role_and_email_revokes_sessions_and_reset_tokens_without_changing_history_identity(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'finance']);
        $oldRemember = $target->remember_token;
        Password::createToken($target);
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->postJson('/users/records/'.$target->id, ['name' => 'Accounting Staff', 'email' => 'CHANGED@example.com', 'role' => 'accounting', 'password' => 'ignored-input'])->assertOk();
        $target->refresh();
        $this->assertSame('changed@example.com', $target->email); $this->assertSame('accounting', $target->role);
        $this->assertSame(2, $target->session_version); $this->assertNotSame($oldRemember, $target->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
    }

    public function test_disabled_accounts_cannot_login_use_existing_sessions_or_receive_role_emails(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'accounting', 'password' => 'known-valid-password']);
        $this->postJson('/users/records/'.$target->id.'/status', ['is_active' => false])->assertOk();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertCount(0, NotificationSetting::recipients('accounting_voucher'));
        $this->actingAs($target)->getJson('/dashboard/records')->assertUnauthorized();
        $this->assertGuest();
        $this->postJson('/login', ['email' => $target->email, 'password' => 'known-valid-password'])->assertUnprocessable();
        $this->assertGuest();
        Notification::fake();
        $this->postJson('/forgot-password', ['email' => $target->email])->assertOk();
        Notification::assertNotSentTo($target, ResetPassword::class);
        $this->actingAs($admin)->postJson('/users/records/'.$target->id.'/status', ['is_active' => true])->assertOk();
        auth()->logout();
        $this->postJson('/login', ['email' => $target->email, 'password' => 'known-valid-password'])->assertOk();
        $this->assertNotNull($target->fresh()->last_login_at);
    }

    public function test_last_admin_and_self_access_cannot_be_removed(): void
    {
        $admin = $this->admin();
        $this->postJson('/users/records/'.$admin->id.'/status', ['is_active' => false])->assertUnprocessable();
        $this->postJson('/users/records/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'finance'])->assertUnprocessable();
        User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->postJson('/users/records/'.$admin->id.'/status', ['is_active' => false])->assertUnprocessable();
        $other = User::factory()->create(['role' => 'admin']);
        $this->postJson('/users/records/'.$admin->id.'/status', ['is_active' => false])->assertUnprocessable();
        $this->postJson('/users/records/'.$other->id.'/status', ['is_active' => false])->assertOk();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_resets_password_and_old_sessions_and_old_password_stop_working(): void
    {
        $this->admin(); $target = User::factory()->create(['password' => 'old-valid-password']);
        $this->postJson('/users/records/'.$target->id.'/password', ['password' => 'new-valid-password', 'password_confirmation' => 'mismatch'])->assertUnprocessable();
        $this->postJson('/users/records/'.$target->id.'/password', ['password' => 'new-valid-password', 'password_confirmation' => 'new-valid-password'])->assertOk();
        $this->assertTrue(Hash::check('new-valid-password', $target->fresh()->password));
        $this->actingAs($target->fresh())->withSession(['auth_version' => 1])->getJson('/dashboard/records')->assertUnauthorized();
        $this->postJson('/login', ['email' => $target->email, 'password' => 'old-valid-password'])->assertUnprocessable();
        $this->postJson('/login', ['email' => $target->email, 'password' => 'new-valid-password'])->assertOk();
    }

    public function test_filters_pagination_and_self_password_changes_are_handled(): void
    {
        $admin = $this->admin();
        User::factory()->count(17)->create(['role' => 'finance']);
        User::factory()->create(['name' => 'Disabled Person', 'email' => 'disabled@example.com', 'role' => 'rso', 'is_active' => false]);
        $this->getJson('/users/records?role=finance')->assertOk()->assertJsonCount(15, 'users.data')->assertJsonPath('users.total', 17)->assertJsonMissingPath('users.data.0.password');
        $this->getJson('/users/records?role=finance&page=2')->assertJsonCount(2, 'users.data');
        $this->getJson('/users/records?status=disabled&search=disabled@')->assertJsonPath('users.total', 1)->assertJsonPath('summary.disabled', 1);
        $this->getJson('/users/records?role=owner')->assertUnprocessable();
        $this->postJson('/users/records/'.$admin->id.'/password', ['password' => 'new-admin-password', 'password_confirmation' => 'new-admin-password'])->assertOk()->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
    }
}
