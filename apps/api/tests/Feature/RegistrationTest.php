<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\RegistrationService;
use App\Support\FormSchema;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        config(['services.internal.key' => 'test-key']);
        $this->event = Event::query()->create([
            'title' => 'Test Event', 'slug' => 'test-event', 'status' => 'published', 'capacity_max' => 1, 'enable_waitlist' => true,
            'notify_admin' => false, 'notify_applicant' => false,
            'form_fields' => FormSchema::normalise([
                ['type' => 'text', 'label' => 'Full name', 'name' => 'full_name', 'required' => true],
                ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true, 'unique' => true],
                ['type' => 'phone', 'label' => 'Phone', 'name' => 'phone', 'required' => true, 'phone_format' => 'my'],
                ['type' => 'radio', 'label' => 'Diet', 'name' => 'diet', 'options' => ['None', 'Other diet'], 'required' => true],
                ['type' => 'text', 'label' => 'Details', 'name' => 'details', 'required' => true, 'conditional' => true, 'cond_field' => 'diet', 'cond_operator' => 'is', 'cond_value' => 'Other diet'],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function register(array $data, int $age = 10): TestResponse
    {
        return $this->withHeaders(['X-Internal-Key' => 'test-key', 'X-Client-Ip' => '10.0.0.1'])
            ->postJson('/api/public/events/test-event/register', $data + ['_token_ts' => RegistrationService::token($this->event, time() - $age)]);
    }

    public function test_public_event_needs_internal_key(): void
    {
        $this->getJson('/api/public/events/test-event')->assertNotFound();
        $this->withHeaders(['X-Internal-Key' => 'test-key'])->getJson('/api/public/events/test-event')->assertOk()->assertJsonPath('registration.state', 'open');
    }

    public function test_valid_registration_then_waitlist_then_duplicate(): void
    {
        $this->register(['full_name' => 'Ann Lee', 'email' => 'ann@example.com', 'phone' => '012-345 6789', 'diet' => 'None'])->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('applicants', ['email' => 'ann@example.com', 'phone' => '+60123456789', 'status' => 'registered']);

        $this->register(['full_name' => 'Bob Tan', 'email' => 'bob@example.com', 'phone' => '0123456780', 'diet' => 'None'])->assertOk();
        $this->assertDatabaseHas('applicants', ['email' => 'bob@example.com', 'status' => 'waitlist']);

        $this->register(['full_name' => 'Ann Again', 'email' => 'ANN@example.com', 'phone' => '0123456781', 'diet' => 'None'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_conditional_required_field_and_formats(): void
    {
        $res = $this->register(['full_name' => 'Cat', 'email' => 'not-an-email', 'phone' => '123', 'diet' => 'Other diet'])->assertStatus(422);
        $this->assertEqualsCanonicalizing(['email', 'phone', 'details'], array_keys($res->json('errors')));
    }

    public function test_spam_protection(): void
    {
        $this->register(['full_name' => 'Fast Bot', 'email' => 'bot@example.com', 'phone' => '0123456789', 'diet' => 'None'], 0)->assertStatus(422);
        $this->register(['full_name' => 'Trap', 'email' => 'trap@example.com', 'phone' => '0123456789', 'diet' => 'None', '_hp' => 'x'])->assertStatus(422);
        $this->assertDatabaseCount('applicants', 0);
    }

    public function test_closed_event_refuses(): void
    {
        $this->event->update(['reg_status' => 'closed']);
        $this->register(['full_name' => 'Late', 'email' => 'late@example.com', 'phone' => '0123456789', 'diet' => 'None'])->assertStatus(422);
    }

    public function test_admin_can_export_selected_applicants(): void
    {
        $this->register(['full_name' => 'Ann Lee', 'email' => 'ann@example.com', 'phone' => '012-345 6789', 'diet' => 'None'])->assertOk();
        $this->actingAs(User::factory()->role('manager')->create());
        $id = $this->getJson('/api/admin/applicants?event_id='.$this->event->id)->assertOk()->json('data.0.id');
        $csv = $this->post('/api/admin/applicants/export', ['format' => 'csv', 'ids' => [$id], 'event_id' => $this->event->id])->assertOk()->streamedContent();
        $this->assertStringContainsString('ann@example.com', $csv);
        $this->postJson('/api/admin/audit-logs/export', ['format' => 'json'])->assertForbidden();
    }
}
