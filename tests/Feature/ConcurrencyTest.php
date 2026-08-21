<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Services\CardVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_card_never_allows_more_than_one_entry(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $card = Card::factory()->single()->create(['used_people' => 0]);
        $service = app(CardVerificationService::class);

        $allowed = 0;

        for ($i = 0; $i < 10; $i++) {
            $result = $service->confirmEntry($card->card_number, $user, []);
            if ($result->success) {
                $allowed++;
            }
        }

        $this->assertSame(1, $allowed);
        $this->assertSame(1, $card->fresh()->used_people);
        $this->assertTrue($card->fresh()->used_people <= $card->fresh()->allowed_people);
    }

    public function test_double_card_never_allows_more_than_two_entries(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $card = Card::factory()->double()->create(['used_people' => 0]);
        $service = app(CardVerificationService::class);

        $allowed = 0;

        for ($i = 0; $i < 10; $i++) {
            $result = $service->confirmEntry($card->card_number, $user, []);
            if ($result->success) {
                $allowed++;
            }
        }

        $this->assertSame(2, $allowed);
        $this->assertSame(2, $card->fresh()->used_people);
        $this->assertTrue($card->fresh()->used_people <= $card->fresh()->allowed_people);
    }

    public function test_row_lock_blocks_second_concurrent_scan(): void
    {
        $writer = DB::connection('second')->getPdo();
        $writer->beginTransaction();
        $writer->exec("INSERT INTO guests (full_name, phone, created_at, updated_at) VALUES ('Mgeni wa Mtihani', '255700000001', NOW(), NOW())");
        $guestId = $writer->lastInsertId();
        $writer->exec("INSERT INTO cards (guest_id, card_number, qr_token, card_type, allowed_people, used_people, status, issued_at, created_at, updated_at) VALUES ($guestId, 'EC-LOCK01', 'lock-token', 'single', 1, 0, 'active', NOW(), NOW(), NOW())");
        $cardId = $writer->lastInsertId();
        $writer->commit();

        $pdoA = DB::connection('second')->getPdo();
        $pdoA->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdoA->beginTransaction();
        $pdoA->query("SELECT * FROM cards WHERE id = {$cardId} FOR UPDATE");

        $pdoB = DB::connection('third')->getPdo();
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdoB->beginTransaction();

        $blocked = false;
        try {
            $pdoB->query("SELECT * FROM cards WHERE id = {$cardId} FOR UPDATE");
            $pdoB->commit();
        } catch (\PDOException $e) {
            $blocked = true;
            $pdoB->rollBack();
        }

        $pdoA->rollBack();

        $writer->exec("DELETE FROM cards WHERE id = {$cardId}");
        $writer->exec("DELETE FROM guests WHERE id = {$guestId}");

        $this->assertTrue($blocked, 'Second concurrent scan must block while the row is locked.');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.second' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'ecard_system_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'database.connections.third' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'ecard_system_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ]);
    }
}
