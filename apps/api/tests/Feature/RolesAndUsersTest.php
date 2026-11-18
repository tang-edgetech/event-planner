<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Support\Roles;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesAndUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        Roles::flush();
    }

    public function test_only_the_developer_can_see_roles(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        $this->getJson('/api/admin/roles')->assertNotFound();
        $this->postJson('/api/admin/roles', ['label' => 'X', 'rank' => 1, 'permissions' => []])->assertNotFound();

        $this->actingAs(User::factory()->role('developer')->create());
        $this->getJson('/api/admin/roles')->assertOk()->assertJsonPath('data.0.key', 'developer')->assertJsonPath('data.0.editable', false);
    }

    public function test_developer_creates_a_role_that_takes_effect(): void
    {
        $this->actingAs(User::factory()->role('developer')->create());
        $this->postJson('/api/admin/roles', ['label' => 'Event Editor', 'rank' => 2, 'permissions' => ['events.manage', 'bogus.perm']])->assertUnprocessable();
        $this->postJson('/api/admin/roles', ['label' => 'Event Editor', 'rank' => 2, 'permissions' => ['events.manage']])->assertCreated()->assertJson(['key' => 'event_editor']);
        $this->postJson('/api/admin/roles', ['label' => 'Event Editor', 'rank' => 2, 'permissions' => []])->assertUnprocessable();

        Roles::flush();
        $editor = User::factory()->create(['role' => 'event_editor']);
        $this->assertTrue($editor->hasPermission('events.view'), 'manage brings view along');
        $this->actingAs($editor);
        $this->postJson('/api/admin/events', ['title' => 'From editor'])->assertCreated();
        $this->getJson('/api/admin/applicants')->assertForbidden();
    }

    public function test_role_changes_apply_and_developer_role_is_locked(): void
    {
        $this->actingAs(User::factory()->role('developer')->create());
        $this->putJson('/api/admin/roles/viewer', ['label' => 'Viewer', 'rank' => 1, 'permissions' => ['events.view']])->assertOk();
        $this->putJson('/api/admin/roles/developer', ['label' => 'Dev', 'rank' => 1, 'permissions' => []])->assertForbidden();
        $this->deleteJson('/api/admin/roles/viewer')->assertForbidden();

        Roles::flush();
        $viewer = User::factory()->role('viewer')->create();
        $this->assertFalse($viewer->hasPermission('reports.view'));
        $this->actingAs($viewer);
        $this->getJson('/api/admin/reports/overview')->assertForbidden();
        Event::query()->create(['title' => 'Seen', 'slug' => 'seen', 'status' => 'draft']);
        $this->getJson('/api/admin/events')->assertOk();
    }

    public function test_custom_role_in_use_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->role('developer')->create());
        $this->postJson('/api/admin/roles', ['label' => 'Temp', 'rank' => 1, 'permissions' => []])->assertCreated();
        Roles::flush();
        $user = User::factory()->create(['role' => 'temp']);
        $this->deleteJson('/api/admin/roles/temp')->assertUnprocessable();
        $user->delete();
        $this->deleteJson('/api/admin/roles/temp')->assertOk();
    }

    public function test_username_and_email_are_unique_ignoring_case(): void
    {
        User::factory()->create(['username' => 'ann', 'email' => 'ann@example.com']);
        $admin = User::factory()->role('super_admin')->create();
        $this->actingAs($admin);
        $base = ['name' => 'Ann Two', 'role' => 'manager', 'status' => 'active', 'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass'];

        $this->postJson('/api/admin/users', [...$base, 'username' => 'ANN', 'email' => 'other@example.com'])
            ->assertUnprocessable()->assertJsonPath('errors.username.0', 'This username is already taken. Choose another one.');
        $this->postJson('/api/admin/users', [...$base, 'username' => 'ann2', 'email' => 'Ann@Example.COM'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/admin/users', [...$base, 'username' => 'Ann2', 'email' => 'ann2@example.com'])->assertCreated();
        $this->assertDatabaseHas('users', ['username' => 'ann2']);

        $this->getJson('/api/admin/users/check?field=username&value=ANN')->assertJson(['available' => false]);
        $this->getJson('/api/admin/users/check?field=email&value=free@example.com')->assertJson(['available' => true]);
        $this->getJson('/api/admin/users/check?field=username&value='.$admin->username.'&ignore='.$admin->id)->assertJson(['available' => true]);
    }

    public function test_logo_and_favicon_settings(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        $this->putJson('/api/admin/settings/general', ['logo' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->putJson('/api/admin/settings/general', ['logo' => 'media/2026/11/logo.png', 'favicon' => 'media/2026/11/icon.png'])->assertOk();
        Settings::flush();
        $this->getJson('/api/admin/auth/config')->assertJson(['logo' => 'media/2026/11/logo.png', 'favicon' => 'media/2026/11/icon.png']);
    }
}
