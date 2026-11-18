<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles become editable (Roles & permissions, developer only). Starts from the roles in config/permissions.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 60);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('rank');
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        $now = now();
        foreach (config('permissions.roles') as $key => $role) {
            DB::table('roles')->insert([
                'key' => $key,
                'label' => $role['label'],
                'description' => $role['description'] ?? null,
                'rank' => $role['rank'],
                'permissions' => json_encode(array_values($role['permissions'])),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
