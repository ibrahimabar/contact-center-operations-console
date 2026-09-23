<?php

use App\Models\Contact;
use App\Models\Interaction;
use Illuminate\Support\Str;

function interactionPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'phone',
        'note' => 'Discussed renewal options.',
    ], $overrides);
}

it('lets an agent add an interaction to their own contact', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $response = $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload(), [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'phone')
        ->assertJsonPath('data.created_by.id', $me->id)
        ->assertHeader('Idempotency-Replayed', 'false');
});

it('forbids an agent from adding an interaction to another agent\'s contact', function () {
    $me = agent();
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload(), [
            'Idempotency-Key' => (string) Str::uuid(),
        ])
        ->assertForbidden();
});

it('rejects a request with no Idempotency-Key header', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});

it('an identical retry replays the original interaction instead of creating a duplicate', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();
    $key = (string) Str::uuid();
    $payload = interactionPayload();

    $first = $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", $payload, ['Idempotency-Key' => $key]);
    $first->assertCreated()->assertHeader('Idempotency-Replayed', 'false');

    $retry = $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", $payload, ['Idempotency-Key' => $key]);
    $retry->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($retry->json('data.id'))->toBe($first->json('data.id'));
    expect(Interaction::where('contact_id', $contact->id)->count())->toBe(1);
});

it('rejects reusing the same idempotency key for a materially different request', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();
    $key = (string) Str::uuid();

    $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload(['note' => 'Original note']), ['Idempotency-Key' => $key])
        ->assertCreated();

    $response = $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload(['note' => 'A completely different note']), ['Idempotency-Key' => $key]);

    $response->assertStatus(422)->assertJson(['code' => 'idempotency_key_reused']);
    expect(Interaction::where('contact_id', $contact->id)->count())->toBe(1);
});

it('two different agents may coincidentally use the same idempotency key without colliding', function () {
    $agentA = agent();
    $contact = Contact::factory()->for($agentA, 'assignedAgent')->create();
    $lead = teamLead();
    $key = (string) Str::uuid(); // same key, different users — must not be treated as a collision

    // Seeded directly (rather than via a second actingAs()+HTTP round trip
    // in this same test — Laravel's test client only reliably reflects one
    // acting user per test when Sanctum's stateful session guard is in the
    // middleware stack) to isolate what this test actually verifies: that
    // the uniqueness constraint is scoped per-user, not global.
    Interaction::factory()->for($contact)->for($agentA, 'creator')->create([
        'idempotency_key' => $key,
        'request_hash' => hash('sha256', json_encode([
            'contact_id' => $contact->id, 'type' => 'phone', 'timestamp' => null, 'note' => 'A note from the team lead',
        ])),
    ]);

    $this->actingAs($lead, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload(['note' => 'A note from the team lead']), ['Idempotency-Key' => $key])
        ->assertCreated();

    expect(Interaction::where('contact_id', $contact->id)->count())->toBe(2);
});

it('advances the contact\'s last_activity_at when an interaction is added', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create(['last_activity_at' => now()->subMonth()]);

    $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload(), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated();

    expect($contact->fresh()->last_activity_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('does not move last_activity_at backwards for a backdated interaction (GREATEST semantics)', function () {
    $me = agent();
    $recentActivity = now()->subDay();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create(['last_activity_at' => $recentActivity]);

    $this->actingAs($me, 'web')
        ->postJson(
            "/api/v1/contacts/{$contact->id}/interactions",
            interactionPayload(['timestamp' => now()->subYear()->toIso8601String()]),
            ['Idempotency-Key' => (string) Str::uuid()]
        )
        ->assertCreated();

    // Compared at whole-second precision: the `last_activity_at` column is
    // a MySQL TIMESTAMP (no fractional seconds), while $recentActivity is an
    // in-memory Carbon instant with microseconds — an exact equalTo() would
    // false-fail on that precision gap alone, independent of GREATEST().
    expect($contact->fresh()->last_activity_at->format('Y-m-d H:i:s'))->toBe($recentActivity->format('Y-m-d H:i:s'));
});

it('rejects an interaction timestamped too far in the future', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->postJson(
            "/api/v1/contacts/{$contact->id}/interactions",
            interactionPayload(['timestamp' => now()->addHour()->toIso8601String()]),
            ['Idempotency-Key' => (string) Str::uuid()]
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('timestamp');
});

it('does not bump the contact\'s version or updated_at — only last_activity_at — so it cannot cause a spurious edit conflict', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create(['version' => 1]);
    $originalUpdatedAt = $contact->updated_at;

    $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/interactions", interactionPayload(), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated();

    $fresh = $contact->fresh();
    expect($fresh->version)->toBe(1);
    expect($fresh->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

it('lists interactions newest-first and paginates them', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $oldest = Interaction::factory()->for($contact)->for($me, 'creator')->create(['timestamp' => now()->subDays(3)]);
    $middle = Interaction::factory()->for($contact)->for($me, 'creator')->create(['timestamp' => now()->subDays(2)]);
    $newest = Interaction::factory()->for($contact)->for($me, 'creator')->create(['timestamp' => now()->subDay()]);

    $response = $this->actingAs($me, 'web')->getJson("/api/v1/contacts/{$contact->id}/interactions?per_page=50");

    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$newest->id, $middle->id, $oldest->id]);
});
