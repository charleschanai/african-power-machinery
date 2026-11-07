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

    /**
     * Idempotency, not concurrency.
     *
     * This is a sequential loop on one connection. It proves that calling
     * confirmEntry() ten times with the same card admits the right number of
     * people — which is a real property worth locking down — but it says
     * nothing about two officers scanning at the same instant. That is what
     * test_row_lock_blocks_second_concurrent_scan() covers.
     */
    public function test_repeated_entry_on_a_single_card_never_exceeds_one(): void
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
    }

    /**
     * Idempotency, not concurrency. See the note above.
     */
    public function test_repeated_entry_on_a_double_card_never_exceeds_two(): void
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
    }

    /**
     * The genuine concurrency test: two independent database connections.
     *
     * These rows are written outside RefreshDatabase's transaction, because
     * that is the whole point — a second connection cannot see uncommitted
     * data. That also means they survive a failure, so the cleanup is in a
     * finally block. Without it, one interrupted run leaves a guest and a card
     * in the shared test database, and every test after this one fails on a
     * duplicate key.
     */
    public function test_row_lock_blocks_second_concurrent_scan(): void
    {
        // Unique per run. A fixed phone number collided with the orphan left
        // by any previous interrupted run.
        $suffix = str_pad((string) random_int(0, 999999999), 10, '0', STR_PAD_LEFT);
        $phone = '2557'.$suffix;
        $cardNumber = 'EC-LOCK'.substr($suffix, 0, 6);

        $writer = DB::connection('second')->getPdo();
        $guestId = null;
        $cardId = null;

        $pdoA = null;
        $pdoB = null;

        try {
            $writer->beginTransaction();
            $writer->exec("INSERT INTO guests (full_name, phone, created_at, updated_at) VALUES ('Mgeni wa Mtihani', {$this->q($phone)}, NOW(), NOW())");
            $guestId = (int) $writer->lastInsertId();
            $writer->exec("INSERT INTO cards (guest_id, card_number, qr_token, card_type, allowed_people, used_people, status, issued_at, created_at, updated_at) VALUES ({$guestId}, {$this->q($cardNumber)}, {$this->q('lock-'.$suffix)}, 'single', 1, 0, 'active', NOW(), NOW(), NOW())");
            $cardId = (int) $writer->lastInsertId();
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
            } catch (\PDOException) {
                $blocked = true;
                $pdoB->rollBack();
            }

            $this->assertTrue($blocked, 'Second concurrent scan must block while the row is locked.');
        } finally {
            // Roll back anything still open, then remove the rows. A
            // transaction left open here holds a row lock and stalls every
            // test that follows.
            foreach ([$pdoA, $pdoB] as $pdo) {
                if ($pdo !== null && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }

            if ($cardId !== null) {
                $writer->exec("DELETE FROM cards WHERE id = {$cardId}");
            }

            if ($guestId !== null) {
                $writer->exec("DELETE FROM guests WHERE id = {$guestId}");
            }
        }
    }

    private function q(string $value): string
    {
        return DB::connection('second')->getPdo()->quote($value);
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
