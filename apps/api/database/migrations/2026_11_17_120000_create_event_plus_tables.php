<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Event Plus tables: 2FA devices, settings, audit log, events, applicants, microsites, media, page views.
     */
    public function up(): void
    {
        Schema::create('two_factor_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 40);
            $table->string('key', 80);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('category', 30)->index();
            $table->string('action', 60)->index();
            $table->string('status', 10)->index();
            $table->string('summary', 500);
            $table->text('description')->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('is_hidden')->default(false)->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug', 120)->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('date_text')->nullable();
            $table->string('time_text')->nullable();
            $table->string('venue')->nullable();
            $table->string('venue_map_url', 1000)->nullable();
            $table->longText('summary')->nullable();
            $table->string('banner_desktop')->nullable();
            $table->string('banner_mobile')->nullable();
            $table->string('accent_color', 20)->nullable();
            $table->string('page_bg', 20)->nullable();
            $table->string('page_layout', 10)->default('split');
            $table->boolean('show_title')->default(true);
            $table->longText('form_intro')->nullable();
            $table->json('form_fields')->nullable();
            $table->string('submit_label', 60)->default('Submit');
            $table->string('reg_status', 10)->default('auto');
            $table->unsignedInteger('capacity_min')->nullable();
            $table->unsignedInteger('capacity_max')->nullable();
            $table->dateTime('reg_open_at')->nullable();
            $table->dateTime('reg_close_at')->nullable();
            $table->boolean('enable_waitlist')->default(false);
            $table->string('default_status', 20)->default('registered');
            $table->string('full_title')->nullable();
            $table->longText('full_message')->nullable();
            $table->string('closed_title')->nullable();
            $table->longText('closed_message')->nullable();
            $table->text('waitlist_message')->nullable();
            $table->longText('waitlist_confirmation')->nullable();
            $table->string('confirmation_type', 10)->default('message');
            $table->longText('confirmation_message')->nullable();
            $table->string('redirect_url', 1000)->nullable();
            $table->boolean('notify_admin')->default(true);
            $table->text('admin_emails')->nullable();
            $table->boolean('notify_applicant')->default(false);
            $table->string('email_subject')->nullable();
            $table->longText('email_body')->nullable();
            $table->timestamp('capacity_alert_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('registered')->index();
            $table->string('name')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->json('data');
            $table->json('labels')->nullable();
            $table->json('source')->nullable();
            $table->string('visitor_id', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('applicant_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message', 500);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('microsites', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug', 120)->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sections')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->string('viewable_type', 20);
            $table->unsignedBigInteger('viewable_id');
            $table->string('visitor_hash', 64)->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('device', 10)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['viewable_type', 'viewable_id']);
        });
    }

    public function down(): void
    {
        foreach (['page_views', 'media', 'microsites', 'applicant_activities', 'applicants', 'events', 'audit_logs', 'settings', 'two_factor_devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
