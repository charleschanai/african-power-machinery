<?php

namespace Database\Factories;

use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            // unique() is required: guests.phone carries a UNIQUE constraint
            // and EntryLogFactory creates two guests per row, so a plain
            // numerify() collides sooner or later.
            'phone' => '2557'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /** A guest who can be reached on WhatsApp or SMS. */
    public function contactable(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_e164' => \App\Services\PhoneHelper::toE164($attributes['phone']),
        ]);
    }
}
