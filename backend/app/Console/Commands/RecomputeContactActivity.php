<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes contacts.last_activity_at from scratch. This denormalized
 * column (see DECISIONS.md #4) is only ever advanced incrementally by
 * InteractionService, so it should never drift — this command exists as a
 * documented recovery path (e.g. after a bulk data import/fix that bypassed
 * the service layer), not as something run routinely.
 */
#[Signature('contacts:recompute-activity')]
#[Description('Recompute contacts.last_activity_at from the interactions table')]
class RecomputeContactActivity extends Command
{
    public function handle(): int
    {
        $affected = DB::update(<<<'SQL'
            update contacts c
            left join (
                select contact_id, max(timestamp) as max_timestamp
                from interactions
                group by contact_id
            ) latest on latest.contact_id = c.id
            set c.last_activity_at = greatest(c.created_at, coalesce(latest.max_timestamp, c.created_at))
        SQL);

        $this->info("Recomputed last_activity_at for {$affected} contact(s).");

        return self::SUCCESS;
    }
}
