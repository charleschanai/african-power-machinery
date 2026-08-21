<?php

namespace Database\Seeders;

use App\Models\Card;
use App\Models\Guest;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(UsersSeeder::class);

        $guests = Guest::factory()->count(15)->create();

        foreach ($guests as $guest) {
            $count = fake()->numberBetween(1, 3);

            for ($i = 0; $i < $count; $i++) {
                Card::factory()->create(['guest_id' => $guest->id]);
            }
        }
    }
}
