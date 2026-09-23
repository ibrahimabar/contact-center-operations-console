<?php

use App\Models\Contact;
use App\Models\Interaction;
use Illuminate\Support\Facades\DB;

it('recomputes last_activity_at from the interactions table, correcting drift', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create(['last_activity_at' => now()->subYear()]);
    $latest = now()->subDay();

    // Bypasses InteractionService on purpose, simulating drift (e.g. a bulk
    // import) that left last_activity_at stale.
    Interaction::factory()->for($contact)->for($me, 'creator')->create(['timestamp' => now()->subWeek()]);
    Interaction::factory()->for($contact)->for($me, 'creator')->create(['timestamp' => $latest]);

    expect($contact->fresh()->last_activity_at->lt($latest))->toBeTrue();

    $this->artisan('contacts:recompute-activity')->assertSuccessful();

    // Whole-second precision: the column is a MySQL TIMESTAMP, $latest is
    // an in-memory Carbon instant with microseconds.
    expect($contact->fresh()->last_activity_at->format('Y-m-d H:i:s'))->toBe($latest->format('Y-m-d H:i:s'));
});

it('falls back to created_at for a contact with no interactions', function () {
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create();
    DB::table('contacts')->where('id', $contact->id)->update(['last_activity_at' => now()->subYear()]);

    $this->artisan('contacts:recompute-activity')->assertSuccessful();

    expect($contact->fresh()->last_activity_at->equalTo($contact->created_at))->toBeTrue();
});
