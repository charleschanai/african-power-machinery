<?php

namespace Database\Factories;

use App\Enums\EntryResult;
use App\Models\Card;
use App\Models\EntryLog;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntryLog>
 */
class EntryLogFactory extends Factory
{
    protected $model = EntryLog::class;

    public function definition(): array
    {
        $result = fake()->randomElement(EntryResult::cases());

        return [
            'card_id' => Card::factory(),
            'guest_id' => Guest::factory(),
            'processed_by' => User::factory(),
            'entry_number' => 1,
            'result' => $result,
            'scanned_at' => now(),
            'gate_name' => fake()->optional()->text(30),
            'device_name' => fake()->optional()->text(30),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'notes' => null,
        ];
    }

    public function allowed(): static
    {
        return $this->state(fn () => ['result' => EntryResult::Allowed]);
    }

    public function denied(): static
    {
        return $this->state(fn () => ['result' => EntryResult::Denied]);
    }
}
