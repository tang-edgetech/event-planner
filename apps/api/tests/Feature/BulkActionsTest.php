<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Microsite;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
    }

    public function test_events_can_be_sorted_and_published_in_bulk(): void
    {
        $b = Event::query()->create(['title' => 'Beta', 'slug' => 'beta', 'status' => 'draft']);
        $a = Event::query()->create(['title' => 'Alpha', 'slug' => 'alpha', 'status' => 'draft']);
        $this->actingAs(User::factory()->role('manager')->create());

        $this->getJson('/api/admin/events?sort=title&order=asc')->assertOk()->assertJsonPath('data.0.title', 'Alpha');
        $this->getJson('/api/admin/events?sort=title&order=desc')->assertOk()->assertJsonPath('data.0.title', 'Beta');

        $this->postJson('/api/admin/events/bulk', ['ids' => [$a->id, $b->id], 'action' => 'publish'])->assertOk()->assertJson(['changed' => 2]);
        $this->assertSame(['published'], Event::query()->pluck('status')->unique()->values()->all());
        $this->assertTrue(AuditLog::query()->where('action', 'event.bulk_publish')->exists());

        $this->postJson('/api/admin/events/bulk', ['ids' => [$a->id], 'action' => 'delete'])->assertOk();
        $this->assertSoftDeleted($a);
        $this->postJson('/api/admin/events/bulk', ['ids' => [$b->id], 'action' => 'explode'])->assertUnprocessable();
    }

    public function test_viewer_cannot_use_bulk_actions(): void
    {
        $event = Event::query()->create(['title' => 'Gamma', 'slug' => 'gamma', 'status' => 'draft']);
        $this->actingAs(User::factory()->role('viewer')->create());

        $this->postJson('/api/admin/events/bulk', ['ids' => [$event->id], 'action' => 'publish'])->assertForbidden();
        $this->postJson('/api/admin/users/bulk', ['ids' => [1], 'action' => 'deactivate'])->assertForbidden();
        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_microsites_bulk_unpublish(): void
    {
        $site = Microsite::query()->create(['title' => 'Site', 'slug' => 'site', 'status' => 'published', 'sections' => [], 'settings' => []]);
        $this->actingAs(User::factory()->role('manager')->create());

        $this->postJson('/api/admin/microsites/bulk', ['ids' => [$site->id], 'action' => 'draft'])->assertOk()->assertJson(['changed' => 1]);
        $this->assertSame('draft', $site->fresh()->status);
    }

    public function test_users_bulk_skips_accounts_of_equal_or_higher_rank(): void
    {
        $admin = User::factory()->role('super_admin')->create();
        $peer = User::factory()->role('super_admin')->create();
        $manager = User::factory()->role('manager')->create();
        $this->actingAs($admin);

        $this->postJson('/api/admin/users/bulk', ['ids' => [$peer->id, $manager->id], 'action' => 'deactivate'])
            ->assertOk()->assertJson(['changed' => 1, 'skipped' => 1]);
        $this->assertSame(User::STATUS_INACTIVE, $manager->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $peer->fresh()->status);
        $this->getJson('/api/admin/users?sort=role&order=desc')->assertOk();
    }

    public function test_applicants_bulk_delete(): void
    {
        $event = Event::query()->create(['title' => 'Delta', 'slug' => 'delta', 'status' => 'published']);
        $applicant = Applicant::query()->create(['event_id' => $event->id, 'status' => 'registered', 'name' => 'Ann', 'email' => 'ann@example.com', 'data' => ['name' => 'Ann']]);
        $this->actingAs(User::factory()->role('manager')->create());

        $this->postJson('/api/admin/applicants/bulk-delete', ['ids' => [$applicant->id]])->assertOk()->assertJson(['changed' => 1]);
        $this->assertNull(Applicant::query()->find($applicant->id));
        $this->getJson('/api/admin/audit-logs?sort=category&order=asc')->assertForbidden();
    }
}
