<?php

use App\Models\Contact;

it('validates required fields when creating a contact', function () {
    $response = $this->actingAs(agent(), 'web')->postJson('/api/v1/contacts', []);

    $response->assertUnprocessable()
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors(['name', 'email', 'phone']);
});

it('creates a contact with the defaults the API promises', function () {
    $me = agent();

    $response = $this->actingAs($me, 'web')->postJson('/api/v1/contacts', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+15551234567',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.archived_at', null);

    $this->assertDatabaseHas('contacts', [
        'email' => 'jane@example.com',
        'assigned_agent_id' => $me->id,
    ]);
});

it('rejects an update that omits the version token', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->patchJson("/api/v1/contacts/{$contact->id}", ['status' => 'active'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('version');
});

it('rejects assigning through the general update endpoint', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->patchJson("/api/v1/contacts/{$contact->id}", [
            'assigned_agent_id' => agent()->id,
            'version' => $contact->version,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assigned_agent_id');
});

it('returns 409 with the current server copy when the version is stale — the required concurrency edge case', function () {
    $lead = teamLead();
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create(['status' => 'new', 'version' => 1]);

    // Simulates a second agent's PATCH landing first.
    $this->actingAs($lead, 'web')
        ->patchJson("/api/v1/contacts/{$contact->id}", ['status' => 'active', 'version' => 1])
        ->assertOk();

    // The original agent's tab, still holding version 1, tries to save.
    $response = $this->actingAs($lead, 'web')
        ->patchJson("/api/v1/contacts/{$contact->id}", ['status' => 'pending', 'version' => 1]);

    $response->assertStatus(409)
        ->assertJson(['code' => 'version_conflict'])
        ->assertJsonPath('current.status', 'active')
        ->assertJsonPath('current.version', 2);

    // The losing request must not have applied at all.
    expect($contact->fresh()->status->value)->toBe('active');
});

it('two concurrent version-1 updates: exactly one succeeds, the other gets 409', function () {
    $lead = teamLead();
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create(['version' => 1]);

    $first = $this->actingAs($lead, 'web')->patchJson("/api/v1/contacts/{$contact->id}", ['status' => 'active', 'version' => 1]);
    $second = $this->actingAs($lead, 'web')->patchJson("/api/v1/contacts/{$contact->id}", ['status' => 'pending', 'version' => 1]);

    $statuses = collect([$first->status(), $second->status()])->sort()->values();
    expect($statuses->all())->toBe([200, 409]);
    expect($contact->fresh()->version)->toBe(2);
});

it('archiving excludes a contact from the default list, and restoring brings it back', function () {
    $lead = teamLead();
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create();

    $this->actingAs($lead, 'web')->deleteJson("/api/v1/contacts/{$contact->id}")->assertNoContent();

    $listAfterArchive = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?per_page=100');
    expect(collect($listAfterArchive->json('data'))->pluck('id'))->not->toContain($contact->id);

    $onlyArchived = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?archived=only&per_page=100');
    expect(collect($onlyArchived->json('data'))->pluck('id'))->toContain($contact->id);

    $this->actingAs($lead, 'web')->postJson("/api/v1/contacts/{$contact->id}/restore")->assertOk();

    $listAfterRestore = $this->actingAs($lead, 'web')->getJson('/api/v1/contacts?per_page=100');
    expect(collect($listAfterRestore->json('data'))->pluck('id'))->toContain($contact->id);
});
