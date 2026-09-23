<?php

namespace Database\Factories;

use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $createdAt = fake()->dateTimeBetween('-1 year', '-1 week');

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+1##########'),
            'company' => fake()->boolean(85) ? fake()->company() : null,
            'assigned_agent_id' => User::factory(),
            'status' => fake()->randomElement(ContactStatus::cases()),
            'version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'last_activity_at' => $createdAt,
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_agent_id' => null,
        ]);
    }
}
