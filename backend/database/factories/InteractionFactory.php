<?php

namespace Database\Factories;

use App\Enums\InteractionType;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Interaction>
 */
class InteractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'type' => fake()->randomElement(InteractionType::cases()),
            'timestamp' => fake()->dateTimeBetween('-1 year', 'now'),
            'note' => fake()->sentence(fake()->numberBetween(6, 20)),
            'created_by' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => fake()->sha256(),
        ];
    }
}
