<?php

namespace Database\Seeders;

use App\Enums\InteractionType;
use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InteractionSeeder extends Seeder
{
    public function run(): void
    {
        $teamLeadId = User::query()->where('role', UserRole::TeamLead)->value('id');
        $types = InteractionType::cases();

        $rows = [];

        Contact::withTrashed()->orderBy('id')->chunk(100, function ($contacts) use (&$rows, $teamLeadId, $types) {
            foreach ($contacts as $contact) {
                $createdBy = $contact->assigned_agent_id ?? $teamLeadId;
                $count = fake()->numberBetween(0, 20);

                for ($i = 0; $i < $count; $i++) {
                    // Deliberately unordered relative to insertion — some of
                    // these land before others chronologically, exercising
                    // the same GREATEST() out-of-order case that
                    // InteractionService::create() handles in production
                    // (see DECISIONS.md #4).
                    $timestamp = fake()->dateTimeBetween($contact->created_at, 'now');

                    $rows[] = [
                        'contact_id' => $contact->id,
                        'type' => fake()->randomElement($types)->value,
                        'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                        'note' => fake()->sentence(fake()->numberBetween(6, 20)),
                        'created_by' => $createdBy,
                        'idempotency_key' => (string) Str::uuid(),
                        'request_hash' => fake()->sha256(),
                        'created_at' => $timestamp->format('Y-m-d H:i:s'),
                    ];
                }
            }

            if (count($rows) >= 500) {
                DB::table('interactions')->insert($rows);
                $rows = [];
            }
        });

        if ($rows !== []) {
            DB::table('interactions')->insert($rows);
        }

        // The seeder bulk-inserts directly via the query builder rather than
        // InteractionService::create(), so it doesn't get the incremental
        // GREATEST() update that keeps contacts.last_activity_at correct in
        // production — recompute it once at the end instead.
        Artisan::call('contacts:recompute-activity');
    }
}
