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
            // Sequential, not random. A random six-digit number can collide
            // with the one CardController::nextCardNumber() is about to
            // issue, and can also overflow to a seven-digit number that
            // breaks the /^EC-\d{6}$/ assertion in CardManagementTest.
            'card_number' => 'EC-'.str_pad((string) self::nextNumber(), 6, '0', STR_PAD_LEFT),
            'qr_token' => Str::random(32),
            'card_type' => $type,
            'allowed_people' => $type->allowedPeople(),
            'used_people' => 0,
            'status' => CardStatus::Active,
            'issued_at' => now(),
            'expires_at' => null,
        ];
    }

    /**
     * Monotonic per-process counter starting above any number the seeder or
     * a previous test run is likely to have used.
     */
    private static int $counter = 900000;

    private static function nextNumber(): int
    {
        return ++self::$counter;
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

    /**
     * A card that has already admitted $count people.
     *
     * The status is derived from the *resolved* attributes (the second
     * argument the factory passes in), not from $this. Factory has __call but
     * no __get, so the previous version read null, compared it, and always
     * produced CardStatus::Used no matter what was asked for.
     */
    public function used(int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'used_people' => $count,
            'status' => $count >= ($attributes['allowed_people'] ?? 1)
                ? CardStatus::Used
                : CardStatus::Active,
        ]);
    }

    /** A card whose validity has run out. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => CardStatus::Active,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => CardStatus::Cancelled,
        ]);
    }
}
