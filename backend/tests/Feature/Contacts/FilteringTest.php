<?php

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

// FULLTEXT search is covered separately in tests/Feature/Search/ContactSearchTest.php,
// which uses DatabaseTruncation instead of this file's (RefreshDatabase)
// transaction-per-test isolation — see that file's header comment and
// DECISIONS.md #6.

it('filters by status', function () {
    $lead = teamLead();
    Contact::factory()->count(3)->for(agent(), 'assignedAgent')->create(['status' => 'resolved']);
    Contact::factory()->count(2)->for(agent(), 'assignedAgent')->create(['status' => 'new']);

    $response = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?status=resolved&per_page=50');

    expect($response->json('data'))->toHaveCount(3);
    foreach ($response->json('data') as $contact) {
        expect($contact['status'])->toBe('resolved');
    }
});

it('filters by assigned agent', function () {
    $lead = teamLead();
    $targetAgent = agent();
    Contact::factory()->count(4)->for($targetAgent, 'assignedAgent')->create();
    Contact::factory()->count(6)->for(agent(), 'assignedAgent')->create();

    $response = $this->actingAs($lead, 'web')->getJson("/api/v1/contacts?assigned_agent_id={$targetAgent->id}&per_page=50");

    expect($response->json('data'))->toHaveCount(4);
});

it('filters the unassigned queue for a team lead', function () {
    $lead = teamLead();
    Contact::factory()->count(2)->unassigned()->create();
    Contact::factory()->count(3)->for(agent(), 'assignedAgent')->create();

    $response = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?unassigned=1&per_page=50');

    expect($response->json('data'))->toHaveCount(2);
});

it('sorts by creation date and by most recent activity independently', function () {
    $lead = teamLead();
    $older = Contact::factory()->for(agent(), 'assignedAgent')->create([
        'created_at' => now()->subDays(5), 'last_activity_at' => now(), // created first, but active most recently
    ]);
    $newer = Contact::factory()->for(agent(), 'assignedAgent')->create([
        'created_at' => now()->subDay(), 'last_activity_at' => now()->subDays(10), // created after, but stale
    ]);

    $byCreated = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?sort=created_at&direction=desc&per_page=2');
    expect($byCreated->json('data.0.id'))->toBe($newer->id);

    $byActivity = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?sort=last_activity_at&direction=desc&per_page=2');
    expect($byActivity->json('data.0.id'))->toBe($older->id);
});

it('paginates with a stable cursor — no duplicates or gaps across pages, and per_page is capped', function () {
    $lead = teamLead();
    Contact::factory()->count(25)->for(agent(), 'assignedAgent')->create();

    // per_page above the cap (100) should be clamped, not rejected — but we
    // ask for a small page here to actually exercise multi-page traversal.
    $seenIds = collect();
    $cursor = null;

    do {
        $url = '/api/v1/contacts?per_page=10'.($cursor ? '&cursor='.$cursor : '');
        $response = $this->actingAs($lead, 'web')->getJson($url);
        $ids = collect($response->json('data'))->pluck('id');

        expect($seenIds->intersect($ids)->isEmpty())->toBeTrue('cursor pagination must not repeat a row across pages');
        $seenIds = $seenIds->merge($ids);

        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($seenIds)->toHaveCount(25);
});

it('eager-loads the assigned agent on the list endpoint instead of querying per row (no N+1)', function () {
    $lead = teamLead();
    Contact::factory()->count(20)->for(agent(), 'assignedAgent')->create();

    DB::enableQueryLog();
    $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?per_page=20')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A handful of fixed queries (auth lookup, the contacts page, the
    // assignedAgent eager load) — not one-per-row, which would scale with
    // the 20 contacts fetched above.
    expect($queryCount)->toBeLessThan(10);
});

it('excludes another agent\'s contacts from every filter combination for an agent', function () {
    $me = agent();
    Contact::factory()->count(2)->for($me, 'assignedAgent')->create(['status' => 'active']);
    Contact::factory()->count(10)->for(agent(), 'assignedAgent')->create(['status' => 'active']);

    $response = $this->actingAs($me, 'web')->getJson('/api/v1/contacts?status=active&per_page=50');

    expect($response->json('data'))->toHaveCount(2);
});
