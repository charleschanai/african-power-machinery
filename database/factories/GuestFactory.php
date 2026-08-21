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
            'phone' => '2557' . fake()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
