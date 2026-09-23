<?php

use Illuminate\Support\Facades\Hash;

it('logs in with correct credentials and starts a session', function () {
    $user = agent(['email' => 'agent@example.com', 'password' => Hash::make('correct-password')]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'agent@example.com',
        'password' => 'correct-password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'agent@example.com');

    $this->assertAuthenticatedAs($user, 'web');
});

it('rejects an incorrect password with a generic message', function () {
    agent(['email' => 'agent@example.com', 'password' => Hash::make('correct-password')]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'agent@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertGuest('web');
});

it('rejects a deactivated user the same way as a wrong password', function () {
    // Same generic error as a wrong password — deliberately doesn't leak
    // account status. See DECISIONS.md "Domain rules".
    agent(['email' => 'inactive@example.com', 'password' => Hash::make('correct-password'), 'is_active' => false]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'correct-password',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertGuest('web');
});

it('rejects unauthenticated access to a protected route with a 401', function () {
    $response = $this->getJson('/api/v1/contacts');

    $response->assertUnauthorized()
        ->assertJson(['code' => 'unauthenticated']);
});

it('throttles repeated failed login attempts', function () {
    agent(['email' => 'agent@example.com', 'password' => Hash::make('correct-password')]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'agent@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429)->assertJson(['code' => 'too_many_requests']);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

it('logs out and invalidates the session', function () {
    $user = agent();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    $this->assertGuest('web');
});
