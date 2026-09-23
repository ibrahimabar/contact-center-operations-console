<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Fixed, well-known dev credentials (see README "Seed credentials") —
     * deliberately not randomized so a reviewer can log in immediately.
     */
    public function run(): void
    {
        User::factory()->teamLead()->create([
            'name' => 'Taylor Lead',
            'email' => 'teamlead@example.com',
            'password' => Hash::make('password'),
        ]);

        User::factory()->create([
            'name' => 'Deactivated Agent',
            'email' => 'inactive.agent@example.com',
            'password' => Hash::make('password'),
            'role' => UserRole::Agent,
            'is_active' => false,
        ]);

        collect(range(1, 6))->each(fn (int $i) => User::factory()->create([
            'name' => "Agent {$i}",
            'email' => "agent{$i}@example.com",
            'password' => Hash::make('password'),
            'role' => UserRole::Agent,
        ]));
    }
}
