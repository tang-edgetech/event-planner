<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The default Developer (top-level) account. It is hidden from every other user.
 * On any server other than a local one it must choose a new password at first sign-in.
 */
class DeveloperSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('username', 'developer')->exists()) {
            return;
        }

        User::query()->create([
            'name' => 'Developer',
            'username' => 'developer',
            'email' => env('DEVELOPER_EMAIL') ?: 'developer@eventplus.local',
            'password' => env('DEVELOPER_PASSWORD') ?: 'Admin@1234',
            'role' => 'developer',
            'status' => User::STATUS_ACTIVE,
            'is_hidden' => true,
            'must_change_password' => ! app()->environment('local', 'testing'),
            'password_changed_at' => now(),
        ]);
    }
}
