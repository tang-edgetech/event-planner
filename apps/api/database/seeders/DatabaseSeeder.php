<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the hidden Developer account. The first visible Super Admin is created on the /setup page.
     * Sample content: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $this->call(DeveloperSeeder::class);
    }
}
