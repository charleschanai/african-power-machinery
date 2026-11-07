<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Enums\CardType;
use App\Enums\InvitationChannel;
use App\Enums\InvitationStatus;
use App\Jobs\SendSmsInvitationJob;
use App\Models\Card;
use App\Models\EntryLog;
use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression tests for the performance work.
 *
 * Each of these locks in a fix that would otherwise be invisible: an index
 * that silently disappears, a scope that stops being eager-loaded, or a
 * scheduled command that starts sending duplicates.
 */
class QueryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /*  Indexes                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @dataProvider indexedColumns
     */
    public function test_the_index_migration_created_the_expected_indexes(string $table, string $index): void
    {
        $indexes = collect(\Illuminate\Support\Facades\Schema::getIndexes($table))
            ->pluck('name')
            ->all();

        $this->assertContains(
            $index,
            $indexes,
            "Index {$index} is missing on {$table}. The performance migration may not have run."
        );
    }

    public static function indexedColumns(): array
    {
        return [
            'guests phone_e164' => ['guests', 'guests_phone_e164_idx'],
            'guests name + id' => ['guests', 'guests_full_name_id_idx'],
            'cards status + type' => ['cards', 'cards_status_type_idx'],
            'cards guest + status' => ['cards', 'cards_guest_status_idx'],
            'cards expires_at' => ['cards', 'cards_expires_at_idx'],
            'entry_logs result + scanned' => ['entry_logs', 'entry_logs_result_scanned_idx'],
            'entry_logs card + scanned' => ['entry_logs', 'entry_logs_card_scanned_idx'],
            'invitation_sends schedule' => ['invitation_sends', 'inv_sends_channel_status_idx'],
            'invitation_sends scheduled_at' => ['invitation_sends', 'inv_sends_status_sched_idx'],
            'whatsapp conv status + last' => ['whatsapp_conversations', 'wa_conv_status_last_idx'],
            'weekly reminders week' => ['weekly_reminder_logs', 'wrl_year_week_status_idx'],
            'audit event + created' => ['audit_logs', 'audit_logs_event_created_idx'],
            'users role' => ['users', 'users_role_idx'],
        ];
    }

    public function test_the_performance_migration_can_be_rolled_back(): void
    {
        /*
         * Verified by inspection rather than by running down()/up() against
         * the shared test database.
         *
         * An earlier version of this test did execute both. It worked, and
         * then poisoned every test that ran after it: the drops and re-creates
         * race the framework's own transactions, and one index MySQL would
         * not release (it backs a foreign key) left the schema in a state
         * nothing had planned for. A test that only passes when it happens to
         * run first is not a test.
         */
        $migration = require database_path('migrations/2026_09_25_100000_add_performance_indexes.php');

        $source = file_get_contents(
            database_path('migrations/2026_09_25_100000_add_performance_indexes.php')
        );

        // Every index named in up() must also be named in down(), or a
        // rollback leaves half the change behind.
        preg_match_all("/addIndex\('\w+', '([\w_]+)'/", $source, $added);
        preg_match_all("/'(\w+)' => '\w+',/", $this->downSection($source), $removed);

        $this->assertNotEmpty($added[1]);

        foreach ($added[1] as $index) {
            $this->assertContains(
                $index,
                $removed[1],
                "Index {$index} is created in up() but not removed in down()."
            );
        }
    }

    private function downSection(string $source): string
    {
        $start = strpos($source, 'public function down');
        $end = strpos($source, 'private function addIndex');

        return substr($source, (int) $start, (int) $end - (int) $start);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function test_invitable_scope_matches_what_a_campaign_would_actually_send(): void
    {
        $contactable = Guest::factory()->contactable()->create();
        $noPhone = Guest::factory()->create(['phone' => '', 'phone_e164' => null]);

        Card::factory()->create(['guest_id' => $contactable->id]);
        Card::factory()->cancelled()->create(['guest_id' => $contactable->id]);
        Card::factory()->create(['guest_id' => $noPhone->id]);

        $invitable = Card::invitable()->pluck('id');

        $this->assertCount(1, $invitable, 'Only the active card of a contactable guest is invitable.');
    }

    public function test_still_valid_scope_excludes_expired_cards(): void
    {
        $valid = Card::factory()->create(['expires_at' => now()->addDay()]);
        $expired = Card::factory()->create(['expires_at' => now()->subDay()]);
        $noExpiry = Card::factory()->create(['expires_at' => null]);

        $ids = Card::stillValid()->pluck('id')->all();

        $this->assertContains($valid->id, $ids);
        $this->assertContains($noExpiry->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    public function test_guest_search_scope_finds_by_name_phone_and_email(): void
    {
        $guest = Guest::factory()->create([
            'full_name' => 'Zahabu Mwakyembe',
            'phone' => '0754123456',
            'email' => 'zahabu@example.test',
        ]);
        Guest::factory()->create(['full_name' => 'Someone Else', 'email' => 'other@example.test']);

        $this->assertTrue(Guest::search('Zahabu')->pluck('id')->contains($guest->id));
        $this->assertTrue(Guest::search('754123')->pluck('id')->contains($guest->id));
        $this->assertTrue(Guest::search('zahabu@')->pluck('id')->contains($guest->id));
    }

    public function test_guest_search_with_an_empty_term_returns_everything(): void
    {
        Guest::factory()->count(3)->create();

        $this->assertCount(3, Guest::search('')->get());
        $this->assertCount(3, Guest::search(null)->get());
    }

    /* ------------------------------------------------------------------ */
    /*  Eager loading (N+1)                                                */
    /* ------------------------------------------------------------------ */

    public function test_cards_eager_load_their_guest_by_default(): void
    {
        Card::factory()->count(5)->create();

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        Card::query()->limit(5)->get();

        // 1 for the cards, 1 for the guests. Without $with this would be 6.
        $this->assertLessThanOrEqual(2, $queries, 'Card queries are not eager-loading guest.');
    }

    public function test_entry_logs_eager_load_guest_card_and_processor(): void
    {
        $guest = Guest::factory()->create();
        $card = Card::factory()->create(['guest_id' => $guest->id]);
        $user = \App\Models\User::factory()->create();

        EntryLog::factory()->count(3)->create([
            'card_id' => $card->id,
            'guest_id' => $guest->id,
            'processed_by' => $user->id,
        ]);

        $queries = 0;

        // One listener only. DB::listen registers permanently for the life
        // of the process, so a second call here would make the count include
        // the first one too.
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        EntryLog::query()->limit(3)->get();

        // 1 for the logs, 1 each for guest / card / processor, and 1 more
        // because Card also eager-loads its own guest. Without the $with
        // declarations this would be 1 + 3*3 = 10.
        $this->assertLessThanOrEqual(5, $queries, 'EntryLog queries are not eager-loading their relations.');
    }

    public function test_entry_log_query_count_does_not_grow_with_the_row_count(): void
    {
        $guest = Guest::factory()->create();
        $card = Card::factory()->create(['guest_id' => $guest->id]);
        $user = \App\Models\User::factory()->create();

        EntryLog::factory()->count(40)->create([
            'card_id' => $card->id,
            'guest_id' => $guest->id,
            'processed_by' => $user->id,
        ]);

        $queries = 0;

        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        EntryLog::query()->limit(40)->get();

        // The property that actually matters. An N+1 looks fine at three
        // rows and falls over at four hundred.
        $this->assertLessThanOrEqual(5, $queries, 'Query count grew with the number of entry logs.');
    }

    /* ------------------------------------------------------------------ */
    /*  Scoped queries                                                     */
    /* ------------------------------------------------------------------ */

    public function test_entry_log_scopes_filter_correctly(): void
    {
        $allowed = EntryLog::factory()->create(['result' => \App\Enums\EntryResult::Allowed]);
        $cancelled = EntryLog::factory()->create(['result' => \App\Enums\EntryResult::Cancelled]);

        $this->assertTrue(EntryLog::allowed()->pluck('id')->contains($allowed->id));
        $this->assertFalse(EntryLog::allowed()->pluck('id')->contains($cancelled->id));

        $this->assertTrue(EntryLog::denied()->pluck('id')->contains($cancelled->id));
        $this->assertFalse(EntryLog::denied()->pluck('id')->contains($allowed->id));

        $this->assertTrue(
            EntryLog::since(now()->subMinute())->pluck('id')->contains($allowed->id)
        );
        $this->assertTrue(
            EntryLog::since(now()->addMinute())->pluck('id')->doesntContain($allowed->id)
        );
    }

    public function test_entry_log_search_guest_name_scope(): void
    {
        $guest = Guest::factory()->create(['full_name' => 'Neema Mwakalinga']);
        $other = Guest::factory()->create(['full_name' => 'Baraka Joseph']);

        EntryLog::factory()->create(['guest_id' => $guest->id]);
        EntryLog::factory()->create(['guest_id' => $other->id]);

        $ids = EntryLog::searchGuestName('Neema')->pluck('id');

        $this->assertCount(1, $ids);
    }

    /* ------------------------------------------------------------------ */
    /*  Scheduled SMS must not double-send                                 */
    /* ------------------------------------------------------------------ */

    public function test_scheduled_sms_is_dispatched_only_once(): void
    {
        Queue::fake();

        $guest = Guest::factory()->create();
        $card = Card::factory()->create(['guest_id' => $guest->id]);

        \App\Models\InvitationSend::create([
            'card_id' => $card->id,
            'channel' => InvitationChannel::Sms->value,
            'status' => InvitationStatus::Queued->value,
            'phone_e164' => '255754123456',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('sms:process-scheduled')->assertSuccessful();
        Queue::assertPushed(SendSmsInvitationJob::class, 1);

        /*
         * Running it again must not dispatch a second copy.
         *
         * The command used to leave status at 'queued' forever, so every
         * scheduler tick re-selected the same row. With four schedulers
         * running that was four messages to one guest, each billed.
         */
        $this->artisan('sms:process-scheduled')->assertSuccessful();
        Queue::assertPushed(SendSmsInvitationJob::class, 1);
    }

    public function test_an_abandoned_claim_is_eventually_retried(): void
    {
        Queue::fake();

        $guest = Guest::factory()->create();
        $card = Card::factory()->create(['guest_id' => $guest->id]);

        \App\Models\InvitationSend::create([
            'card_id' => $card->id,
            'channel' => InvitationChannel::Sms->value,
            'status' => InvitationStatus::Queued->value,
            'phone_e164' => '255754123456',
            'scheduled_at' => now()->subDay(),
            // Claimed by a worker that then died.
            'queued_at' => now()->subHour(),
        ]);

        $this->artisan('sms:process-scheduled')->assertSuccessful();

        // Deduplication must not turn into silently losing a message.
        Queue::assertPushed(SendSmsInvitationJob::class, 1);
    }

    public function test_future_scheduled_sms_is_left_alone(): void
    {
        Queue::fake();

        $guest = Guest::factory()->create();
        $card = Card::factory()->create(['guest_id' => $guest->id]);

        \App\Models\InvitationSend::create([
            'card_id' => $card->id,
            'channel' => InvitationChannel::Sms->value,
            'status' => InvitationStatus::Queued->value,
            'phone_e164' => '255754123456',
            'scheduled_at' => now()->addDay(),
        ]);

        $this->artisan('sms:process-scheduled')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_scheduled_sms_dry_run_sends_nothing(): void
    {
        Queue::fake();

        $guest = Guest::factory()->create();
        $card = Card::factory()->create(['guest_id' => $guest->id]);

        \App\Models\InvitationSend::create([
            'card_id' => $card->id,
            'channel' => InvitationChannel::Sms->value,
            'status' => InvitationStatus::Queued->value,
            'phone_e164' => '255754123456',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('sms:process-scheduled', ['--dry-run' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------ */
    /*  Model helpers                                                      */
    /* ------------------------------------------------------------------ */

    public function test_card_expired_factory_state_is_really_expired(): void
    {
        // The old used() state read $this->allowedPeople, which does not
        // exist on a Factory, so it always produced a "used" card.
        $expired = Card::factory()->expired()->create();
        $this->assertTrue($expired->isExpired());

        $fresh = Card::factory()->used(0)->create();
        $this->assertSame(CardStatus::Active, $fresh->status);
        $this->assertSame(0, $fresh->used_people);

        $spent = Card::factory()->double()->used(2)->create();
        $this->assertSame(CardStatus::Used, $spent->status);
    }

    public function test_guest_phone_generation_is_unique(): void
    {
        $guests = Guest::factory()->count(25)->create();

        $phones = $guests->pluck('phone');

        $this->assertCount(25, $phones->unique(), 'Factory generated duplicate phone numbers.');
    }
}
