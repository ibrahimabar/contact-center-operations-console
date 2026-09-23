<?php

use App\Models\Contact;

it('lets an agent view a contact assigned to them', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->getJson("/api/v1/contacts/{$contact->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $contact->id);
});

it('forbids an agent from viewing another agent\'s contact', function () {
    $me = agent();
    $someoneElsesContact = Contact::factory()->for(agent(), 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->getJson("/api/v1/contacts/{$someoneElsesContact->id}")
        ->assertForbidden()
        ->assertJson(['code' => 'forbidden']);
});

it('scopes the contacts list to only the agent\'s own contacts', function () {
    $me = agent();
    Contact::factory()->count(3)->for($me, 'assignedAgent')->create();
    Contact::factory()->count(5)->for(agent(), 'assignedAgent')->create();

    $response = $this->actingAs($me, 'web')->getJson('/api/v1/contacts?per_page=50');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    foreach ($response->json('data') as $contact) {
        expect($contact['assigned_agent_id'])->toBe($me->id);
    }
});

it('lets a team lead view any contact', function () {
    $lead = teamLead();
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create();

    $this->actingAs($lead, 'web')
        ->getJson("/api/v1/contacts/{$contact->id}")
        ->assertOk();
});

it('forbids an agent from updating another agent\'s contact', function () {
    $me = agent();
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create();

    $this->actingAs($me, 'web')
        ->patchJson("/api/v1/contacts/{$contact->id}", ['status' => 'active', 'version' => $contact->version])
        ->assertForbidden();
});

it('lets an agent update their own contact', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create(['status' => 'new']);

    $this->actingAs($me, 'web')
        ->patchJson("/api/v1/contacts/{$contact->id}", ['status' => 'active', 'version' => $contact->version])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('forbids an agent from archiving, restoring, or assigning a contact — even their own', function () {
    $me = agent();
    $contact = Contact::factory()->for($me, 'assignedAgent')->create();

    $this->actingAs($me, 'web')->deleteJson("/api/v1/contacts/{$contact->id}")->assertForbidden();
    $this->actingAs($me, 'web')->postJson("/api/v1/contacts/{$contact->id}/restore")->assertForbidden();
    $this->actingAs($me, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/assign", ['assigned_agent_id' => null, 'version' => $contact->version])
        ->assertForbidden();
});

it('lets a team lead archive, restore, and assign any contact', function () {
    $lead = teamLead();
    $newAgent = agent();
    $contact = Contact::factory()->for(agent(), 'assignedAgent')->create();

    $this->actingAs($lead, 'web')->deleteJson("/api/v1/contacts/{$contact->id}")->assertNoContent();
    expect($contact->fresh()->trashed())->toBeTrue();

    $this->actingAs($lead, 'web')->postJson("/api/v1/contacts/{$contact->id}/restore")->assertOk();
    expect($contact->fresh()->trashed())->toBeFalse();

    $this->actingAs($lead, 'web')
        ->postJson("/api/v1/contacts/{$contact->id}/assign", ['assigned_agent_id' => $newAgent->id, 'version' => $contact->fresh()->version])
        ->assertOk()
        ->assertJsonPath('data.assigned_agent_id', $newAgent->id);
});

it('forces a new contact created by an agent to be assigned to themselves, ignoring any submitted assignee', function () {
    $me = agent();
    $someoneElse = agent();

    $response = $this->actingAs($me, 'web')->postJson('/api/v1/contacts', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+15551234567',
        'assigned_agent_id' => $someoneElse->id,
    ]);

    $response->assertCreated()->assertJsonPath('data.assigned_agent_id', $me->id);
});

it('lets a team lead assign a new contact to a chosen agent, or leave it unassigned', function () {
    $lead = teamLead();
    $target = agent();

    $assigned = $this->actingAs($lead, 'web')->postJson('/api/v1/contacts', [
        'name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '+15551234567',
        'assigned_agent_id' => $target->id,
    ]);
    $assigned->assertCreated()->assertJsonPath('data.assigned_agent_id', $target->id);

    $unassigned = $this->actingAs($lead, 'web')->postJson('/api/v1/contacts', [
        'name' => 'John Roe', 'email' => 'john@example.com', 'phone' => '+15551234568',
    ]);
    $unassigned->assertCreated()->assertJsonPath('data.assigned_agent_id', null);
});
