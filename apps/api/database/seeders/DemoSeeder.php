<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\Event;
use App\Models\Microsite;
use App\Models\PageView;
use App\Support\FormSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sample content for trying the system locally: one event with a form, a microsite and some applicants.
 * php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $event = Event::query()->updateOrCreate(['slug' => 'demo-symposium'], [
            'title' => 'Demo Symposium 2026',
            'status' => 'published',
            'starts_at' => now()->addDays(30)->setTime(9, 0),
            'ends_at' => now()->addDays(30)->setTime(17, 0),
            'venue' => 'Demo Convention Centre, Kuala Lumpur',
            'summary' => '<p>This is sample content for trying the system. Edit or delete it any time.</p>',
            'page_layout' => 'split',
            'show_title' => true,
            'capacity_max' => 50,
            'enable_waitlist' => true,
            'notify_admin' => false,
            'notify_applicant' => false,
            'form_intro' => '<p>Please fill in your details.</p>',
            'form_fields' => FormSchema::normalise([
                ['type' => 'salutation', 'label' => 'Salutation', 'required' => true, 'width' => '25'],
                ['type' => 'text', 'label' => 'Full name', 'name' => 'full_name', 'required' => true, 'width' => '66'],
                ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true, 'width' => '50', 'unique' => true],
                ['type' => 'phone', 'label' => 'Mobile number', 'name' => 'phone', 'required' => true, 'width' => '50', 'phone_format' => 'my'],
                ['type' => 'select', 'label' => 'Specialty', 'name' => 'specialty', 'required' => true, 'width' => '50', 'options' => ['Cardiology', 'General Practice', 'Pharmacy', 'Nursing'], 'allow_other' => true],
                ['type' => 'radio', 'label' => 'Dietary preference', 'name' => 'diet', 'width' => '50', 'options' => ['None', 'Vegetarian', 'Halal'], 'required' => true],
                ['type' => 'text', 'label' => 'Please describe your allergy', 'name' => 'allergy', 'width' => '100', 'conditional' => true, 'cond_field' => 'diet', 'cond_operator' => 'is', 'cond_value' => 'Vegetarian'],
                ['type' => 'consent', 'label' => 'I agree to the processing of my personal data for this event.', 'name' => 'consent', 'required' => true],
            ]),
        ]);

        Microsite::query()->updateOrCreate(['slug' => 'demo-symposium'], [
            'title' => 'Demo Symposium 2026',
            'status' => 'published',
            'event_id' => $event->id,
            'settings' => ['accent_color' => '#7a0f3d', 'show_nav' => true, 'footer_text' => '© Copyright Protected. All Rights Reserved.', 'animations' => true],
            'sections' => [
                ['id' => (string) Str::uuid(), 'layout' => 'banner', 'data' => ['eyebrow' => 'Sample microsite', 'title' => 'Demo Symposium 2026', 'description' => '<p>A one-day update for healthcare professionals.</p>', 'show_meta' => true, 'style' => 'center', 'height' => 'md', 'text_align' => 'center', 'countdown' => true, 'countdown_to' => $event->starts_at->toIso8601String(), 'buttons' => [['label' => 'Register now', 'action' => 'anchor', 'url' => '#register', 'style' => 'solid']]], 'settings' => ['bg_type' => 'gradient', 'bg_color' => '#7a0f3d', 'bg_color2' => '#2a1a20', 'bg_angle' => 135, 'text_scheme' => 'light', 'padding' => 'lg', 'container' => 'default', 'nav_label' => 'Home']],
                ['id' => (string) Str::uuid(), 'layout' => 'agenda', 'data' => ['heading' => 'Programme', 'align' => 'center', 'items' => [['time' => '9:00 AM', 'title' => 'Registration & welcome', 'description' => ''], ['time' => '10:00 AM', 'title' => 'Keynote', 'description' => 'Speaker to be announced'], ['time' => '12:30 PM', 'title' => 'Lunch', 'description' => '']]], 'settings' => ['bg_type' => 'none', 'padding' => 'md', 'container' => 'narrow', 'anchor' => 'programme', 'nav_label' => 'Programme']],
                ['id' => (string) Str::uuid(), 'layout' => 'form', 'data' => ['heading' => 'Register', 'align' => 'center', 'event_id' => $event->id], 'settings' => ['bg_type' => 'color', 'bg_color' => '#f7f3ef', 'padding' => 'lg', 'container' => 'narrow', 'anchor' => 'register', 'nav_label' => 'Register']],
            ],
        ]);

        if ($event->applicants()->count() === 0) {
            $statuses = ['registered', 'registered', 'confirmed', 'registered', 'attended', 'cancelled', 'confirmed', 'registered'];
            $sources = [null, ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'launch'], ['referrer' => 'https://www.google.com/'], ['utm_source' => 'whatsapp']];
            foreach (range(1, 24) as $i) {
                $name = 'Sample Person '.$i;
                Applicant::query()->create([
                    'event_id' => $event->id,
                    'status' => $statuses[$i % count($statuses)],
                    'name' => 'Dr. '.$name,
                    'email' => "sample{$i}@example.com",
                    'phone' => '+6012345'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'data' => ['salutation' => 'Dr.', 'full_name' => $name, 'email' => "sample{$i}@example.com", 'phone' => '+6012345'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'specialty' => ['Cardiology', 'General Practice', 'Pharmacy'][$i % 3], 'diet' => 'None', 'consent' => 'Agreed'],
                    'source' => $sources[$i % count($sources)],
                    'created_at' => now()->subDays(24 - $i)->setTime(10 + $i % 8, 15),
                    'updated_at' => now()->subDays(24 - $i),
                ]);
            }
            foreach (range(0, 120) as $i) {
                PageView::query()->create([
                    'viewable_type' => $i % 3 ? 'event' : 'microsite',
                    'viewable_id' => $i % 3 ? $event->id : Microsite::query()->where('slug', 'demo-symposium')->value('id'),
                    'visitor_hash' => sha1('demo'.$i % 70),
                    'utm_source' => ['newsletter', null, 'whatsapp', null][$i % 4],
                    'referrer_host' => $i % 5 === 0 ? 'www.google.com' : null,
                    'device' => ['mobile', 'desktop', 'mobile', 'tablet'][$i % 4],
                    'created_at' => now()->subDays($i % 25)->setTime(8 + $i % 12, 0),
                ]);
            }
        }
    }
}
