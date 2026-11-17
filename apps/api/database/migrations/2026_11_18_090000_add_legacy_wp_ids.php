<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember the WordPress post ID of imported items, so the import can be run again without duplicates.
     */
    public function up(): void
    {
        foreach (['events', 'microsites', 'applicants', 'media'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('legacy_wp_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (['events', 'microsites', 'applicants', 'media'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['legacy_wp_id']);
                $t->dropColumn('legacy_wp_id');
            });
        }
    }
};
