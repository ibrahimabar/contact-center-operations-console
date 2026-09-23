<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $agentIds = User::query()->where('role', UserRole::Agent)->where('is_active', true)->pluck('id');

        // ~470 assigned, spread across the active agents.
        Contact::factory()
            ->count(470)
            ->sequence(fn ($sequence) => ['assigned_agent_id' => $agentIds[$sequence->index % $agentIds->count()]])
            ->create();

        // A small unassigned queue for the team lead to triage.
        Contact::factory()->count(30)->unassigned()->create();

        // A handful of already-archived contacts, so `archived=only/with`
        // and the restore flow have something to demonstrate against.
        Contact::query()->inRandomOrder()->limit(15)->get()->each->delete();
    }
}
