<?php

namespace Database\Factories;

use App\Enums\CardStatus;
use App\Enums\CardType;
use App\Models\Card;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    protected $model = Card::class;

    public function definition(): array
    {
        $type = fake()->randomElement([CardType::Single, CardType::Double]);

        return [
            'guest_id' => Guest::factory(),
            'card_number' => 'EC-' . str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'qr_token' => Str::random(32),
            'card_type' => $type,
            'allowed_people' => $type->allowedPeople(),
            'used_people' => 0,
            'status' => CardStatus::Active,
            'issued_at' => now(),
            'expires_at' => null,
        ];
    }

    public function single(): static
    {
        return $this->state(fn () => [
            'card_type' => CardType::Single,
            'allowed_people' => CardType::Single->allowedPeople(),
        ]);
    }

    public function double(): static
    {
        return $this->state(fn () => [
            'card_type' => CardType::Double,
            'allowed_people' => CardType::Double->allowedPeople(),
        ]);
    }

    public function used(int $count): static
    {
        return $this->state(fn () => [
            'used_people' => $count,
            'status' => $count >= $this->allowedPeople ? CardStatus::Used : CardStatus::Active,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => CardStatus::Cancelled,
        ]);
    }
}
