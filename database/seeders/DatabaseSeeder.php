<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Recordings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the demo. Everything is fixed text so every reset looks the same, and nothing needs Faker,
     * so the nightly reset also runs on a server installed without dev dependencies.
     */
    public function run(): void
    {
        User::create(['name' => 'Alex Rivera', 'email' => 'alex@flowly.test', 'role' => 'admin', 'password' => Str::random(40)]);
        User::create(['name' => 'Sam Chen', 'email' => 'sam@flowly.test', 'role' => 'agent', 'password' => Str::random(40)]);
        User::create(['name' => 'Jordan Patel', 'email' => 'jordan@flowly.test', 'role' => 'agent', 'password' => Str::random(40)]);

        $this->call([
            KnowledgeBaseSeeder::class,
            SkillSeeder::class,
            CustomerSeeder::class,
            TicketSeeder::class,
        ]);

        // Put back the real runs recorded with "php artisan triagedesk:record", if there are any.
        if ($restored = Recordings::import()) {
            $this->command?->info("Restored {$restored} recorded agent runs.");
        }
    }
}
