<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Event;
use App\Models\Microsite;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ImportWordPressTest extends TestCase
{
    use RefreshDatabase;

    private function exportFile(): string
    {
        $json = [
            'source' => 'http://old.example/wp',
            'settings' => ['option_lists' => [['name' => 'Meal', 'slug' => 'meal', 'options' => ['Fish', 'Chicken']]], 'from_name' => 'Event Plus'],
            'media' => [],
            'events' => [[
                'wp_id' => 5, 'title' => 'Old Summit', 'slug' => 'old-summit', 'status' => 'published', 'created_at' => '2026-01-01 10:00:00',
                'fields' => ['event_start' => '2026-09-13 08:30:00', 'capacity_max' => '100', 'reg_status' => 'auto', 'show_title' => true, 'notify_admin' => true, 'summary' => '<p>Hello<script>x</script></p>'] + array_fill_keys(['venue', 'event_date_text', 'event_time_text', 'venue_map', 'banner_desktop', 'banner_mobile', 'accent_color', 'page_bg', 'page_layout', 'form_intro', 'submit_label', 'capacity_min', 'enable_waitlist', 'default_status', 'full_title', 'closed_title', 'full_message', 'closed_message', 'waitlist_message', 'waitlist_confirmation', 'confirmation_type', 'confirmation_message', 'redirect_url', 'admin_emails', 'notify_applicant', 'email_subject', 'email_body'], ''),
                'form_fields' => [
                    ['name' => 'full-name', 'type' => 'text', 'label' => 'Full name', 'required' => true, 'width' => '100', 'options' => [], 'format' => '', 'conditions' => []],
                    ['name' => 'contact-number', 'type' => 'phone', 'label' => 'Phone', 'required' => true, 'width' => '50', 'options' => [], 'format' => 'my', 'conditions' => []],
                    ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'width' => '50', 'options' => [], 'format' => '', 'unique' => true, 'conditions' => []],
                ],
            ]],
            'microsites' => [[
                'wp_id' => 10, 'title' => 'Old Site', 'slug' => 'old-site', 'status' => 'published', 'created_at' => '2026-01-01 10:00:00', 'linked_event' => 5,
                'settings' => ['nav_cta' => ['title' => 'Register', 'url' => 'http://old.example/wp/event/old-summit/'], 'show_nav' => true],
                'sections' => [
                    ['acf_fc_layout' => 'banner', 'title' => 'Hi', 'buttons' => [['label' => 'Go', 'action' => 'event', 'event' => 5, 'url' => '', 'modal_links' => false]], 'settings' => ['bg_type' => 'color']],
                    ['acf_fc_layout' => 'posts', 'heading' => 'News', 'settings' => []],
                ],
            ]],
            'applicants' => [[
                'wp_id' => 34, 'title' => 'Dr. Ann', 'created_at' => '2026-02-01 09:00:00', 'event_wp_id' => 5, 'status' => 'confirmed',
                'data' => ['full-name' => 'Ann', 'contact-number' => '+60123456789', 'email' => 'Ann@Example.com'], 'labels' => ['full-name' => 'Full name'],
                'source' => ['utm_source' => 'newsletter', 'ref_domain' => ''], 'visitor' => '', 'ip_hash' => '', 'log' => [['t' => 1790000000, 'm' => 'Note: called']], 'notes' => '',
            ]],
        ];
        $path = tempnam(sys_get_temp_dir(), 'wp').'.json';
        file_put_contents($path, json_encode($json));

        return $path;
    }

    public function test_import_maps_everything_and_can_run_twice(): void
    {
        Settings::flush();
        $file = $this->exportFile();

        foreach ([1, 2] as $run) {
            $code = Artisan::call('import:wordpress', ['file' => $file]);
            $this->assertSame(0, $code, "Run {$run}: ".Artisan::output());
        }

        $this->assertSame(1, Event::query()->count());
        $this->assertSame(1, Applicant::query()->count());
        $event = Event::query()->first();
        $this->assertSame(['full_name', 'contact_number', 'email'], array_column($event->form_fields, 'name'));
        $this->assertStringNotContainsString('<script', (string) $event->summary);

        $applicant = Applicant::query()->first();
        $this->assertSame('ann@example.com', $applicant->email);
        $this->assertSame('+60123456789', $applicant->phone);
        $this->assertSame('confirmed', $applicant->status);
        $this->assertSame('Ann', $applicant->data['full_name']);
        $this->assertSame(2, $applicant->activities()->count());

        $site = Microsite::query()->first();
        $this->assertSame($event->id, $site->event_id);
        $this->assertCount(1, $site->sections, 'the News section is left out');
        $this->assertSame($event->id, $site->sections[0]['data']['buttons'][0]['event_id']);
        $this->assertSame('/event/old-summit', $site->settings['nav_cta_url']);
        $this->assertSame('meal', setting('extra', 'option_lists')[0]['slug']);
    }
}
