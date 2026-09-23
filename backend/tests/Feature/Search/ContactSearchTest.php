<?php

use App\Models\Contact;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

// InnoDB FULLTEXT indexes do not reliably see a row inserted earlier in the
// same *uncommitted* transaction once the table already holds committed
// data (verified empirically against this project's MySQL container — a
// freshly-created, still-empty FULLTEXT table does show same-transaction
// visibility, but the real `contacts` table, seeded/exercised across many
// tests, does not). RefreshDatabase wraps every test in exactly that kind
// of transaction, so this file uses DatabaseTruncation instead: each test's
// writes are actually committed, then the tables are truncated afterwards.
// Slower than a transaction rollback, which is why it's isolated to just
// this one file rather than applied suite-wide. See DECISIONS.md #6.
uses(TestCase::class, DatabaseTruncation::class);

it('searches by name, email, phone, and company', function () {
    $lead = teamLead();
    $target = Contact::factory()->for(agent(), 'assignedAgent')->create([
        'name' => 'Zephyr Quibble', 'email' => 'zephyr@acme.test', 'phone' => '+19995550123', 'company' => 'Acme Rockets',
    ]);
    Contact::factory()->count(5)->for(agent(), 'assignedAgent')->create();

    // FULLTEXT boolean mode matches by *token prefix*, not substring (see
    // DECISIONS.md #6) — "+19995550123" tokenizes as one word starting with
    // "1", so a search has to match from the start of the token, not the
    // middle. Documented limitation, not a bug.
    foreach (['Zephyr', 'zephyr@acme.test', '19995550123', 'Rockets'] as $term) {
        $response = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?q='.urlencode($term));
        $ids = collect($response->json('data'))->pluck('id');

        // expect()->toContain() takes no failure-message argument (unlike
        // PHPUnit's assertContains) — asserting via `true` with an explicit
        // condition is what actually surfaces which term failed.
        expect($ids->contains($target->id))->toBeTrue("search term [{$term}] should have matched, got ids: ".$ids->implode(','));
    }
});

it('does not match on a substring that isn\'t a token prefix — a documented limitation', function () {
    $lead = teamLead();
    Contact::factory()->for(agent(), 'assignedAgent')->create(['phone' => '+19995550123']);

    $response = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?q=9995550123');

    expect($response->json('data'))->toBeEmpty();
});
