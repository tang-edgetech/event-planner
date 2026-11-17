<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Settings;
use App\Support\TwoFactor;
use Database\Seeders\DeveloperSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        $this->seed(DeveloperSeeder::class);
    }

    public function test_setup_creates_first_super_admin_once(): void
    {
        $this->getJson('/api/admin/setup/status')->assertJson(['needed' => true]);

        $this->postJson('/api/admin/setup', [
            'name' => 'First Admin', 'username' => 'admin', 'email' => 'admin@example.com',
            'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['username' => 'admin', 'role' => 'super_admin']);
        $this->getJson('/api/admin/setup/status')->assertJson(['needed' => false]);
        $this->postJson('/api/admin/setup', [
            'name' => 'Second', 'username' => 'second', 'email' => 's@example.com',
            'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass',
        ])->assertNotFound();
    }

    public function test_developer_can_sign_in_and_is_hidden_from_others(): void
    {
        $admin = User::factory()->role('super_admin')->create(['username' => 'boss']);

        $this->postJson('/api/admin/auth/login', ['login' => 'developer', 'password' => 'Admin@1234'])->assertOk();
        $this->getJson('/api/admin/auth/me')->assertOk()->assertJsonPath('user.role', 'developer');

        $this->actingAs($admin);
        $this->getJson('/api/admin/users')->assertOk()->assertJsonMissing(['username' => 'developer']);
        $developer = User::query()->where('username', 'developer')->first();
        $this->getJson("/api/admin/users/{$developer->id}")->assertNotFound();
    }

    public function test_wrong_password_is_rejected_and_logged(): void
    {
        $this->postJson('/api/admin/auth/login', ['login' => 'developer', 'password' => 'nope'])->assertStatus(422);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login_failed')->where('status', 'error')->exists());
    }

    public function test_login_method_email_only_refuses_username(): void
    {
        Settings::put('security', ['login_method' => 'email']);
        $this->postJson('/api/admin/auth/login', ['login' => 'developer', 'password' => 'Admin@1234'])->assertStatus(422);
        $this->postJson('/api/admin/auth/login', ['login' => 'developer@eventplus.local', 'password' => 'Admin@1234'])->assertOk();
    }

    public function test_inactive_user_cannot_sign_in(): void
    {
        User::factory()->create(['username' => 'off', 'status' => 'inactive', 'password' => 'Str0ng!Pass']);
        $this->postJson('/api/admin/auth/login', ['login' => 'off', 'password' => 'Str0ng!Pass'])->assertStatus(422);
    }

    public function test_two_factor_is_required_when_a_device_is_linked(): void
    {
        $user = User::factory()->role('manager')->create(['username' => 'mgr', 'password' => 'Str0ng!Pass']);
        $secret = TwoFactor::newSecret();
        $user->twoFactorDevices()->create(['name' => 'Phone', 'secret' => $secret, 'confirmed_at' => now()]);

        $this->postJson('/api/admin/auth/login', ['login' => 'mgr', 'password' => 'Str0ng!Pass'])->assertJson(['two_factor' => true]);
        $this->getJson('/api/admin/auth/me')->assertStatus(401);
        $this->postJson('/api/admin/auth/two-factor', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/admin/auth/two-factor', ['code' => TwoFactor::engine()->getCurrentOtp($secret)])->assertOk();
        $this->getJson('/api/admin/auth/me')->assertOk()->assertJsonPath('user.username', 'mgr');
    }

    public function test_max_two_devices(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['A', 'B'] as $name) {
            $user->twoFactorDevices()->create(['name' => $name, 'secret' => TwoFactor::newSecret(), 'confirmed_at' => now()]);
        }
        $this->postJson('/api/admin/account/two-factor', ['name' => 'C'])->assertStatus(422);
    }

    public function test_must_change_password_blocks_other_screens(): void
    {
        $user = User::factory()->role('super_admin')->create(['must_change_password' => true, 'password' => 'Old!Pass123']);
        $this->actingAs($user);
        $this->getJson('/api/admin/events')->assertStatus(423)->assertJson(['reason' => 'password_change']);
        $this->putJson('/api/admin/account/password', ['current_password' => 'Old!Pass123', 'password' => 'N3w!Password', 'password_confirmation' => 'N3w!Password'])->assertOk();
        $this->getJson('/api/admin/events')->assertOk();
    }

    public function test_managers_cannot_manage_super_admins_or_open_settings(): void
    {
        $manager = User::factory()->role('manager')->create();
        $boss = User::factory()->role('super_admin')->create();
        $this->actingAs($manager);
        $this->patchJson("/api/admin/users/{$boss->id}/status", ['status' => 'inactive'])->assertForbidden();
        $this->getJson('/api/admin/settings/general')->assertForbidden();
    }

    public function test_super_admin_quick_actions(): void
    {
        $boss = User::factory()->role('super_admin')->create();
        $viewer = User::factory()->role('viewer')->create();
        $this->actingAs($boss);

        $this->patchJson("/api/admin/users/{$viewer->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertSame('inactive', $viewer->fresh()->status);
        $temp = $this->postJson("/api/admin/users/{$viewer->id}/reset-password")->assertOk()->json('temporary_password');
        $this->assertNotEmpty($temp);
        $this->assertTrue($viewer->fresh()->must_change_password);
        $this->postJson("/api/admin/users/{$viewer->id}/force-logout")->assertOk();
        $this->postJson("/api/admin/users/{$viewer->id}/reset-two-factor")->assertOk();
    }

    public function test_signed_out_requests_get_no_details(): void
    {
        $this->getJson('/api/admin/events')->assertStatus(401)->assertJson(['message' => 'Not found.']);
        $this->getJson('/api/public/site')->assertNotFound();
        $this->get('/')->assertNotFound();
    }
}
